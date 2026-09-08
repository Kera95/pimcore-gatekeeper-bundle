<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Model;

/**
 * One named rule set of a class: which fields must be filled, for which languages, and the score
 * that counts as complete. The class-level rule is the profile named "default".
 */
final class Profile
{
    public const DEFAULT_NAME = 'default';

    /**
     * @param string[] $required   field names, top-level or localized children
     * @param string[] $languages  empty = every valid system language
     */
    public function __construct(
        private readonly string $name,
        private readonly array $required,
        private readonly array $languages,
        private readonly int $threshold,
    ) {
    }

    public function getName(): string
    {
        return $this->name;
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
    public function getLanguages(): array
    {
        return $this->languages;
    }

    public function getThreshold(): int
    {
        return $this->threshold;
    }
}
