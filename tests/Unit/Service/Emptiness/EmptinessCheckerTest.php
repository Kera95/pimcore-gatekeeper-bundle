<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Tests\Unit\Service\Emptiness;

use Codeception\Test\Unit;
use Pimcore\Model\Asset;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Data\Hotspotimage;
use Pimcore\Model\DataObject\Data\Link;
use Pimcore\Model\DataObject\Data\QuantityValue;
use Pimcore\Model\DataObject\QuantityValue\Unit as QuantityUnit;
use Pimcore\Model\DataObject\Data\Video;
use Pimcore\Model\DataObject\Fieldcollection;
use Pimcore\Model\DataObject\Objectbrick;
use Tsf\GatekeeperBundle\Service\Emptiness\EmptinessChecker;
use Tsf\GatekeeperBundle\Service\Emptiness\EmptinessResolverInterface;
use Tsf\GatekeeperBundle\Service\Emptiness\Resolver\FieldcollectionResolver;
use Tsf\GatekeeperBundle\Service\Emptiness\Resolver\HotspotimageResolver;
use Tsf\GatekeeperBundle\Service\Emptiness\Resolver\LinkResolver;
use Tsf\GatekeeperBundle\Service\Emptiness\Resolver\ObjectbrickResolver;
use Tsf\GatekeeperBundle\Service\Emptiness\Resolver\QuantityValueResolver;
use Tsf\GatekeeperBundle\Service\Emptiness\Resolver\StringResolver;
use Tsf\GatekeeperBundle\Service\Emptiness\Resolver\TableResolver;
use Tsf\GatekeeperBundle\Service\Emptiness\Resolver\VideoResolver;
use Tsf\GatekeeperBundle\Tests\Support\ObjectStub;

final class EmptinessCheckerTest extends Unit
{
    private EmptinessChecker $checker;

    protected function _before(): void
    {
        $this->checker = new EmptinessChecker([
            new StringResolver(),
            new QuantityValueResolver(),
            new HotspotimageResolver(),
            new VideoResolver(),
            new LinkResolver(),
            new TableResolver(),
            new FieldcollectionResolver(),
            new ObjectbrickResolver(),
        ]);
    }

    /**
     * @dataProvider values
     */
    public function testEmptiness(Data $definition, mixed $value, bool $expectedFilled): void
    {
        self::assertSame($expectedFilled, $this->checker->isFilled($definition, $value));
        self::assertSame(!$expectedFilled, $this->checker->isEmpty($definition, $value));
    }

    /**
     * @return iterable<string, array{0: Data, 1: mixed, 2: bool}>
     */
    public static function values(): iterable
    {
        // strings: "0" and whitespace handling differ from PHP's empty()
        yield 'input null' => [new Data\Input(), null, false];
        yield 'input empty' => [new Data\Input(), '', false];
        yield 'input blank' => [new Data\Input(), "  \t", false];
        yield 'input zero string' => [new Data\Input(), '0', true];
        yield 'input text' => [new Data\Input(), 'abc', true];
        yield 'email zero' => [new Data\Email(), '0', true];
        yield 'textarea zero' => [new Data\Textarea(), '0', true];
        yield 'wysiwyg empty paragraph' => [new Data\Wysiwyg(), '<p></p>', false];
        yield 'wysiwyg nbsp' => [new Data\Wysiwyg(), '<p>&nbsp;</p>', false];
        yield 'wysiwyg text' => [new Data\Wysiwyg(), '<p>Hi</p>', true];

        // numbers and booleans: 0 and false are values
        yield 'numeric zero' => [new Data\Numeric(), 0, true];
        yield 'numeric zero string' => [new Data\Numeric(), '0.00', true];
        yield 'numeric null' => [new Data\Numeric(), null, false];
        yield 'numeric non numeric' => [new Data\Numeric(), 'abc', false];
        yield 'checkbox false' => [new Data\Checkbox(), false, true];
        yield 'checkbox null' => [new Data\Checkbox(), null, false];

        // select and multiselect
        yield 'select empty' => [new Data\Select(), '', false];
        yield 'select value' => [new Data\Select(), 'simple', true];
        yield 'multiselect empty' => [new Data\Multiselect(), [], false];
        yield 'multiselect values' => [new Data\Multiselect(), ['a'], true];

        // quantity: value decides, unit alone is nothing
        yield 'quantity zero' => [new Data\QuantityValue(), new QuantityValue(0), true];
        yield 'quantity value' => [new Data\QuantityValue(), new QuantityValue(12.5, self::unit()), true];
        yield 'quantity unit only' => [new Data\QuantityValue(), new QuantityValue(null, self::unit()), false];
        yield 'quantity empty' => [new Data\QuantityValue(), new QuantityValue(), false];
        yield 'input quantity zero' => [new Data\InputQuantityValue(), new QuantityValue('0'), true];

        // relations
        yield 'relation none' => [new Data\ManyToManyObjectRelation(), [], false];
        yield 'relation one' => [new Data\ManyToManyObjectRelation(), [new ObjectStub('Category', 1)], true];
        yield 'single relation null' => [new Data\ManyToOneRelation(), null, false];
        yield 'single relation object' => [new Data\ManyToOneRelation(), new ObjectStub('Category', 1), true];

        // media
        yield 'image null' => [new Data\Image(), null, false];
        yield 'image asset' => [new Data\Image(), new Asset\Image(), true];
        yield 'hotspot without image' => [new Data\Hotspotimage(), new Hotspotimage(), false];
        yield 'hotspot with image' => [new Data\Hotspotimage(), new Hotspotimage(new Asset\Image()), true];
        yield 'video without data' => [new Data\Video(), new Video(), false];
        yield 'video youtube' => [new Data\Video(), self::video('youtube', 'abc'), true];
        yield 'video empty data' => [new Data\Video(), self::video('youtube', ''), false];

        // link: needs a target
        yield 'link empty object' => [new Data\Link(), new Link(), false];
        yield 'link text only' => [new Data\Link(), self::link(null, 'Read more'), false];
        yield 'link direct' => [new Data\Link(), self::link('https://example.com', null), true];
        yield 'link internal' => [new Data\Link(), self::internalLink(42), true];

        // table: at least one non-blank cell
        yield 'table blank row' => [new Data\Table(), [['', '']], false];
        yield 'table blank rows' => [new Data\Table(), [[' ', ''], ['', null]], false];
        yield 'table cell' => [new Data\Table(), [['', 'x']], true];
        yield 'table zero cell' => [new Data\Table(), [['0']], true];
        yield 'table not array' => [new Data\Table(), 'x', false];

        // containers
        yield 'fieldcollection empty' => [new Data\Fieldcollections(), new Fieldcollection(), false];
        yield 'fieldcollection item' => [new Data\Fieldcollections(), new Fieldcollection([self::fieldcollectionItem()]), true];
        yield 'objectbrick empty' => [new Data\Objectbricks(), new Objectbrick(new ObjectStub('Product'), 'bricks'), false];
        yield 'objectbrick with brick' => [new Data\Objectbricks(), self::brickContainer(false), true];
        yield 'objectbrick marked for deletion' => [new Data\Objectbricks(), self::brickContainer(true), false];
        yield 'objectbrick wrong type' => [new Data\Objectbricks(), 'x', false];
    }

