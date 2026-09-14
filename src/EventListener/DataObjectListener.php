<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\EventListener;

use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Model\DataObject\Concrete;
use Pimcore\Model\Element\ValidationException;
use Psr\Log\LoggerInterface;
use Tsf\GatekeeperBundle\Model\ClassRule;
use Tsf\GatekeeperBundle\Model\Evaluation;
use Tsf\GatekeeperBundle\Model\Gate;
use Tsf\GatekeeperBundle\Service\Config\RuleSet;
use Tsf\GatekeeperBundle\Service\Evaluator;
use Tsf\GatekeeperBundle\Service\Report\AssetWriter;
use Tsf\GatekeeperBundle\Service\ResultStore;
use Tsf\GatekeeperBundle\Service\ScoreFieldWriter;
use WeakMap;

use function sprintf;

/**
 * preAdd/preUpdate: evaluate, mirror the score into the score field, apply the gate.
 * postAdd/postUpdate: persist the rows (needs the id). postDelete: remove the rows.
 *
 * The evaluation travels from pre to post in a WeakMap keyed by the object, so nested or
 * concurrent saves in one request cannot mix up. Nothing here ever calls save().
 *
 * Trusted tooling that fills fields in steps can pass SKIP_GATE_PARAMETER to save() so a published
 * object that is still incomplete afterwards is saved anyway: the score, the score field and the
 * rows are still updated, only the warn/block step is skipped for that one save.
 *
 * @api
 */
class DataObjectListener
{
    /**
     * `$object->save([DataObjectListener::SKIP_GATE_PARAMETER => true])`
     */
    public const SKIP_GATE_PARAMETER = 'tsf_gatekeeper_skip_gate';

    /**
     * @var WeakMap<Concrete, Evaluation>
     */
    private WeakMap $pending;

    private bool $assetsWritten = false;

    /**
     * @param array{enabled: bool, on_save: bool} $assetConfig
     */
    public function __construct(
        private readonly RuleSet $rules,
        private readonly Evaluator $evaluator,
        private readonly ResultStore $store,
        private readonly ScoreFieldWriter $scoreFieldWriter,
        private readonly AssetWriter $assetWriter,
        private readonly LoggerInterface $logger,
        private readonly bool $enabled = true,
        private readonly array $assetConfig = ['enabled' => false, 'on_save' => false],
    ) {
        $this->pending = new WeakMap();
    }

    /**
     * @throws ValidationException when the gate is "block" and a published object is incomplete
     */
    public function onPreSave(DataObjectEvent $event): void
    {
        $object = $event->getObject();
        $rule = $this->ruleFor($object);
        if ($rule === null || !$object instanceof Concrete) {
            return;
        }

        try {
            $evaluation = $this->evaluator->evaluate($object, $rule);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Gatekeeper: evaluation of %s #%s failed: %s', $rule->getClassName(), $object->getId() ?? 'new', $e->getMessage()), ['exception' => $e]);

            return;
        }

        $this->pending[$object] = $evaluation;

        if ($rule->getScoreField() !== null) {
            try {
                $this->scoreFieldWriter->write($object, $rule->getScoreField(), $evaluation->getAggregateScore());
            } catch (\Throwable $e) {
                $this->logger->error(sprintf('Gatekeeper: could not write score field "%s" on %s: %s', $rule->getScoreField(), $rule->getClassName(), $e->getMessage()), ['exception' => $e]);
            }
        }

        if ($event->hasArgument(self::SKIP_GATE_PARAMETER) && $event->getArgument(self::SKIP_GATE_PARAMETER)) {
            if (!$evaluation->isPassed() && $object->isPublished() && $rule->getGate() !== Gate::Off) {
                $this->logger->info(sprintf('Gatekeeper: gate skipped on request for %s "%s" (%s).', $rule->getClassName(), $object->getKey() ?? $object->getId() ?? 'new', $evaluation->describeFailures()), ['object_id' => $object->getId()]);
            }

            return;
        }

        $this->applyGate($object, $rule, $evaluation);
    }

    public function onPostSave(DataObjectEvent $event): void
    {
        $object = $event->getObject();
        if (!$object instanceof Concrete || !isset($this->pending[$object])) {
            return;
        }

        $evaluation = $this->pending[$object];
        unset($this->pending[$object]);

        $id = $object->getId();
        if ($id === null) {
            return;
        }

        try {
            $this->store->save($id, $evaluation);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Gatekeeper: could not store the result of object #%d: %s', $id, $e->getMessage()), ['exception' => $e]);

            return;
        }

        if (($this->assetConfig['enabled'] ?? false) && ($this->assetConfig['on_save'] ?? false) && !$this->assetsWritten) {
            $this->assetsWritten = true;
            try {
                $this->assetWriter->writeAll();
            } catch (\Throwable $e) {
                $this->logger->error('Gatekeeper: report asset export after save failed: ' . $e->getMessage(), ['exception' => $e]);
            }
        }
    }

    public function onPostDelete(DataObjectEvent $event): void
    {
        $object = $event->getObject();
        $id = $object->getId();
        if (!$object instanceof Concrete || $id === null || $this->ruleFor($object) === null) {
            return;
        }

        try {
            $this->store->delete($id);
        } catch (\Throwable $e) {
            $this->logger->error(sprintf('Gatekeeper: could not delete the results of object #%d: %s', $id, $e->getMessage()), ['exception' => $e]);
        }
    }

    private function ruleFor(object $object): ?ClassRule
    {
        if (!$this->enabled || !$object instanceof Concrete) {
            return null;
        }

        $className = $object->getClassName();

        return $className === null ? null : $this->rules->getEnabled($className);
    }

    /**
     * @throws ValidationException
     */
    private function applyGate(Concrete $object, ClassRule $rule, Evaluation $evaluation): void
    {
        if ($rule->getGate() === Gate::Off || $evaluation->isPassed() || !$object->isPublished()) {
            return;
        }

        $message = sprintf(
            'Completeness gate: %s "%s" is incomplete. %s',
            $rule->getClassName(),
            $object->getKey() ?? $object->getId() ?? 'new',
            $evaluation->describeFailures()
        );

        if ($rule->getGate() === Gate::Block) {
            throw new ValidationException($message);
        }

        $this->logger->warning($message, ['object_id' => $object->getId()]);
    }
}
