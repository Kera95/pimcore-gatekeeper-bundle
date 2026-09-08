<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Service\Emptiness\Resolver;

use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Objectbrick;
use Tsf\GatekeeperBundle\Service\Emptiness\EmptinessResolverInterface;

/**
 * The brick container is filled when at least one brick is set on it.
 */
final class ObjectbrickResolver implements EmptinessResolverInterface
{
    public function supports(Data $fieldDefinition): bool
    {
        return $fieldDefinition instanceof Data\Objectbricks;
    }

    public function isEmpty(Data $fieldDefinition, mixed $value): bool
    {
        if (!$value instanceof Objectbrick) {
            return true;
        }

        foreach ($value->getItems() as $item) {
            if ($item instanceof Objectbrick\Data\AbstractData && !$item->getDoDelete()) {
                return false;
            }
        }

        return true;
    }
}
