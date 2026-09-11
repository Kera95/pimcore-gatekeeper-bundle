<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Tests\Functional;

use Pimcore\Model\DataObject;
use Pimcore\Model\Element\ValidationException;
use Tsf\GatekeeperBundle\Service\ResultStore;
use Tsf\GatekeeperBundle\Tests\Support\FunctionalTestCase;

/**
 * The listener on a real save: evaluation, score field, result rows and the gate
 */
final class SaveListenerTest extends FunctionalTestCase
{
    public function testCompleteProductIsScoredAndStored(): void
    {
        $product = $this->product($this->completeValues());
        $product->save();

        self::assertSame(100, $product->getCompleteness());

        $rows = $this->rows($product->getId());
        self::assertSame(['default|de', 'default|en', 'print|en'], array_keys($rows));
        foreach ($rows as $row) {
            self::assertSame(100, (int) $row['score']);
            self::assertSame(1, (int) $row['passed']);
            self::assertSame(0, (int) $row['missing_count']);
            self::assertSame('', (string) $row['missing_fields']);
        }
        self::assertSame(3, (int) $rows['default|en']['required_count']);
        self::assertSame(50, (int) $rows['print|en']['threshold']);
        self::assertSame(100, (int) $rows['default|en']['threshold']);

        // the score survives a reload, i.e. it was written before the object was persisted
        $reloaded = DataObject::getById($product->getId(), ['force' => true]);
        self::assertSame(100, $reloaded->getCompleteness());
    }

    public function testIncompletePublishedProductIsBlocked(): void
    {
        $product = $this->product(['sku' => 'SKU-2']);

        try {
            $product->save();
            self::fail('Expected the block gate to refuse the save.');
        } catch (ValidationException $e) {
            self::assertStringContainsString('Completeness gate: GkProduct', $e->getMessage());
            self::assertStringContainsString('name', $e->getMessage());
            self::assertStringContainsString('title', $e->getMessage());
        }

        self::assertNull($product->getId());
        self::assertSame(0, (int) $this->connection()->fetchOne('SELECT COUNT(*) FROM `' . ResultStore::TABLE . '`'));
    }

    public function testIncompleteUnpublishedProductIsStoredWithItsScore(): void
    {
        $product = $this->product(['sku' => 'SKU-3', 'name' => 'Cable', 'title' => ['en' => 'Cable']], false);
        $product->save();

        // aggregate = worst result: the print profile has nothing filled
        self::assertSame(0, $product->getCompleteness());

        $rows = $this->rows($product->getId());
        self::assertSame(100, (int) $rows['default|en']['score']);
        self::assertSame(1, (int) $rows['default|en']['passed']);

        self::assertSame(67, (int) $rows['default|de']['score']);
        self::assertSame(0, (int) $rows['default|de']['passed']);
        self::assertSame('title', $rows['default|de']['missing_fields']);

        self::assertSame(0, (int) $rows['print|en']['score']);
        self::assertSame('description,bricks.GkDimensions.width,features.GkFeature.label', $rows['print|en']['missing_fields']);
    }

    public function testProfileThresholdLetsAPartiallyFilledPrintProfilePass(): void
    {
        $values = $this->completeValues();
        unset($values['width']);

        $product = $this->product($values);
        $product->save();

        $rows = $this->rows($product->getId());
        self::assertSame(67, (int) $rows['print|en']['score']);
        self::assertSame(1, (int) $rows['print|en']['passed']);
        self::assertSame('bricks.GkDimensions.width', $rows['print|en']['missing_fields']);
        self::assertSame(67, $product->getCompleteness());
    }

    public function testSavingAgainReplacesTheRows(): void
    {
        $product = $this->product(['sku' => 'SKU-4'], false);
        $product->save();
        self::assertSame(33, (int) $this->rows($product->getId())['default|en']['score']);

        $product->setName('Headphones');
        $product->setTitle('Headphones', 'en');
        $product->setTitle('Kopfhörer', 'de');
        $product->setDescription('Over-ear.', 'en');
        $product->save();

        $rows = $this->rows($product->getId());
        self::assertCount(3, $rows);
        self::assertSame(100, (int) $rows['default|en']['score']);
        self::assertSame(100, (int) $rows['default|de']['score']);
        self::assertSame(33, (int) $rows['print|en']['score']);
        self::assertSame(33, $product->getCompleteness());
    }

    public function testDeletingAnObjectRemovesItsRows(): void
    {
        $product = $this->product($this->completeValues());
        $product->save();
        $id = $product->getId();
        self::assertCount(3, $this->rows($id));

        $product->delete();

        self::assertCount(0, $this->rows($id));
    }

    public function testWarnGateSavesAnIncompleteObject(): void
    {
        $category = $this->category('Audio');
        $category->save();

        self::assertNotNull($category->getId());
        $rows = $this->rows($category->getId());
        self::assertSame(['default|en'], array_keys($rows));
        self::assertSame(50, (int) $rows['default|en']['score']);
        self::assertSame(0, (int) $rows['default|en']['passed']);
        self::assertSame('title', $rows['default|en']['missing_fields']);
        self::assertSame('GkCategory', $rows['default|en']['class_name']);
    }
}
