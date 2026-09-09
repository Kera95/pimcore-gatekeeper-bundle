<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Tests\Unit\Service;

use Codeception\Test\Unit;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\ClassDefinition\Layout\Panel;
use Pimcore\Model\DataObject\Fieldcollection;
use Pimcore\Model\DataObject\Objectbrick;
use Tsf\GatekeeperBundle\Service\FieldReader;
use Tsf\GatekeeperBundle\Tests\Support\ObjectStub;

final class FieldReaderTest extends Unit
{
    private FieldReader $reader;

    private ClassDefinition $class;

    protected function _before(): void
    {
        $this->class = new ClassDefinition();
        $this->class->setName('Product');
        $this->class->setLayoutDefinitions(self::layout([
            self::field(Data\Input::class, 'sku'),
            self::field(Data\Objectbricks::class, 'bricks', ['allowedTypes' => ['Dimensions']]),
            self::field(Data\Fieldcollections::class, 'features', ['allowedTypes' => ['Feature']]),
        ]));

        $brick = new Objectbrick\Definition();
        $brick->setKey('Dimensions');
        $brick->setLayoutDefinitions(self::layout([self::field(Data\Numeric::class, 'width')]));

        $collection = new Fieldcollection\Definition();
        $collection->setKey('Feature');
        $collection->setLayoutDefinitions(self::layout([self::field(Data\Input::class, 'label')]));

        $this->reader = new class($brick, $collection) extends FieldReader {
            public function __construct(private readonly Objectbrick\Definition $brick, private readonly Fieldcollection\Definition $collection)
            {
            }

            protected function loadBrickDefinition(string $type): ?Objectbrick\Definition
            {
                return $type === $this->brick->getKey() ? $this->brick : null;
            }

            protected function loadCollectionDefinition(string $type): ?Fieldcollection\Definition
            {
                return $type === $this->collection->getKey() ? $this->collection : null;
            }
        };
    }

    public function testTopLevelFieldsResolve(): void
    {
        self::assertInstanceOf(Data\Input::class, $this->reader->getDefinition($this->class, 'sku'));
        self::assertFalse($this->reader->isLocalized($this->class, 'sku'));
        self::assertNull($this->reader->getDefinition($this->class, 'nope'));
        self::assertNull($this->reader->describeProblem($this->class, 'sku'));
        self::assertSame('field "nope" does not exist on the class (top-level or localized).', $this->reader->describeProblem($this->class, 'nope'));
    }

    public function testBrickFieldsResolveThroughTheBrickDefinition(): void
    {
        self::assertInstanceOf(Data\Numeric::class, $this->reader->getDefinition($this->class, 'bricks.Dimensions.width'));
        self::assertFalse($this->reader->isLocalized($this->class, 'bricks.Dimensions.width'));
        self::assertNull($this->reader->describeProblem($this->class, 'bricks.Dimensions.width'));
    }

    public function testCollectionFieldsResolveThroughTheCollectionDefinition(): void
    {
        self::assertInstanceOf(Data\Input::class, $this->reader->getDefinition($this->class, 'features.Feature.label'));
        self::assertNull($this->reader->describeProblem($this->class, 'features.Feature.label'));
    }

    /**
     * @dataProvider badPaths
     */
    public function testBadPathsAreExplained(string $path, string $messagePart): void
    {
        self::assertNull($this->reader->getDefinition($this->class, $path));
        self::assertStringContainsString($messagePart, (string) $this->reader->describeProblem($this->class, $path));
    }

    /**
     * @return iterable<string, array{0: string, 1: string}>
     */
    public static function badPaths(): iterable
    {
        yield 'two parts' => ['bricks.width', 'not a valid path'];
        yield 'empty part' => ['bricks..width', 'not a valid path'];
        yield 'container missing' => ['nope.Dimensions.width', '"nope" is not an object bricks or field collections field'];
        yield 'container wrong type' => ['sku.Dimensions.width', '"sku" is not an object bricks or field collections field'];
        yield 'type not allowed' => ['bricks.Other.width', 'type "Other" is not allowed in "bricks" (allowed: Dimensions)'];
        yield 'field missing in brick' => ['bricks.Dimensions.depth', 'field "depth" does not exist in "Dimensions"'];
        yield 'localizedfields container' => ['localizedfields', 'list the localized fields by name'];
    }

