<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Model;

use function count;

/**
 * One stored row of tsf_gatekeeper_result, joined with the object it belongs to. The public read
 * shape of the result table: other bundles consume this instead of the table columns.
 *
 * @api
 */
final class ResultRow
{
    /**
     * @param string[] $missing field paths in the bundle's addressing syntax
     */
    public function __construct(
        private readonly int $objectId,
        private readonly string $objectKey,
        private readonly string $path,
        private readonly string $className,
        private readonly bool $published,
        private readonly string $profile,
        private readonly string $language,
        private readonly int $score,
        private readonly int $threshold,
        private readonly bool $passed,
        private readonly int $requiredCount,
        private readonly array $missing,
        private readonly \DateTimeImmutable $calculatedAt,
    ) {
    }

    /**
     * @param array<string, mixed> $row a row of ResultStore::fetchRows()
     */
    public static function fromArray(array $row): self
    {
        return new self(
            (int) $row['object_id'],
            (string) $row['object_key'],
            (string) $row['path'],
            (string) $row['class_name'],
            (bool) $row['published'],
            (string) $row['profile'],
            (string) $row['language'],
            (int) $row['score'],
            (int) $row['threshold'],
            (bool) $row['passed'],
            (int) $row['required_count'],
            array_values(array_filter(explode(',', (string) ($row['missing_fields'] ?? '')))),
            new \DateTimeImmutable((string) $row['calculated_at'])
        );
    }

    public function getObjectId(): int
    {
        return $this->objectId;
    }

    public function getObjectKey(): string
    {
        return $this->objectKey;
    }

    public function getPath(): string
    {
        return $this->path;
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function isPublished(): bool
    {
        return $this->published;
    }

    public function getProfile(): string
    {
        return $this->profile;
    }

    /**
     * "" when the profile has no localized field
     */
    public function getLanguage(): string
    {
        return $this->language;
    }

    public function getScore(): int
    {
        return $this->score;
    }

    public function getThreshold(): int
    {
        return $this->threshold;
    }

    public function isPassed(): bool
    {
        return $this->passed;
    }

    public function getRequiredCount(): int
    {
        return $this->requiredCount;
    }

    public function getMissingCount(): int
    {
        return count($this->missing);
    }

    /**
     * @return string[]
     */
    public function getMissing(): array
    {
        return $this->missing;
    }

    public function getCalculatedAt(): \DateTimeImmutable
    {
        return $this->calculatedAt;
    }

    /**
     * "profile/language" or just "profile" when the row is not language specific
     */
    public function getLabel(): string
    {
        return $this->language === '' ? $this->profile : $this->profile . '/' . $this->language;
    }
}
