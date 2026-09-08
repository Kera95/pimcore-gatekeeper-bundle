<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Service\Report;

use Tsf\GatekeeperBundle\Service\ResultStore;

/**
 * Custom Reports definitions (pimcore_custom_reports.definitions) that render the result table in
 * the admin UI: Studio (Reporting) and Classic (Marketing > Custom Reports). Pure data, no UI code.
 */
final class StudioReportDefinitions
{
    public const GROUP = 'Completeness';

    public const OBJECTS = 'tsf_gatekeeper_objects';

    public const SUMMARY = 'tsf_gatekeeper_summary';

    /**
     * Fixed timestamp: the definitions are code, not user edits. Studio requires an integer here.
     */
    private const TIMESTAMP = 1_757_000_000;

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        return [
            self::OBJECTS => self::objects(),
            self::SUMMARY => self::summary(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function objects(): array
    {
        $sql = sprintf(
            'SELECT r.object_id, o.`key` AS object_key, CONCAT(o.path, o.`key`) AS path, r.class_name, o.published, '
            . 'r.profile, r.language, r.score, r.threshold, r.passed, r.missing_count, r.missing_fields, r.calculated_at '
            . 'FROM %s r INNER JOIN objects o ON o.id = r.object_id',
            ResultStore::TABLE
        );

        return self::definition(self::OBJECTS, 'Completeness: objects', $sql, [
            self::column('object_id', 'ID', ['action' => 'openObject', 'width' => 80]),
            self::column('object_key', 'Key'),
            self::column('path', 'Path'),
            self::column('class_name', 'Class', ['width' => 110]),
            self::column('published', 'Published', ['width' => 90]),
            self::column('profile', 'Profile', ['width' => 100]),
            self::column('language', 'Language', ['width' => 90]),
            self::column('score', 'Score %', ['width' => 90]),
            self::column('threshold', 'Threshold %', ['width' => 100]),
            self::column('passed', 'Passed', ['width' => 80]),
            self::column('missing_count', 'Missing', ['width' => 80]),
            self::column('missing_fields', 'Missing fields'),
            self::column('calculated_at', 'Calculated at', ['width' => 150]),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public static function summary(): array
    {
        $sql = sprintf(
            'SELECT r.class_name, r.profile, r.language, COUNT(*) AS objects, ROUND(AVG(r.score)) AS average_score, '
            . 'SUM(r.passed) AS complete, SUM(1 - r.passed) AS failing, MAX(r.calculated_at) AS last_calculated_at '
            . 'FROM %s r GROUP BY r.class_name, r.profile, r.language',
            ResultStore::TABLE
        );

        return self::definition(self::SUMMARY, 'Completeness: summary', $sql, [
            self::column('class_name', 'Class'),
            self::column('profile', 'Profile'),
            self::column('language', 'Language'),
            self::column('objects', 'Objects'),
            self::column('average_score', 'Average score %'),
            self::column('complete', 'Complete'),
            self::column('failing', 'Failing'),
            self::column('last_calculated_at', 'Last calculated'),
        ]);
    }

    /**
     * @param array<int, array<string, mixed>> $columns
     *
     * @return array<string, mixed>
     */
    private static function definition(string $name, string $niceName, string $sql, array $columns): array
    {
        return [
            'id' => $name,
            'name' => $name,
            'niceName' => $niceName,
            'group' => self::GROUP,
            'groupIconClass' => '',
            'iconClass' => '',
            'menuShortcut' => true,
            'reportClass' => '',
            'chartType' => '',
            'pieColumn' => '',
            'pieLabelColumn' => '',
            'xAxis' => '',
            'yAxis' => [],
            'shareGlobally' => true,
            'sharedUserNames' => [],
            'sharedRoleNames' => [],
            'pagination' => true,
            'modificationDate' => self::TIMESTAMP,
            'creationDate' => self::TIMESTAMP,
            'dataSourceConfig' => [
                [
                    'type' => 'sql',
                    'sql' => $sql,
                    'from' => '',
                    'where' => '',
                    'groupby' => '',
                ],
            ],
            'columnConfiguration' => $columns,
        ];
    }

    /**
     * Keys are the ones Studio's ColumnHydrator reads; anything else makes it fail.
     *
     * @param array<string, mixed> $extra
     *
     * @return array<string, mixed>
     */
    private static function column(string $name, string $label, array $extra = []): array
    {
        return array_merge([
            'name' => $name,
            'id' => $name,
            'label' => $label,
            'display' => true,
            'export' => true,
            'order' => true,
            'action' => '',
        ], $extra);
    }
}
