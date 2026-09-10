<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Tests\Unit\Service\Report;

use Codeception\Test\Unit;
use Tsf\GatekeeperBundle\Service\Report\ReportBuilder;

final class ReportBuilderTest extends Unit
{
    private ReportBuilder $builder;

    protected function _before(): void
    {
        $this->builder = new ReportBuilder();
    }

    public function testSectionsGroupByClassAndProfileWithTotals(): void
    {
        $sections = $this->builder->sections($this->rows());

        self::assertCount(2, $sections);
        self::assertSame('Product · profile default · 2 objects · avg 75 % · 1 complete · 1 failing (threshold 100)', $sections[0]['title']);
        self::assertSame(['2', 'ABC-123', '-', 'yes', ' 100 %', ''], $sections[0]['rows'][0]);
        self::assertSame(['6', 'PCSA-001', '-', 'yes', '! 50 %', 'description,main_poduct_image'], $sections[0]['rows'][1]);
        self::assertSame('Product · profile print · 1 object · avg 50 % · 0 complete · 1 failing (threshold 80)', $sections[1]['title']);
        self::assertSame('de', $sections[1]['rows'][0][2]);
    }

    public function testCsvHasAHeaderAndQuotesWhereNeeded(): void
    {
        $lines = explode("\n", trim($this->builder->csv($this->rows())));

        self::assertSame(implode(',', ReportBuilder::CSV_COLUMNS), $lines[0]);
        self::assertSame('2,ABC-123,"/PC & laptops/ABC-123",Product,yes,default,,100,100,yes,0,,"2026-09-08 10:00:00"', $lines[1]);
        self::assertSame('6,PCSA-001,/PC/PCSA-001,Product,yes,default,,50,100,no,2,"description,main_poduct_image","2026-09-08 10:00:00"', $lines[2]);
        self::assertCount(4, $lines);
    }

    public function testCsvOfNoRowsIsJustTheHeader(): void
    {
        self::assertSame(implode(',', ReportBuilder::CSV_COLUMNS) . "\n", $this->builder->csv([]));
    }

    public function testMarkdownRendersOneTablePerSection(): void
    {
        $markdown = $this->builder->markdown($this->rows());

        self::assertStringContainsString('### Product · profile default', $markdown);
        self::assertStringContainsString('| id | key | lang | pub | score | missing |', $markdown);
        self::assertStringContainsString('| 6 | PCSA-001 | - | yes | ! 50 % | description,main_poduct_image |', $markdown);
        self::assertStringContainsString('### Product · profile print', $markdown);
    }

    public function testSummaryMarkdownListsTotalsMissingFieldsAndFailures(): void
    {
        $summary = [
            ['class_name' => 'Product', 'profile' => 'default', 'language' => '', 'objects' => 2, 'average_score' => 75, 'complete' => 1, 'failing' => 1],
        ];
        $markdown = $this->builder->summaryMarkdown($summary, ['description' => 3, 'ean' => 1], [$this->rows()[1]], new \DateTimeImmutable('2026-09-08 10:00'));

        self::assertStringStartsWith("# Completeness report\n\nGenerated 2026-09-08 10:00", $markdown);
        self::assertStringContainsString('| Product | default | - | 2 | 75 % | 1 | 1 |', $markdown);
        self::assertStringContainsString("| description | 3 |\n| ean | 1 |", $markdown);
        self::assertStringContainsString('## Failing objects', $markdown);
        self::assertStringContainsString('| 6 | PCSA-001 |', $markdown);
    }

    public function testSummaryTableCsvAndMarkdown(): void
    {
        $summary = [
            ['class_name' => 'Product', 'profile' => 'default', 'language' => '', 'objects' => 4, 'average_score' => 64, 'complete' => 1, 'failing' => 3, 'threshold' => 100, 'last_calculated_at' => '2026-09-08 10:00:00'],
            ['class_name' => 'Product', 'profile' => 'print', 'language' => 'de', 'objects' => 4, 'average_score' => 43, 'complete' => 1, 'failing' => 3, 'threshold' => 80, 'last_calculated_at' => '2026-09-08 10:00:00'],
        ];

        $table = $this->builder->summaryTable($summary);
        self::assertSame(['class', 'profile', 'lang', 'objects', 'avg', 'complete', 'failing', 'threshold'], $table['header']);
        self::assertSame(['Product', 'default', '-', '4', ' 64 %', '1', '3', '100 %'], $table['rows'][0]);

        $csv = explode("\n", trim($this->builder->summaryCsv($summary)));
        self::assertSame(implode(',', ReportBuilder::SUMMARY_COLUMNS), $csv[0]);
        self::assertSame('Product,print,de,4,43,1,3,80,"2026-09-08 10:00:00"', $csv[2]);

        self::assertStringContainsString('| Product | print | de | 4 | 43 % | 1 | 3 | 80 % |', $this->builder->summaryTableMarkdown($summary));
    }

    public function testSummaryMarkdownWithoutProblems(): void
    {
        $markdown = $this->builder->summaryMarkdown([], [], []);

        self::assertStringContainsString('Nothing is missing.', $markdown);
        self::assertStringContainsString("## Failing objects\n\nNone.", $markdown);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function rows(): array
    {
        $base = ['class_name' => 'Product', 'published' => 1, 'threshold' => 100, 'required_count' => 4, 'calculated_at' => '2026-09-08 10:00:00', 'type' => 'object'];

        return [
            $base + ['object_id' => 2, 'object_key' => 'ABC-123', 'path' => '/PC & laptops/ABC-123', 'profile' => 'default', 'language' => '', 'score' => 100, 'passed' => 1, 'missing_count' => 0, 'missing_fields' => ''],
            $base + ['object_id' => 6, 'object_key' => 'PCSA-001', 'path' => '/PC/PCSA-001', 'profile' => 'default', 'language' => '', 'score' => 50, 'passed' => 0, 'missing_count' => 2, 'missing_fields' => 'description,main_poduct_image'],
            ['threshold' => 80] + $base + ['object_id' => 6, 'object_key' => 'PCSA-001', 'path' => '/PC/PCSA-001', 'profile' => 'print', 'language' => 'de', 'score' => 50, 'passed' => 0, 'missing_count' => 1, 'missing_fields' => 'ean'],
        ];
    }
}
