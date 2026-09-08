<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Service;

use Pimcore\Model\DataObject\ClassDefinition\Data;
use Pimcore\Model\DataObject\Concrete;
use Psr\Log\LoggerInterface;

use function sprintf;

/**
 * Mirrors the aggregate score into the optional Numeric field of the class through the generated
 * setter, so the same save() versions and indexes it. A missing or wrong field is logged once per
 * class and never blocks the save.
 */
class ScoreFieldWriter
{
    /**
     * @var array<string, bool>
     */
    private array $warned = [];

    public function __construct(private readonly LoggerInterface $logger)
    {
    }

    public function write(Concrete $object, string $field, int $score): bool
    {
        $className = (string) $object->getClassName();
        $definition = $object->getClass()->getFieldDefinitions()[$field] ?? null;

        if (!$definition instanceof Data\Numeric) {
            if (!isset($this->warned[$className])) {
                $this->warned[$className] = true;
                $this->logger->warning(sprintf(
                    'Gatekeeper: score_field "%s" on class "%s" is %s; the score is not mirrored. Run tsf:gatekeeper:validate.',
                    $field,
                    $className,
                    $definition === null ? 'missing' : 'not a Numeric field'
                ));
            }

            return false;
        }

        $object->{'set' . ucfirst($field)}($score);

        return true;
    }
}
