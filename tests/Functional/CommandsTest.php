<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Tests\Functional;

use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data\Numeric;
use Tsf\GatekeeperBundle\Service\ResultStore;
use Tsf\GatekeeperBundle\Tests\Support\Fixture\ClassFixtures;
use Tsf\GatekeeperBundle\Tests\Support\FunctionalTestCase;

final class CommandsTest extends FunctionalTestCase
{
    public function testValidateAcceptsTheTestConfiguration(): void
    {
        $tester = $this->runCommand('tsf:gatekeeper:validate');

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('OK', $tester->getDisplay());
        self::assertStringContainsString('GkProduct (enabled, gate block, profiles: default, print, score_field completeness)', $tester->getDisplay());
        self::assertStringContainsString('GkCategory (enabled, gate warn, profiles: default)', $tester->getDisplay());
    }

    public function testRecalculateRebuildsTheResultTable(): void
    {
        $complete = $this->product($this->completeValues());
        $complete->save();
        $incomplete = $this->product(['sku' => 'SKU-9'], false);
        $incomplete->save();
        $this->connection()->executeStatement('DELETE FROM `' . ResultStore::TABLE . '`');

        $dryRun = $this->runCommand('tsf:gatekeeper:recalculate', ['--dry-run' => true]);
        self::assertSame(0, $dryRun->getStatusCode(), $dryRun->getDisplay());
        self::assertStringContainsString('GkProduct: 2 object(s) evaluated, 1 failing (dry run)', $dryRun->getDisplay());
        self::assertSame(0, $this->countRows());

        $tester = $this->runCommand('tsf:gatekeeper:recalculate', ['--class' => ClassFixtures::PRODUCT]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('GkProduct: 2 object(s) evaluated, 1 failing', $tester->getDisplay());
        self::assertSame(6, $this->countRows());
        self::assertSame(100, (int) $this->rows($complete->getId())['print|en']['score']);
        self::assertSame(33, (int) $this->rows($incomplete->getId())['default|en']['score']);
    }

    public function testReportPrintsRowsAndSummary(): void
    {
        $product = $this->product($this->completeValues());
        $product->save();
        $category = $this->category('Audio');
        $category->save();

        $csv = $this->runCommand('tsf:gatekeeper:report', ['--format' => 'csv', '--class' => ClassFixtures::PRODUCT]);
        self::assertSame(0, $csv->getStatusCode(), $csv->getDisplay());
        self::assertStringContainsString('object_id,object_key,path,class_name', $csv->getDisplay());
        self::assertStringContainsString($product->getKey(), $csv->getDisplay());
        self::assertStringNotContainsString($category->getKey(), $csv->getDisplay());

        $failed = $this->runCommand('tsf:gatekeeper:report', ['--only-failed' => true, '--format' => 'md']);
        self::assertStringContainsString($category->getKey(), $failed->getDisplay());
        self::assertStringNotContainsString($product->getKey(), $failed->getDisplay());

        $summary = $this->runCommand('tsf:gatekeeper:report', ['--summary' => true, '--format' => 'csv']);
        self::assertSame(0, $summary->getStatusCode(), $summary->getDisplay());
        self::assertStringContainsString('class_name,profile,language,objects,average_score,complete,failing', $summary->getDisplay());
        self::assertStringContainsString('GkCategory,default,en,1,50,0,1', $summary->getDisplay());
        self::assertStringContainsString('GkProduct,print,en,1,100,1,0', $summary->getDisplay());
    }

    public function testReportAssetWritesIntoTheAssetTree(): void
    {
        $product = $this->product($this->completeValues());
        $product->save();

        $tester = $this->runCommand('tsf:gatekeeper:report', ['--asset' => true]);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

        $csv = Asset::getByPath('/reports/completeness/GkProduct.csv');
        self::assertInstanceOf(Asset::class, $csv);
        self::assertStringContainsString($product->getKey(), (string) $csv->getData());

        $summary = Asset::getByPath('/reports/completeness/summary.md');
        self::assertInstanceOf(Asset::class, $summary);
        self::assertStringContainsString('GkProduct', (string) $summary->getData());
    }

    public function testAddScoreFieldAppendsANumericFieldWithoutTouchingTheOtherColumns(): void
    {
        // a class of its own, created and deleted here: the command rewrites the class definition and
        // regenerates its PHP class, which would strand objects of a class other tests already loaded
        $class = ClassFixtures::createScoreTarget();
        $storeTable = 'object_store_' . $class->getId();
        self::assertSame(['name', 'oo_id', 'sku'], $this->columns($storeTable));

        try {
            $tester = $this->runCommand('tsf:gatekeeper:add-score-field', ['class' => ClassFixtures::SCORE_TARGET, '--name' => 'score']);
            self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());

            // reloaded from the definition file; getByName() would hand back the very instance the
            // command mutated, which proves nothing about what was written
            $saved = ClassDefinition::getById($class->getId(), true);
            self::assertInstanceOf(Numeric::class, $saved->getFieldDefinitions()['score'] ?? null);

            // saving a class whose field definitions were emptied drops every data column of its
            // tables, so assert the columns survived alongside the new one
            self::assertSame(['name', 'oo_id', 'score', 'sku'], $this->columns($storeTable));

            $again = $this->runCommand('tsf:gatekeeper:add-score-field', ['class' => ClassFixtures::SCORE_TARGET, '--name' => 'score']);
            self::assertSame(1, $again->getStatusCode());
            self::assertStringContainsString('already has a field "score"', $again->getDisplay());
        } finally {
            ClassDefinition::getById($class->getId(), true)?->delete();
        }
    }

    /**
     * @return string[] column names, sorted
     */
    private function columns(string $table): array
    {
        $columns = array_keys($this->connection()->createSchemaManager()->listTableColumns($table));
        sort($columns);

        return $columns;
    }

    private function countRows(): int
    {
        return (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM `' . ResultStore::TABLE . '`');
    }
}
