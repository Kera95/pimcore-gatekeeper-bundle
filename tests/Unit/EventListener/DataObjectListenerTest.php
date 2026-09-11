<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Tests\Unit\EventListener;

use Codeception\Test\Unit;
use PHPUnit\Framework\MockObject\MockObject;
use Pimcore\Event\Model\DataObjectEvent;
use Pimcore\Model\DataObject\Folder;
use Pimcore\Model\Element\ValidationException;
use Psr\Log\AbstractLogger;
use Tsf\GatekeeperBundle\EventListener\DataObjectListener;
use Tsf\GatekeeperBundle\Model\ClassRule;
use Tsf\GatekeeperBundle\Model\Evaluation;
use Tsf\GatekeeperBundle\Model\Gate;
use Tsf\GatekeeperBundle\Model\Profile;
use Tsf\GatekeeperBundle\Model\Result;
use Tsf\GatekeeperBundle\Service\Config\RuleSet;
use Tsf\GatekeeperBundle\Service\Evaluator;
use Tsf\GatekeeperBundle\Service\Report\AssetWriter;
use Tsf\GatekeeperBundle\Service\ResultStore;
use Tsf\GatekeeperBundle\Service\ScoreFieldWriter;
use Tsf\GatekeeperBundle\Tests\Support\ObjectStub;

final class DataObjectListenerTest extends Unit
{
    private Evaluator&MockObject $evaluator;

    private ResultStore&MockObject $store;

    private ScoreFieldWriter&MockObject $scoreWriter;

    private AssetWriter&MockObject $assetWriter;

    /**
     * @var array<int, array{0: string, 1: string}>
     */
    private array $logs = [];

    protected function _before(): void
    {
        $this->evaluator = $this->createMock(Evaluator::class);
        $this->store = $this->createMock(ResultStore::class);
        $this->scoreWriter = $this->createMock(ScoreFieldWriter::class);
        $this->assetWriter = $this->createMock(AssetWriter::class);
        $this->logs = [];
    }

    public function testObjectsOfUnconfiguredClassesAreIgnored(): void
    {
        $this->evaluator->expects(self::never())->method('evaluate');
        $this->store->expects(self::never())->method('save');

        $listener = $this->listener(Gate::Block);
        $object = new ObjectStub('Category', 5);
        $listener->onPreSave(new DataObjectEvent($object));
        $listener->onPostSave(new DataObjectEvent($object));
        $listener->onPostSave(new DataObjectEvent(new Folder()));
    }

    public function testDisabledBundleDoesNothing(): void
    {
        $this->evaluator->expects(self::never())->method('evaluate');

        $this->listener(Gate::Block, enabled: false)->onPreSave(new DataObjectEvent(new ObjectStub('Product', 2)));
    }

    public function testEvaluationTravelsFromPreToPostAndIsStoredWithTheId(): void
    {
        $object = new ObjectStub('Product', null, 'new-product');
        $evaluation = $this->evaluation(true);
        $this->evaluator->method('evaluate')->willReturn($evaluation);
        $this->store->expects(self::once())->method('save')->with(42, $evaluation);

        $listener = $this->listener(Gate::Block);
        $listener->onPreSave(new DataObjectEvent($object));
        $object->setId(42);
        $listener->onPostSave(new DataObjectEvent($object));
        // a second post without a pre must not store again
        $listener->onPostSave(new DataObjectEvent($object));
    }

    public function testScoreFieldIsWrittenWhenConfigured(): void
    {
        $object = new ObjectStub('Product', 2);
        $this->evaluator->method('evaluate')->willReturn($this->evaluation(false));
        $this->scoreWriter->expects(self::once())->method('write')->with($object, 'completeness', 50);

        $this->listener(Gate::Off, scoreField: 'completeness')->onPreSave(new DataObjectEvent($object));
    }

    public function testBlockGateRejectsAPublishedIncompleteObject(): void
    {
        $this->evaluator->method('evaluate')->willReturn($this->evaluation(false));

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Completeness gate: Product "ABC-123" is incomplete. default (50% < 100%): name');

        $this->listener(Gate::Block)->onPreSave(new DataObjectEvent(new ObjectStub('Product', 2, 'ABC-123', true)));
    }

    public function testBlockGateLetsUnpublishedObjectsThrough(): void
    {
        $this->evaluator->method('evaluate')->willReturn($this->evaluation(false));
        $this->store->expects(self::once())->method('save');

        $listener = $this->listener(Gate::Block);
        $object = new ObjectStub('Product', 2, 'ABC-123', false);
        $listener->onPreSave(new DataObjectEvent($object));
        $listener->onPostSave(new DataObjectEvent($object));
        self::assertSame([], $this->logs);
    }

