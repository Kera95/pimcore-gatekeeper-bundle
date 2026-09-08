<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Tests\Support;

use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\Concrete;

/**
 * A Concrete object that never touches the database: class name, id, key and published state are
 * given, the class definition is an empty in-memory one (field lookups go through mocked readers).
 */
final class ObjectStub extends Concrete
{
    private ClassDefinition $classDefinition;

    /**
     * @var array<string, mixed>
     */
    private array $values = [];

    public function __construct(string $className, ?int $id = null, ?string $key = null, bool $published = true)
    {
        $this->className = $className;
        $this->id = $id;
        $this->key = $key;
        $this->published = $published;
        $this->classDefinition = new ClassDefinition();
        $this->classDefinition->setName($className);
    }

    public function getClass(): ClassDefinition
    {
        return $this->classDefinition;
    }

    public function getClassName(): ?string
    {
        return $this->className;
    }

    public function withValue(string $field, mixed $value): self
    {
        $this->values[$field] = $value;

        return $this;
    }

    public function get(string $fieldName, ?string $language = null): mixed
    {
        return $this->values[$fieldName] ?? null;
    }

    public function __call(string $method, array $args): mixed
    {
        if (str_starts_with($method, 'set')) {
            $this->values[lcfirst(substr($method, 3))] = $args[0] ?? null;

            return $this;
        }
        if (str_starts_with($method, 'get')) {
            return $this->values[lcfirst(substr($method, 3))] ?? null;
        }

        throw new \BadMethodCallException($method);
    }
}
