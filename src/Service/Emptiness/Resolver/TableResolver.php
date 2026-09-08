<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Service\Emptiness\Resolver;

use Pimcore\Model\DataObject\ClassDefinition\Data;
use Tsf\GatekeeperBundle\Service\Emptiness\EmptinessResolverInterface;

use function is_array;
use function is_scalar;

/**
 * A table is empty when every cell is blank; the editor always stores at least one blank row.
 */
final class TableResolver implements EmptinessResolverInterface
{
    public function supports(Data $fieldDefinition): bool
    {
        return $fieldDefinition instanceof Data\Table;
    }

    public function isEmpty(Data $fieldDefinition, mixed $value): bool
    {
        if (!is_array($value)) {
            return true;
        }

        foreach ($value as $row) {
            foreach (is_array($row) ? $row : [$row] as $cell) {
                if (is_scalar($cell) && trim((string) $cell) !== '') {
                    return false;
                }
            }
        }

        return true;
    }
}