    public function testBlockGateLetsCompleteObjectsThrough(): void
    {
        $this->evaluator->method('evaluate')->willReturn($this->evaluation(true));

        $this->listener(Gate::Block)->onPreSave(new DataObjectEvent(new ObjectStub('Product', 2, 'ABC-123', true)));
        self::assertSame([], $this->logs);
    }

    public function testWarnGateLogsAWarningForPublishedIncompleteObjects(): void
    {
        $this->evaluator->method('evaluate')->willReturn($this->evaluation(false));

        $this->listener(Gate::Warn)->onPreSave(new DataObjectEvent(new ObjectStub('Product', 2, 'ABC-123', true)));

        self::assertCount(1, $this->logs);
        self::assertSame('warning', $this->logs[0][0]);
        self::assertStringContainsString('default (50% < 100%): name', $this->logs[0][1]);
    }

    public function testOffGateIsSilent(): void
    {
        $this->evaluator->method('evaluate')->willReturn($this->evaluation(false));

        $this->listener(Gate::Off)->onPreSave(new DataObjectEvent(new ObjectStub('Product', 2, 'ABC-123', true)));
        self::assertSame([], $this->logs);
    }

    public function testAnEvaluatorFailureNeverBlocksTheSave(): void
    {
        $this->evaluator->method('evaluate')->willThrowException(new \RuntimeException('resolver bug'));
        $this->store->expects(self::never())->method('save');

        $listener = $this->listener(Gate::Block);
        $object = new ObjectStub('Product', 2, 'ABC-123', true);
        $listener->onPreSave(new DataObjectEvent($object));
        $listener->onPostSave(new DataObjectEvent($object));

        self::assertSame('error', $this->logs[0][0]);
        self::assertStringContainsString('resolver bug', $this->logs[0][1]);
    }

    public function testAStoreFailureIsLoggedNotThrown(): void
    {
        $this->evaluator->method('evaluate')->willReturn($this->evaluation(true));
        $this->store->method('save')->willThrowException(new \RuntimeException('db down'));

        $listener = $this->listener(Gate::Off);
        $object = new ObjectStub('Product', 2);
        $listener->onPreSave(new DataObjectEvent($object));
        $listener->onPostSave(new DataObjectEvent($object));

        self::assertSame('error', $this->logs[0][0]);
    }

    public function testDeleteRemovesTheRowsOfConfiguredClassesOnly(): void
    {
        $this->store->expects(self::once())->method('delete')->with(2);

        $listener = $this->listener(Gate::Off);
        $listener->onPostDelete(new DataObjectEvent(new ObjectStub('Product', 2)));
        $listener->onPostDelete(new DataObjectEvent(new ObjectStub('Category', 3)));
    }

    public function testAssetsAreRewrittenOncePerRequestWhenOnSaveIsEnabled(): void
    {
        $this->evaluator->method('evaluate')->willReturn($this->evaluation(true));
        $this->assetWriter->expects(self::once())->method('writeAll');

        $listener = $this->listener(Gate::Off, assetConfig: ['enabled' => true, 'on_save' => true]);
        foreach ([2, 6] as $id) {
            $object = new ObjectStub('Product', $id);
            $listener->onPreSave(new DataObjectEvent($object));
            $listener->onPostSave(new DataObjectEvent($object));
        }
    }

    public function testAssetsAreNotRewrittenByDefault(): void
    {
        $this->evaluator->method('evaluate')->willReturn($this->evaluation(true));
        $this->assetWriter->expects(self::never())->method('writeAll');

        $listener = $this->listener(Gate::Off);
        $object = new ObjectStub('Product', 2);
        $listener->onPreSave(new DataObjectEvent($object));
        $listener->onPostSave(new DataObjectEvent($object));
    }

    private function evaluation(bool $complete): Evaluation
    {
        return new Evaluation('Product', [
            new Result('default', '', ['sku', 'name'], $complete ? [] : ['name'], 100),
        ]);
    }

    /**
     * @param array{enabled: bool, on_save: bool} $assetConfig
     */
    private function listener(Gate $gate, bool $enabled = true, ?string $scoreField = null, array $assetConfig = ['enabled' => false, 'on_save' => false]): DataObjectListener
    {
        $rules = new RuleSet([]);
        $reflection = new \ReflectionProperty($rules, 'rules');
        $reflection->setValue($rules, [
            'Product' => new ClassRule('Product', true, $gate, $scoreField, ['default' => new Profile('default', ['sku', 'name'], [], 100)]),
        ]);

        $logger = new class ($this->logs) extends AbstractLogger {
            /**
             * @param array<int, array{0: string, 1: string}> $logs
             */
            public function __construct(private array &$logs)
            {
            }

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->logs[] = [(string) $level, (string) $message];
            }
        };

        return new DataObjectListener($rules, $this->evaluator, $this->store, $this->scoreWriter, $this->assetWriter, $logger, $enabled, $assetConfig);
    }
}
