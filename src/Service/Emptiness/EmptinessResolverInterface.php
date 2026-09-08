<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Service\Emptiness;

use Pimcore\Model\DataObject\ClassDefinition\Data;

/**
 * Extension point: decide whether a value counts as empty for a given field definition. Services
 * implementing this interface are tagged "tsf_gatekeeper.emptiness_resolver" automatically; the
 * first resolver that supports a field definition wins, the core Data::isEmpty() is the fallback.
 */
interface EmptinessResolverInterface
{
    public function supports(Data $fieldDefinition): bool;

    public function isEmpty(Data $fieldDefinition, mixed $value): bool;
}