    public function testCustomResolverWinsOverTheDefaults(): void
    {
        $custom = new class() implements EmptinessResolverInterface {
            public function supports(Data $fieldDefinition): bool
            {
                return $fieldDefinition instanceof Data\Input;
            }

            public function isEmpty(Data $fieldDefinition, mixed $value): bool
            {
                return $value === 'n/a';
            }
        };
        $checker = new EmptinessChecker(new \ArrayIterator([$custom, new StringResolver()]));

        self::assertFalse($checker->isFilled(new Data\Input(), 'n/a'));
        self::assertTrue($checker->isFilled(new Data\Input(), '   '));
    }

    public function testWithoutResolversTheCoreDefinitionDecides(): void
    {
        $checker = new EmptinessChecker([]);

        self::assertFalse($checker->isFilled(new Data\Input(), ''));
        self::assertTrue($checker->isFilled(new Data\Input(), 'x'));
        self::assertTrue($checker->isFilled(new Data\Numeric(), 0));
        self::assertFalse($checker->isFilled(new Data\Numeric(), null));
    }

    private static function unit(): QuantityUnit
    {
        $unit = new QuantityUnit();
        $unit->setId('1');
        $unit->setAbbreviation('kg');

        return $unit;
    }

    private static function video(string $type, string $data): Video
    {
        $video = new Video();
        $video->setType($type);
        $video->setData($data);

        return $video;
    }

    private static function link(?string $direct, ?string $text): Link
    {
        $link = new Link();
        if ($direct !== null) {
            $link->setDirect($direct);
        }
        if ($text !== null) {
            $link->setText($text);
        }

        return $link;
    }

    private static function internalLink(int $id): Link
    {
        $link = new Link();
        $link->setInternalType('document');
        $link->setInternal($id);

        return $link;
    }

    private static function fieldcollectionItem(): Fieldcollection\Data\AbstractData
    {
        return new class() extends Fieldcollection\Data\AbstractData {
        };
    }

    private static function brickContainer(bool $doDelete): Objectbrick
    {
        $brick = new class() extends Objectbrick\Data\AbstractData {
            public function __construct()
            {
            }
        };
        $brick->setDoDelete($doDelete);

        $container = new class(new ObjectStub('Product'), 'bricks') extends Objectbrick {
            public ?Objectbrick\Data\AbstractData $dimensions = null;

            public function getItems(bool $withInheritedValues = false): array
            {
                return $this->dimensions === null ? [] : [$this->dimensions];
            }
        };
        $container->dimensions = $brick;

        return $container;
    }
}
