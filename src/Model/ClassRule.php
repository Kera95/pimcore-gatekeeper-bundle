<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Model;

final class ClassRule
{
    /**
     * @param Profile[] $profiles keyed by profile name
     */
    public function __construct(
        private readonly string $className,
        private readonly bool $enabled,
        private readonly Gate $gate,
        private readonly ?string $scoreField,
        private readonly array $profiles,
    ) {
    }

    public function getClassName(): string
    {
        return $this->className;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    public function getGate(): Gate
    {
        return $this->gate;
    }

    public function getScoreField(): ?string
    {
        return $this->scoreField;
    }

    /**
     * @return Profile[] keyed by profile name
     */
    public function getProfiles(): array
    {
        return $this->profiles;
    }

    public function getProfile(string $name): ?Profile
    {
        return $this->profiles[$name] ?? null;
    }

    /**
     * @return string[]
     */
    public function getProfileNames(): array
    {
        return array_keys($this->profiles);
    }

    /**
     * Every field name referenced by any profile, without duplicates
     *
     * @return string[]
     */
    public function getAllRequiredFields(): array
    {
        $fields = [];
        foreach ($this->profiles as $profile) {
            foreach ($profile->getRequired() as $field) {
                $fields[$field] = true;
            }
        }

        return array_keys($fields);
    }
}
