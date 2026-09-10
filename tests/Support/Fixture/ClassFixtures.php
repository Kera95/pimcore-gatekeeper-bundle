<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Tests\Support\Fixture;

use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\ClassDefinition\Layout;
use Pimcore\Model\DataObject\Fieldcollection;
use Pimcore\Model\DataObject\Objectbrick;

/**
 * The DataObject classes the functional suite works with, created programmatically in the test
 * database. Their names carry a "Gk" prefix so they never collide with classes of a surrounding
 * project when the suite runs from inside one.
 *
 *  - GkCategory: name, localized title
 *  - GkFeature (field collection): label
 *  - GkDimensions (object brick on GkProduct.bricks): width, height
 *  - GkProduct: sku, name, localized title + description, completeness (score field),
 *    features (GkFeature), bricks (GkDimensions)
 */
final class ClassFixtures
{
    public const CATEGORY = 'GkCategory';

    public const PRODUCT = 'GkProduct';

    public const FEATURE = 'GkFeature';

    public const DIMENSIONS = 'GkDimensions';

    public static function create(): void
    {
        if (ClassDefinition::getByName(self::CATEGORY) === null) {
            self::createClass(self::CATEGORY, [
                self::input('name'),
                self::localized([self::input('title')]),
            ]);
        }

        if (Fieldcollection\Definition::getByKey(self::FEATURE) === null) {
            $definition = new Fieldcollection\Definition();
            $definition->setKey(self::FEATURE);
            $definition->setLayoutDefinitions(self::panel([self::input('label')]));
            $definition->save();
        }

        if (ClassDefinition::getByName(self::PRODUCT) === null) {
            $features = new Data\Fieldcollections();
            $features->setName('features');
            $features->setTitle('Features');
            $features->setAllowedTypes([self::FEATURE]);

            $bricks = new Data\Objectbricks();
            $bricks->setName('bricks');
            $bricks->setTitle('Bricks');

            $score = new Data\Numeric();
            $score->setName('completeness');
            $score->setTitle('Completeness %');
            $score->setInteger(true);

            self::createClass(self::PRODUCT, [
                self::input('sku'),
                self::input('name'),
                self::localized([self::input('title'), self::textarea('description')]),
                $score,
                $features,
                $bricks,
            ]);
        }

        if (Objectbrick\Definition::getByKey(self::DIMENSIONS) === null) {
            $definition = new Objectbrick\Definition();
            $definition->setKey(self::DIMENSIONS);
            $definition->setLayoutDefinitions(self::panel([self::numeric('width'), self::numeric('height')]));
            $definition->setClassDefinitions([['classname' => self::PRODUCT, 'fieldname' => 'bricks']]);
            $definition->save();
        }
    }

    /**
     * @param Data[] $fields
     */
    private static function createClass(string $name, array $fields): void
    {
        $class = new ClassDefinition();
        $class->setName($name);
        $class->setId($name);
        $class->setUserOwner(1);
        $class->setUserModification(1);
        $class->setLayoutDefinitions(self::panel($fields));
        $class->save();
    }

    /**
     * @param Data[]|Layout[] $children
     */
    private static function panel(array $children): Layout\Panel
    {
        $panel = new Layout\Panel();
        $panel->setName('pimcore_root');
        $panel->setChildren($children);

        return $panel;
    }

    /**
     * @param Data[] $children
     */
    private static function localized(array $children): Data\Localizedfields
    {
        $localized = new Data\Localizedfields();
        $localized->setName('localizedfields');
        $localized->setTitle('Localized');
        $localized->setChildren($children);

        return $localized;
    }

    private static function input(string $name): Data\Input
    {
        $field = new Data\Input();
        $field->setName($name);
        $field->setTitle(ucfirst($name));

        return $field;
    }

    private static function textarea(string $name): Data\Textarea
    {
        $field = new Data\Textarea();
        $field->setName($name);
        $field->setTitle(ucfirst($name));

        return $field;
    }

    private static function numeric(string $name): Data\Numeric
    {
        $field = new Data\Numeric();
        $field->setName($name);
        $field->setTitle(ucfirst($name));

        return $field;
    }
}
