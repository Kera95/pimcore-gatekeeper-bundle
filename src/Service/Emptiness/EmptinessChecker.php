<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Service\Emptiness;

use Pimcore\Model\DataObject\ClassDefinition\Data;

final class EmptinessChecker
{
    /**
     * @var EmptinessResolverInterface[]
     */
    private array $resolvers;

    /**
     * @param iterable<EmptinessResolverInterface> $resolvers
     */
    public function __construct(iterable $resolvers)
    {
        $this->resolvers = $resolvers instanceof \Traversable ? iterator_to_array($resolvers, false) : $resolvers;
    }

    public function isFilled(Data $fieldDefinition, mixed $value): bool
    {
        return !$this->isEmpty($fieldDefinition, $value);
    }

    public function isEmpty(Data $fieldDefinition, mixed $value): bool
    {
        if ($value === null) {
            return true;
        }

        foreach ($this->resolvers as $resolver) {
            if ($resolver->supports($fieldDefinition)) {
                return $resolver->isEmpty($fieldDefinition, $value);
            }
        }

        return $fieldDefinition->isEmpty($value);
    }
}
