<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Service;

use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Localizedfield;

/**
 * Resolves field definitions (top-level or inside "localizedfields") and reads values without
 * language fallback, so a value that only exists through the fallback language is not "filled".
 */
class FieldReader
{
    public const LOCALIZED_CONTAINER = 'localizedfields';

    /**
     * Top-level field or localized child. Note that ClassDefinition::getFieldDefinition() already
     * falls through to localized children, which is why the top-level map is consulted directly.
     */
    public function getDefinition(ClassDefinition $class, string $field): ?Data
    {
        return $this->topLevel($class, $field) ?? $this->localizedChild($class, $field);
    }

    public function isLocalized(ClassDefinition $class, string $field): bool
    {
        return $this->topLevel($class, $field) === null && $this->localizedChild($class, $field) !== null;
    }

    public function topLevel(ClassDefinition $class, string $field): ?Data
    {
        $definition = $class->getFieldDefinitions()[$field] ?? null;

        return $definition instanceof Data ? $definition : null;
    }

    private function localizedChild(ClassDefinition $class, string $field): ?Data
    {
        $container = $class->getFieldDefinitions()[self::LOCALIZED_CONTAINER] ?? null;
        if (!$container instanceof Data\Localizedfields) {
            return null;
        }

        $definition = $container->getFieldDefinition($field);

        return $definition instanceof Data ? $definition : null;
    }

    public function read(Concrete $object, string $field, ?string $language): mixed
    {
        if ($language === null) {
            return $object->get($field);
        }

        $container = $object->get(self::LOCALIZED_CONTAINER);
        if (!$container instanceof Localizedfield) {
            return null;
        }

        return $container->getLocalizedValue($field, $language, true);
    }
}
