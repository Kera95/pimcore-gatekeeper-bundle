<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Service\Emptiness\Resolver;

use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Data\AbstractQuantityValue;
use Tsf\GatekeeperBundle\Service\Emptiness\EmptinessResolverInterface;

/**
 * A quantity is filled when it has a value; 0 is a value, a unit without a value is not.
 */
final class QuantityValueResolver implements EmptinessResolverInterface
{
    public function supports(Data $fieldDefinition): bool
    {
        return $fieldDefinition instanceof Data\AbstractQuantityValue;
    }

    public function isEmpty(Data $fieldDefinition, mixed $value): bool
    {
        if (!$value instanceof AbstractQuantityValue) {
            return true;
        }

        $number = $value->getValue();

        return $number === null || $number === '';
    }
}
