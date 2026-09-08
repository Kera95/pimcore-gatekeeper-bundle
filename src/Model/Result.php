<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Model;

use function count;

/**
 * The outcome of one profile for one language ("" when the profile has no localized field)
 */
final class Result
{
    /**
     * @param string[] $required
     * @param string[] $missing
     */
    public function __construct(
        private readonly string $profile,
        private readonly string $language,
        private readonly array $required,
        private readonly array $missing,
        private readonly int $threshold,
    ) {
    }

    public function getProfile(): string
    {
        return $this->profile;
    }

    public function getLanguage(): string
    {
        return $this->language;
    }

    /**
     * @return string[]
     */
    public function getRequired(): array
    {
        return $this->required;
    }

    /**
     * @return string[]
     */
    public function getMissing(): array
    {
        return $this->missing;
    }

    public function getRequiredCount(): int
    {
        return count($this->required);
    }

    public function getMissingCount(): int
    {
        return count($this->missing);
    }

    public function getThreshold(): int
    {
        return $this->threshold;
    }

    /**
     * 0-100, rounded to the nearest integer. A profile without required fields is complete.
     */
    public function getScore(): int
    {
        $required = count($this->required);
        if ($required === 0) {
            return 100;
        }

        return (int) round(100 * ($required - count($this->missing)) / $required);
    }

    public function isPassed(): bool
    {
        return $this->getScore() >= $this->threshold;
    }

    /**
     * "profile/language" or just "profile" when the result is not language specific
     */
    public function getLabel(): string
    {
        return $this->language === '' ? $this->profile : $this->profile . '/' . $this->language;
    }
}
