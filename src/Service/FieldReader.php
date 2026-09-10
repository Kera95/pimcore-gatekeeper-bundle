<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Service;

use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Fieldcollection;
use Pimcore\Model\DataObject\Localizedfield;
use Pimcore\Model\DataObject\Objectbrick;

use function count;
use function in_array;
use function sprintf;

/**
 * Resolves field definitions and reads values for the three supported field paths:
 *
 * - `name`                      top-level field of the class
 * - `name`                      child of the class' "localizedfields" (read per language, no fallback)
 * - `container.Type.name`       field of an object brick (`bricks.Dimensions.width`) or of a field
 *                               collection item (`features.Feature.label`); localized children of the
 *                               brick/collection are supported the same way
 *
 * Values are read without language fallback, so a value that only exists through the fallback
 * language does not count as filled. A field collection yields one candidate value per item of
 * the given type; the field counts as filled when any item has it filled.
 */
class FieldReader
{
    public const LOCALIZED_CONTAINER = 'localizedfields';

    public const PATH_SEPARATOR = '.';

    public function getDefinition(ClassDefinition $class, string $field): ?Data
    {
        $holder = $this->holder($class, $field);

        return $holder === null ? null : ($this->topLevelOf($holder[0], $holder[1]) ?? $this->localizedChildOf($holder[0], $holder[1]));
    }

    public function isLocalized(ClassDefinition $class, string $field): bool
    {
        $holder = $this->holder($class, $field);

        return $holder !== null
            && $this->topLevelOf($holder[0], $holder[1]) === null
            && $this->localizedChildOf($holder[0], $holder[1]) !== null;
    }

    /**
     * Why a field path is not usable on the class, or null when it is
     */
    public function describeProblem(ClassDefinition $class, string $field): ?string
    {
        if ($field === self::LOCALIZED_CONTAINER) {
            return sprintf('list the localized fields by name instead of "%s".', $field);
        }

        $path = $this->parse($field);
        if ($path === null) {
            if (str_contains($field, self::PATH_SEPARATOR)) {
                return sprintf('"%s" is not a valid path; nested fields are written as container.Type.field, e.g. bricks.Dimensions.width.', $field);
            }

            return $this->getDefinition($class, $field) === null
                ? sprintf('field "%s" does not exist on the class (top-level or localized).', $field)
                : null;
        }

        [$container, $type, $name] = $path;
        if ($name === self::LOCALIZED_CONTAINER) {
            return sprintf('"%s": list the localized fields of "%s" by name instead of "%s".', $field, $type, $name);
        }
        $containerDefinition = $this->topLevel($class, $container);
        if (!$containerDefinition instanceof Data\Objectbricks && !$containerDefinition instanceof Data\Fieldcollections) {
            return sprintf('"%s": "%s" is not an object bricks or field collections field of the class.', $field, $container);
        }
        if (!in_array($type, $containerDefinition->getAllowedTypes(), true)) {
            return sprintf('"%s": type "%s" is not allowed in "%s" (allowed: %s).', $field, $type, $container, implode(', ', $containerDefinition->getAllowedTypes()) ?: 'none');
        }
        $holder = $this->containerDefinition($class, $container, $type);
        if ($holder === null) {
            return sprintf('"%s": the %s definition "%s" does not exist.', $field, $containerDefinition instanceof Data\Objectbricks ? 'object brick' : 'field collection', $type);
        }
        if (($this->topLevelOf($holder, $name) ?? $this->localizedChildOf($holder, $name)) === null) {
            return sprintf('"%s": field "%s" does not exist in "%s".', $field, $name, $type);
        }

        return null;
    }