    public function testReadAllReturnsOneValueForTopLevelFields(): void
    {
        $object = (new ObjectStub('Product', 2))->withValue('sku', 'ABC');

        self::assertSame(['ABC'], $this->reader->readAll($object, 'sku', null));
        self::assertSame([null], $this->reader->readAll($object, 'missing', null));
    }

    public function testReadAllReadsTheBrickValueAndSkipsDeletedOrMissingBricks(): void
    {
        $object = new ObjectStub('Product', 2);
        self::assertSame([], $this->reader->readAll($object, 'bricks.Dimensions.width', null), 'container not set');

        $container = self::brickContainer($object, null);
        $object->withValue('bricks', $container);
        self::assertSame([], $this->reader->readAll($object, 'bricks.Dimensions.width', null), 'brick not set');

        $brick = self::brickItem('35.0', false);
        $object->withValue('bricks', self::brickContainer($object, $brick));
        self::assertSame(['35.0'], $this->reader->readAll($object, 'bricks.Dimensions.width', null));

        $object->withValue('bricks', self::brickContainer($object, self::brickItem('35.0', true)));
        self::assertSame([], $this->reader->readAll($object, 'bricks.Dimensions.width', null), 'brick marked for deletion');
    }

    public function testReadAllReturnsOneValuePerCollectionItemOfTheType(): void
    {
        $object = new ObjectStub('Product', 2);
        self::assertSame([], $this->reader->readAll($object, 'features.Feature.label', null));

        $object->withValue('features', new Fieldcollection([
            self::collectionItem('Feature', 'Backlit'),
            self::collectionItem('Other', 'ignored'),
            self::collectionItem('Feature', ''),
        ]));

        self::assertSame(['Backlit', ''], $this->reader->readAll($object, 'features.Feature.label', null));
    }

    /**
     * @param Data[] $fields
     */
    private static function layout(array $fields): Panel
    {
        $panel = new Panel();
        $panel->setName('pimcore_root');
        $panel->setChildren($fields);

        return $panel;
    }

    /**
     * @param class-string<Data> $type
     * @param array<string, mixed> $props
     */
    private static function field(string $type, string $name, array $props = []): Data
    {
        $field = new $type();
        $field->setName($name);
        foreach ($props as $key => $value) {
            // assign directly: setAllowedTypes() loads the brick/collection definitions from disk
            $field->$key = $value;
        }

        return $field;
    }

    private static function brickItem(?string $width, bool $doDelete): Objectbrick\Data\AbstractData
    {
        $item = new class() extends Objectbrick\Data\AbstractData {
            public ?string $width = null;

            public function __construct()
            {
            }

            public function get(string $fieldName, ?string $language = null): mixed
            {
                return $fieldName === 'width' ? $this->width : null;
            }
        };
        $item->width = $width;
        $item->setDoDelete($doDelete);

        return $item;
    }

    private static function brickContainer(ObjectStub $object, ?Objectbrick\Data\AbstractData $dimensions): Objectbrick
    {
        return new class($object, 'bricks', $dimensions) extends Objectbrick {
            public function __construct(ObjectStub $object, string $fieldname, private readonly ?Objectbrick\Data\AbstractData $dimensions)
            {
                parent::__construct($object, $fieldname);
            }

            public function getDimensions(): ?Objectbrick\Data\AbstractData
            {
                return $this->dimensions;
            }
        };
    }

    private static function collectionItem(string $type, string $label): Fieldcollection\Data\AbstractData
    {
        $item = new class() extends Fieldcollection\Data\AbstractData {
            public string $itemType = '';

            public string $label = '';

            public function getType(): string
            {
                return $this->itemType;
            }

            public function get(string $fieldName, ?string $language = null): mixed
            {
                return $fieldName === 'label' ? $this->label : null;
            }
        };
        $item->itemType = $type;
        $item->label = $label;

        return $item;
    }
}
