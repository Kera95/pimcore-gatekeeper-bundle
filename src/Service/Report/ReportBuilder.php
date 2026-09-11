<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Service\Report;

use function count;
use function is_string;

/**
 * Renders result rows (as returned by ResultStore) as console table sections, CSV or Markdown.
 * Pure formatting, no database access, so every format is unit tested from fixture rows.
 */
class ReportBuilder
{
    public const SUMMARY_COLUMNS = ['class_name', 'profile', 'language', 'objects', 'average_score', 'complete', 'failing', 'threshold', 'last_calculated_at'];

    public const CSV_COLUMNS = ['object_id', 'object_key', 'path', 'class_name', 'published', 'profile', 'language', 'score', 'threshold', 'passed', 'missing_count', 'missing_fields', 'calculated_at'];

    /**
     * Groups rows into sections per class and profile, each with a headline and its rows
     *
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<int, array{title: string, header: string[], rows: array<int, array<int, string>>}>
     */
    public function sections(array $rows): array
    {
        $groups = [];
        foreach ($rows as $row) {
            $key = $row['class_name'] . '|' . $row['profile'];
            $groups[$key][] = $row;
        }

        $sections = [];
        foreach ($groups as $group) {
            $first = $group[0];
            $objectIds = [];
            $failingIds = [];
            $scoreSum = 0;
            foreach ($group as $row) {
                $objectIds[(int) $row['object_id']] = true;
                if ((int) $row['passed'] === 0) {
                    $failingIds[(int) $row['object_id']] = true;
                }
                $scoreSum += (int) $row['score'];
            }

            $sections[] = [
                'title' => sprintf(
                    '%s · profile %s · %d object%s · avg %d %% · %d complete · %d failing (threshold %d)',
                    $first['class_name'],
                    $first['profile'],
                    count($objectIds),
                    count($objectIds) === 1 ? '' : 's',
                    (int) round($scoreSum / count($group)),
                    count($objectIds) - count($failingIds),
                    count($failingIds),
                    (int) $first['threshold']
                ),
                'header' => ['id', 'key', 'lang', 'pub', 'score', 'missing'],
                'rows' => array_map(static fn (array $row): array => [
                    (string) $row['object_id'],
                    (string) $row['object_key'],
                    $row['language'] === '' ? '-' : (string) $row['language'],
                    (int) $row['published'] === 1 ? 'yes' : 'no',
                    sprintf('%s%3d %%', (int) $row['passed'] === 1 ? ' ' : '!', (int) $row['score']),
                    (string) $row['missing_fields'],
                ], $group),
            ];
        }

        return $sections;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function csv(array $rows): string
    {
        return $this->toCsv(self::CSV_COLUMNS, $rows);
    }

    /**
     * @param string[] $columns
     * @param array<int, array<string, mixed>> $rows
     */
    private function toCsv(array $columns, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            throw new \RuntimeException('Could not open a temporary stream.');
        }

        fputcsv($handle, $columns, ',', '"', '\\', "\n");
        foreach ($rows as $row) {
            $line = [];
            foreach ($columns as $column) {
                $value = $row[$column] ?? '';
                $line[] = $column === 'passed' || $column === 'published' ? ((int) $value === 1 ? 'yes' : 'no') : (string) $value;
            }
            fputcsv($handle, $line, ',', '"', '\\', "\n");
        }

        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     */
    public function markdown(array $rows): string
    {
        $out = [];
        foreach ($this->sections($rows) as $section) {
            $out[] = '### ' . $section['title'];
            $out[] = '';
            array_push($out, ...$this->markdownTable($section['header'], $section['rows']));
            $out[] = '';
        }

        return implode("\n", $out);
    }

    /**
     * Summary rows (ResultStore::fetchSummary) as a single table: header + string cells
     *
     * @param array<int, array<string, mixed>> $summary
     *
     * @return array{header: string[], rows: array<int, array<int, string>>}
     */
    public function summaryTable(array $summary): array
    {
        return [
            'header' => ['class', 'profile', 'lang', 'objects', 'avg', 'complete', 'failing', 'threshold'],
            'rows' => array_map(static fn (array $row): array => [
                (string) $row['class_name'],
                (string) $row['profile'],
                $row['language'] === '' ? '-' : (string) $row['language'],
                (string) (int) $row['objects'],
                sprintf('%3d %%', (int) $row['average_score']),
                (string) (int) $row['complete'],
                (string) (int) $row['failing'],
                sprintf('%3d %%', (int) $row['threshold']),
            ], $summary),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $summary
     */
    public function summaryCsv(array $summary): string
    {
        return $this->toCsv(self::SUMMARY_COLUMNS, $summary);
    }

    /**
     * @param array<int, array<string, mixed>> $summary
     */
    public function summaryTableMarkdown(array $summary): string
    {
        $table = $this->summaryTable($summary);

        return implode("\n", $this->markdownTable($table['header'], $table['rows']));
    }

    /**
     * Header, separator and one line per row, cells trimmed and pipes escaped
     *
     * @param string[] $header
     * @param array<int, array<int, string>> $rows
     *
     * @return string[]
     */
    private function markdownTable(array $header, array $rows): array
    {
        $out = ['| ' . implode(' | ', $header) . ' |', '|' . str_repeat(' --- |', count($header))];
        foreach ($rows as $row) {
            $out[] = '| ' . implode(' | ', array_map(static fn (string $cell): string => str_replace('|', '\\|', trim($cell)), $row)) . ' |';
        }

        return $out;
    }

    /**
     * Human summary across all classes: totals per class/profile/language, most missing fields,
     * failing objects. This is what lands in summary.md.
     *
     * @param array<int, array<string, mixed>> $summary  rows from ResultStore::fetchSummary()
     * @param array<string, int> $missingFrequency       from ResultStore::fetchMissingFieldFrequency()
     * @param array<int, array<string, mixed>> $failing  rows from ResultStore::fetchRows(onlyFailed: true)
     */
    public function summaryMarkdown(array $summary, array $missingFrequency, array $failing, ?\DateTimeImmutable $at = null): string
    {
        $at ??= new \DateTimeImmutable();
        $out = ['# Completeness report', '', 'Generated ' . $at->format('Y-m-d H:i') . ' by TsfGatekeeperBundle.', ''];

        $out[] = '## Totals';
        $out[] = '';
        $out[] = '| class | profile | language | objects | avg score | complete | failing |';
        $out[] = '| --- | --- | --- | ---: | ---: | ---: | ---: |';
        foreach ($summary as $row) {
            $out[] = sprintf(
                '| %s | %s | %s | %d | %d %% | %d | %d |',
                $row['class_name'],
                $row['profile'],
                $row['language'] === '' ? '-' : $row['language'],
                (int) $row['objects'],
                (int) $row['average_score'],
                (int) $row['complete'],
                (int) $row['failing']
            );
        }
        $out[] = '';

        $out[] = '## Most frequently missing fields';
        $out[] = '';
        if (count($missingFrequency) === 0) {
            $out[] = 'Nothing is missing.';
        } else {
            $out[] = '| field | missing in |';
            $out[] = '| --- | ---: |';
            foreach (array_slice($missingFrequency, 0, 10, true) as $field => $count) {
                $out[] = sprintf('| %s | %d |', $field, $count);
            }
        }
        $out[] = '';

        $out[] = '## Failing objects';
        $out[] = '';
        if (count($failing) === 0) {
            $out[] = 'None.';
        } else {
            $out[] = $this->markdown($failing);
        }

        return implode("\n", $out) . "\n";
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     *
     * @return array<string, array<int, array<string, mixed>>> rows keyed by class name
     */
    public function byClass(array $rows): array
    {
        $byClass = [];
        foreach ($rows as $row) {
            $class = is_string($row['class_name']) ? $row['class_name'] : (string) $row['class_name'];
            $byClass[$class][] = $row;
        }

        return $byClass;
    }
}
