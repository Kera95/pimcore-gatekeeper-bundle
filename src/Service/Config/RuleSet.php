<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Service\Config;

use Tsf\GatekeeperBundle\Model\ClassRule;
use Tsf\GatekeeperBundle\Model\Gate;
use Tsf\GatekeeperBundle\Model\Profile;

use function count;

/**
 * Turns the processed "classes" configuration into ClassRule objects. The class-level "required"
 * list becomes the profile named "default"; every other profile inherits languages and threshold
 * from the class when it does not set its own.
 */
final class RuleSet
{
    /**
     * @var ClassRule[] keyed by class name
     */
    private array $rules = [];

    /**
     * @param array<string, array<string, mixed>> $classes processed configuration, keyed by class name
     */
    public function __construct(array $classes)
    {
        foreach ($classes as $className => $config) {
            $this->rules[$className] = $this->buildRule((string) $className, $config);
        }
    }

    /**
     * @return ClassRule[] keyed by class name
     */
    public function all(): array
    {
        return $this->rules;
    }

    /**
     * @return ClassRule[] enabled rules only, keyed by class name
     */
    public function enabled(): array
    {
        return array_filter($this->rules, static fn (ClassRule $rule): bool => $rule->isEnabled());
    }

    public function get(string $className): ?ClassRule
    {
        return $this->rules[$className] ?? null;
    }

    public function getEnabled(string $className): ?ClassRule
    {
        $rule = $this->get($className);

        return $rule !== null && $rule->isEnabled() ? $rule : null;
    }

    /**
     * @param array<string, mixed> $config
     */
    private function buildRule(string $className, array $config): ClassRule
    {
        $languages = $config['languages'] ?? [];
        $threshold = (int) ($config['threshold'] ?? 100);
        $profiles = [];

        if (count($config['required'] ?? []) > 0) {
            $profiles[Profile::DEFAULT_NAME] = new Profile(
                Profile::DEFAULT_NAME,
                array_values($config['required']),
                array_values($languages),
                $threshold
            );
        }

        foreach ($config['profiles'] ?? [] as $name => $profile) {
            $profiles[(string) $name] = new Profile(
                (string) $name,
                array_values($profile['required']),
                array_values(count($profile['languages'] ?? []) > 0 ? $profile['languages'] : $languages),
                $profile['threshold'] ?? $threshold
            );
        }

        return new ClassRule(
            $className,
            (bool) ($config['enabled'] ?? true),
            Gate::from($config['gate'] ?? Gate::Warn->value),
            $config['score_field'] ?? null,
            $profiles
        );
    }
}
