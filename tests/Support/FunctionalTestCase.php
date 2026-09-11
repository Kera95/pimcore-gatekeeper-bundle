<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Tests\Support;

use Doctrine\DBAL\Connection;
use Pimcore;
use Pimcore\Console\Application;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\DataObject\Fieldcollection;
use Pimcore\Tests\Support\Test\TestCase;
use Symfony\Component\Console\Tester\CommandTester;
use Tsf\GatekeeperBundle\Service\ResultStore;
use Tsf\GatekeeperBundle\Tests\Support\Fixture\ClassFixtures;

/**
 * Base of the functional tests: a booted Pimcore kernel, a database that is emptied before every
 * test, the test classes from ClassFixtures and a few builders for objects, rows and commands.
 */
abstract class FunctionalTestCase extends TestCase
{
    protected function needsDb(): bool
    {
        return true;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection()->executeStatement('DELETE FROM `' . ResultStore::TABLE . '`');
    }

    protected function connection(): Connection
    {
        return Pimcore::getContainer()->get('database_connection');
    }

    /**
     * Private services are reachable through Symfony's test container
     */
    protected function service(string $id): object
    {
        return Pimcore::getContainer()->get('test.service_container')->get($id);
    }

    /**
     * Unsaved GkProduct. Values: sku, name, title (array language => value), description (array),
     * width (brick GkDimensions), features (array of labels).
     *
     * @param array<string, mixed> $values
     */
    protected function product(array $values = [], bool $published = true): Concrete
    {
        $class = 'Pimcore\\Model\\DataObject\\' . ClassFixtures::PRODUCT;
        /** @var Concrete $object */
        $object = new $class();
        $object->setParentId(1);
        $object->setUserOwner(1);
        $object->setUserModification(1);
        $object->setKey('product-' . uniqid());
        $object->setPublished($published);

        if (isset($values['sku'])) {
            $object->setSku($values['sku']);
        }
        if (isset($values['name'])) {
            $object->setName($values['name']);
        }
        foreach ($values['title'] ?? [] as $language => $title) {
            $object->setTitle($title, $language);
        }
        foreach ($values['description'] ?? [] as $language => $description) {
            $object->setDescription($description, $language);
        }
        if (isset($values['width'])) {
            $brickClass = 'Pimcore\\Model\\DataObject\\Objectbrick\\Data\\' . ClassFixtures::DIMENSIONS;
            $brick = new $brickClass($object);
            $brick->setWidth($values['width']);
            $object->getBricks()->{'set' . ClassFixtures::DIMENSIONS}($brick);
        }
        if (isset($values['features'])) {
            $itemClass = 'Pimcore\\Model\\DataObject\\Fieldcollection\\Data\\' . ClassFixtures::FEATURE;
            $collection = new Fieldcollection();
            foreach ($values['features'] as $label) {
                $item = new $itemClass();
                $item->setLabel($label);
                $collection->add($item);
            }
            $object->setFeatures($collection);
        }

        return $object;
    }

    /**
     * Everything a fully complete product needs for the default and the print profile
     *
     * @return array<string, mixed>
     */
    protected function completeValues(): array
    {
        return [
            'sku' => 'SKU-1',
            'name' => 'Headphones',
            'title' => ['en' => 'Headphones', 'de' => 'Kopfhörer'],
            'description' => ['en' => 'Over-ear, wireless.'],
            'width' => 12,
            'features' => ['Bluetooth'],
        ];
    }

    protected function category(?string $name, ?string $title = null, bool $published = true): Concrete
    {
        $class = 'Pimcore\\Model\\DataObject\\' . ClassFixtures::CATEGORY;
        /** @var Concrete $object */
        $object = new $class();
        $object->setParentId(1);
        $object->setUserOwner(1);
        $object->setUserModification(1);
        $object->setKey('category-' . uniqid());
        $object->setPublished($published);
        if ($name !== null) {
            $object->setName($name);
        }
        if ($title !== null) {
            $object->setTitle($title, 'en');
        }

        return $object;
    }

    /**
     * Result rows of one object keyed by "profile|language"
     *
     * @return array<string, array<string, mixed>>
     */
    protected function rows(int $objectId): array
    {
        $rows = $this->connection()->fetchAllAssociative(
            'SELECT * FROM `' . ResultStore::TABLE . '` WHERE object_id = :id ORDER BY profile, language',
            ['id' => $objectId]
        );

        $keyed = [];
        foreach ($rows as $row) {
            $keyed[$row['profile'] . '|' . $row['language']] = $row;
        }

        return $keyed;
    }

    /**
     * @param array<string, mixed> $input
     */
    protected function runCommand(string $name, array $input = []): CommandTester
    {
        $application = new Application(Pimcore::getKernel());
        $application->setAutoExit(false);

        $tester = new CommandTester($application->find($name));
        $tester->execute($input, ['interactive' => false]);

        return $tester;
    }
}
