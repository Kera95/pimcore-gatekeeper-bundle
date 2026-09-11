<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Tests\Functional;

use Pimcore\Model\Asset;
use Tsf\GatekeeperBundle\Service\Report\AssetWriter;
use Tsf\GatekeeperBundle\Tests\Support\FunctionalTestCase;

final class AssetWriterTest extends FunctionalTestCase
{
    public function testWriteAllCreatesOneCsvPerClassAndTheSummaryAndOverwritesInPlace(): void
    {
        $product = $this->product($this->completeValues());
        $product->save();
        $category = $this->category('Audio');
        $category->save();

        /** @var AssetWriter $writer */
        $writer = $this->service(AssetWriter::class);
        self::assertTrue($writer->isEnabled());

        $written = $writer->writeAll();
        self::assertSame(
            ['/reports/completeness/GkCategory.csv', '/reports/completeness/GkProduct.csv', '/reports/completeness/summary.md'],
            $written
        );

        $csv = Asset::getByPath('/reports/completeness/GkProduct.csv');
        self::assertInstanceOf(Asset::class, $csv);
        self::assertStringContainsString($product->getKey(), (string) $csv->getData());
        self::assertStringNotContainsString($category->getKey(), (string) $csv->getData());

        $summary = Asset::getByPath('/reports/completeness/summary.md');
        self::assertInstanceOf(Asset::class, $summary);
        self::assertStringContainsString('GkCategory', (string) $summary->getData());
        self::assertStringContainsString('title', (string) $summary->getData());

        // a second run updates the same assets instead of creating new ones
        $category->setTitle('Audio', 'en');
        $category->save();
        $writer->writeAll();

        $summaryAgain = Asset::getByPath('/reports/completeness/summary.md');
        self::assertSame($summary->getId(), $summaryAgain->getId());
        self::assertStringNotContainsString('title', (string) $summaryAgain->getData());
    }

    public function testTimestampedFilesAreCreatedNextToTheCurrentOnes(): void
    {
        $this->product($this->completeValues())->save();

        /** @var AssetWriter $writer */
        $writer = $this->service(AssetWriter::class);
        $at = new \DateTimeImmutable('2026-09-10 08:15:00');

        $written = $writer->writeAll(null, true, $at);

        self::assertSame(['/reports/completeness/GkProduct-20260910-0815.csv', '/reports/completeness/summary-20260910-0815.md'], $written);
        self::assertInstanceOf(Asset::class, Asset::getByPath('/reports/completeness/summary-20260910-0815.md'));
    }
}
