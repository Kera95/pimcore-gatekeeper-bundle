<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Service;

use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Tsf\GatekeeperBundle\Model\Evaluation;

use function count;

/**
 * The bundle's own table: one row per object, profile and language. Source of truth for every
 * report; the optional score field on the object is only a mirror of the aggregate.
 */
class ResultStore
{
    public const TABLE = 'tsf_gatekeeper_result';

    public function __construct(private readonly Connection $connection)
    {
    }

    public static function createTableSql(): string
    {
        return 'CREATE TABLE IF NOT EXISTS `' . self::TABLE . '` (
  `object_id`      INT UNSIGNED      NOT NULL,
  `class_name`     VARCHAR(190)      NOT NULL,
  `profile`        VARCHAR(100)      NOT NULL DEFAULT \'default\',
  `language`       VARCHAR(10)       NOT NULL DEFAULT \'\',
  `score`          TINYINT UNSIGNED  NOT NULL,
  `required_count` SMALLINT UNSIGNED NOT NULL,
  `missing_count`  SMALLINT UNSIGNED NOT NULL,
  `missing_fields` TEXT              NULL,
  `threshold`      TINYINT UNSIGNED  NOT NULL,
  `passed`         TINYINT(1)        NOT NULL,
  `calculated_at`  DATETIME          NOT NULL,
  PRIMARY KEY (`object_id`, `profile`, `language`),
  KEY `idx_class_score` (`class_name`, `score`),
  KEY `idx_passed` (`passed`)
) DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_520_ci';
    }

    /**
     * Upserts every result of the evaluation and removes rows of profiles/languages that no
     * longer exist in the configuration.
     */
    public function save(int $objectId, Evaluation $evaluation, ?\DateTimeImmutable $at = null): void
    {
        $at ??= new \DateTimeImmutable();
        $keep = [];

        foreach ($evaluation->getResults() as $result) {
            $keep[] = $result->getProfile() . '|' . $result->getLanguage();
            $this->connection->executeStatement(
                'INSERT INTO `' . self::TABLE . '` (object_id, class_name, profile, language, score, required_count, missing_count, missing_fields, threshold, passed, calculated_at)
                 VALUES (:object_id, :class_name, :profile, :language, :score, :required_count, :missing_count, :missing_fields, :threshold, :passed, :calculated_at)
                 ON DUPLICATE KEY UPDATE class_name = VALUES(class_name), score = VALUES(score), required_count = VALUES(required_count),
                    missing_count = VALUES(missing_count), missing_fields = VALUES(missing_fields), threshold = VALUES(threshold),
                    passed = VALUES(passed), calculated_at = VALUES(calculated_at)',
                [
                    'object_id' => $objectId,
                    'class_name' => $evaluation->getClassName(),
                    'profile' => $result->getProfile(),
                    'language' => $result->getLanguage(),
                    'score' => $result->getScore(),
                    'required_count' => $result->getRequiredCount(),
                    'missing_count' => $result->getMissingCount(),
                    'missing_fields' => implode(',', $result->getMissing()),
                    'threshold' => $result->getThreshold(),
                    'passed' => $result->isPassed() ? 1 : 0,
                    'calculated_at' => $at->format('Y-m-d H:i:s'),
                ]
            );
        }

        if (count($keep) === 0) {
            $this->delete($objectId);

            return;
        }

        $this->connection->executeStatement(
            'DELETE FROM `' . self::TABLE . '` WHERE object_id = :object_id AND CONCAT(profile, \'|\', language) NOT IN (:keep)',
            ['object_id' => $objectId, 'keep' => $keep],
            ['keep' => ArrayParameterType::STRING]
        );
    }

    public function delete(int $objectId): void
    {
        $this->connection->executeStatement('DELETE FROM `' . self::TABLE . '` WHERE object_id = :id', ['id' => $objectId]);
    }

    public function deleteClass(string $className): void
    {
        $this->connection->executeStatement('DELETE FROM `' . self::TABLE . '` WHERE class_name = :c', ['c' => $className]);
    }

    /**
     * Result rows joined with the object tree, ordered by class, profile, language, score
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchRows(?string $className = null, ?string $profile = null, ?string $language = null, ?int $below = null, bool $onlyFailed = false): array
    {
        [$where, $params] = $this->filters($className, $profile, $language, $below, $onlyFailed);

        $sql = 'SELECT r.object_id, o.`key` AS object_key, CONCAT(o.path, o.`key`) AS path, r.class_name, o.published, o.type,
                    r.profile, r.language, r.score, r.threshold, r.passed, r.required_count, r.missing_count, r.missing_fields, r.calculated_at
                FROM `' . self::TABLE . '` r
                INNER JOIN objects o ON o.id = r.object_id'
            . $where
            . ' ORDER BY r.class_name, r.profile, r.object_id ASC, r.language';

        return $this->connection->fetchAllAssociative($sql, $params);
    }

    /**
     * One row per class, profile and language with counts and the average score
     *
     * @return array<int, array<string, mixed>>
     */
    public function fetchSummary(?string $className = null, ?string $profile = null, ?string $language = null): array
    {
        [$where, $params] = $this->filters($className, $profile, $language, null, false);

        $sql = 'SELECT r.class_name, r.profile, r.language, COUNT(*) AS objects, ROUND(AVG(r.score)) AS average_score,
                    SUM(r.passed) AS complete, SUM(1 - r.passed) AS failing, MAX(r.threshold) AS threshold, MAX(r.calculated_at) AS last_calculated_at
                FROM `' . self::TABLE . '` r
                INNER JOIN objects o ON o.id = r.object_id'
            . $where
            . ' GROUP BY r.class_name, r.profile, r.language ORDER BY r.class_name, r.profile, r.language';

        return $this->connection->fetchAllAssociative($sql, $params);
    }

    /**
     * Most frequently missing fields, for the summary
     *
     * @return array<string, int> field => count
     */
    public function fetchMissingFieldFrequency(?string $className = null, ?string $profile = null, ?string $language = null): array
    {
        [$where, $params] = $this->filters($className, $profile, $language, null, true);

        $rows = $this->connection->fetchAllAssociative(
            'SELECT r.missing_fields FROM `' . self::TABLE . '` r INNER JOIN objects o ON o.id = r.object_id' . $where,
            $params
        );

        $counts = [];
        foreach ($rows as $row) {
            foreach (array_filter(explode(',', (string) $row['missing_fields'])) as $field) {
                $counts[$field] = ($counts[$field] ?? 0) + 1;
            }
        }
        arsort($counts);

        return $counts;
    }

    /**
     * @return string[] distinct class names present in the table
     */
    public function fetchClassNames(): array
    {
        return array_map('strval', $this->connection->fetchFirstColumn('SELECT DISTINCT class_name FROM `' . self::TABLE . '` ORDER BY class_name'));
    }

    /**
     * @return array{0: string, 1: array<string, mixed>}
     */
    private function filters(?string $className, ?string $profile, ?string $language, ?int $below, bool $onlyFailed): array
    {
        $conditions = [];
        $params = [];

        if ($className !== null) {
            $conditions[] = 'r.class_name = :class_name';
            $params['class_name'] = $className;
        }
        if ($profile !== null) {
            $conditions[] = 'r.profile = :profile';
            $params['profile'] = $profile;
        }
        if ($language !== null) {
            $conditions[] = 'r.language = :language';
            $params['language'] = $language;
        }
        if ($below !== null) {
            $conditions[] = 'r.score < :below';
            $params['below'] = $below;
        }
        if ($onlyFailed) {
            $conditions[] = 'r.passed = 0';
        }

        return [count($conditions) > 0 ? ' WHERE ' . implode(' AND ', $conditions) : '', $params];
    }
}
