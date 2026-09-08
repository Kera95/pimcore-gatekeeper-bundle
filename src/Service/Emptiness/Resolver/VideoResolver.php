<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Service\Emptiness\Resolver;

use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Data\Video;
use Tsf\GatekeeperBundle\Service\Emptiness\EmptinessResolverInterface;

final class VideoResolver implements EmptinessResolverInterface
{
    public function supports(Data $fieldDefinition): bool
    {
        return $fieldDefinition instanceof Data\Video;
    }

    public function isEmpty(Data $fieldDefinition, mixed $value): bool
    {
        if (!$value instanceof Video) {
            return true;
        }

        $data = $value->getData();

        return $data === null || $data === '' || $data === 0;
    }
}
