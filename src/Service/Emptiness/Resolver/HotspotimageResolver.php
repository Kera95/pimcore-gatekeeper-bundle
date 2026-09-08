<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Service\Emptiness\Resolver;

use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Data\Hotspotimage;
use Tsf\GatekeeperBundle\Service\Emptiness\EmptinessResolverInterface;

final class HotspotimageResolver implements EmptinessResolverInterface
{
    public function supports(Data $fieldDefinition): bool
    {
        return $fieldDefinition instanceof Data\Hotspotimage;
    }

    public function isEmpty(Data $fieldDefinition, mixed $value): bool
    {
        return !$value instanceof Hotspotimage || $value->getImage() === null;
    }
}
