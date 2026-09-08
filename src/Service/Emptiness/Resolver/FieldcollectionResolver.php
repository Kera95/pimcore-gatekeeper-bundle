<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Service\Emptiness\Resolver;

use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Fieldcollection;
use Tsf\GatekeeperBundle\Service\Emptiness\EmptinessResolverInterface;

use function count;

final class FieldcollectionResolver implements EmptinessResolverInterface
{
    public function supports(Data $fieldDefinition): bool
    {
        return $fieldDefinition instanceof Data\Fieldcollections;
    }

    public function isEmpty(Data $fieldDefinition, mixed $value): bool
    {
        return !$value instanceof Fieldcollection || count($value->getItems()) === 0;
    }
}