    /**
     * Candidate values for the field: exactly one for top-level, localized and brick fields, one per
     * item for field collections, none when the container is not set.
     *
     * @return array<int, mixed>
     */
    public function readAll(Concrete $object, string $field, ?string $language): array
    {
        $path = $this->parse($field);
        if ($path === null) {
            return [$this->readFrom($object, $field, $language)];
        }

        [$container, $type, $name] = $path;
        $value = $object->get($container);

        if ($value instanceof Objectbrick) {
            $getter = 'get' . ucfirst($type);
            $item = method_exists($value, $getter) ? $value->$getter() : null;
            if (!$item instanceof Objectbrick\Data\AbstractData || $item->getDoDelete()) {
                return [];
            }

            return [$this->readFrom($item, $name, $language)];
        }

        if ($value instanceof Fieldcollection) {
            $values = [];
            foreach ($value->getItems() as $item) {
                if ($item instanceof Fieldcollection\Data\AbstractData && $item->getType() === $type) {
                    $values[] = $this->readFrom($item, $name, $language);
                }
            }

            return $values;
        }

        return [];
    }

    public function topLevel(ClassDefinition $class, string $field): ?Data
    {
        return $this->topLevelOf($class, $field);
    }

    /**
     * @return array{0: string, 1: string, 2: string}|null container, type, field
     */
    public function parse(string $field): ?array
    {
        if (!str_contains($field, self::PATH_SEPARATOR)) {
            return null;
        }

        $parts = explode(self::PATH_SEPARATOR, $field);
        if (count($parts) !== 3 || in_array('', $parts, true)) {
            return null;
        }

        return [$parts[0], $parts[1], $parts[2]];
    }

    /**
     * @param Concrete|Objectbrick\Data\AbstractData|Fieldcollection\Data\AbstractData $holder
     */
    private function readFrom(object $holder, string $field, ?string $language): mixed
    {
        if ($language === null) {
            return $holder->get($field);
        }

        $localized = $holder->get(self::LOCALIZED_CONTAINER);
        if (!$localized instanceof Localizedfield) {
            return null;
        }

        return $localized->getLocalizedValue($field, $language, true);
    }

    private function containerDefinition(ClassDefinition $class, string $container, string $type): Objectbrick\Definition|Fieldcollection\Definition|null
    {
        $definition = $this->topLevel($class, $container);
        if ($definition instanceof Data\Objectbricks && in_array($type, $definition->getAllowedTypes(), true)) {
            return $this->loadBrickDefinition($type);
        }
        if ($definition instanceof Data\Fieldcollections && in_array($type, $definition->getAllowedTypes(), true)) {
            return $this->loadCollectionDefinition($type);
        }

        return null;
    }

    protected function loadBrickDefinition(string $type): ?Objectbrick\Definition
    {
        try {
            return Objectbrick\Definition::getByKey($type);
        } catch (\Throwable) {
            return null;
        }
    }

    protected function loadCollectionDefinition(string $type): ?Fieldcollection\Definition
    {
        try {
            return Fieldcollection\Definition::getByKey($type);
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * The definition that holds the field (the class itself, or the brick / collection definition
     * of a nested path) together with the plain field name; null when a nested path cannot be resolved.
     *
     * @return array{0: ClassDefinition|Objectbrick\Definition|Fieldcollection\Definition, 1: string}|null
     */
    private function holder(ClassDefinition $class, string $field): ?array
    {
        $path = $this->parse($field);
        if ($path === null) {
            return [$class, $field];
        }

        $definition = $this->containerDefinition($class, $path[0], $path[1]);

        return $definition === null ? null : [$definition, $path[2]];
    }

    /**
     * @param ClassDefinition|Objectbrick\Definition|Fieldcollection\Definition $definition
     */
    private function topLevelOf(object $definition, string $field): ?Data
    {
        $data = $definition->getFieldDefinitions()[$field] ?? null;

        return $data instanceof Data ? $data : null;
    }

    /**
     * @param ClassDefinition|Objectbrick\Definition|Fieldcollection\Definition $definition
     */
    private function localizedChildOf(object $definition, string $field): ?Data
    {
        $container = $definition->getFieldDefinitions()[self::LOCALIZED_CONTAINER] ?? null;
        if (!$container instanceof Data\Localizedfields) {
            return null;
        }

        $data = $container->getFieldDefinition($field);

        return $data instanceof Data ? $data : null;
    }
}
