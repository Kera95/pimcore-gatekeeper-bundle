<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Model;

use function count;

/**
 * All results of one object: one Result per profile and language
 */
final class Evaluation
{
    /**
     * @param Result[] $results
     */
    public function __construct(
        private readonly string $className,
        private readonly array $results,
    ) {
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    /**
     * @return Result[]
     */
    public function getResults(): array
    {
        return $this->results;
    }

    /**
     * The worst score over all profiles and languages; what the editor needs to know
     */
    public function getAggregateScore(): int
    {
        if (count($this->results) === 0) {
            return 100;
        }

        return min(array_map(static fn (Result $result): int => $result->getScore(), $this->results));
    }

    /**
     * @return Result[]
     */
    public function getFailed(): array
    {
        return array_values(array_filter(
            $this->results,
            static fn (Result $result): bool => !$result->isPassed()
        ));
    }

    public function isPassed(): bool
    {
        return count($this->getFailed()) === 0;
    }

    /**
     * Human readable list of what is missing, e.g. "default: ean, description; print/de: name"
     */
    public function describeFailures(): string
    {
        $parts = [];
        foreach ($this->getFailed() as $result) {
            $parts[] = sprintf(
                '%s (%d%% < %d%%): %s',
                $result->getLabel(),
                $result->getScore(),
                $result->getThreshold(),
                implode(', ', $result->getMissing()) ?: '-'
            );
        }

        return implode('; ', $parts);
    }
}
