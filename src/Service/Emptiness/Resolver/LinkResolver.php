<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Service\Emptiness\Resolver;

use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Data\Link;
use Tsf\GatekeeperBundle\Service\Emptiness\EmptinessResolverInterface;

final class LinkResolver implements EmptinessResolverInterface
{
    public function supports(Data $fieldDefinition): bool
    {
        return $fieldDefinition instanceof Data\Link;
    }

    public function isEmpty(Data $fieldDefinition, mixed $value): bool
    {
        if (!$value instanceof Link) {
            return true;
        }

        // A link without a target is not a link, whatever else is set (text, title, target)
        return trim((string) $value->getDirect()) === '' && ($value->getInternal() === null || $value->getInternal() === '');
    }
}
