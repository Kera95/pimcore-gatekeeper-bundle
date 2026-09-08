<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Tests\Unit\Service;

use Codeception\Test\Unit;
use PHPUnit\Framework\MockObject\MockObject;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Tsf\GatekeeperBundle\Model\ClassRule;
use Tsf\GatekeeperBundle\Model\Gate;
use Tsf\GatekeeperBundle\Model\Profile;
use Tsf\GatekeeperBundle\Service\Emptiness\EmptinessChecker;
use Tsf\GatekeeperBundle\Service\Emptiness\Resolver\StringResolver;
use Tsf\GatekeeperBundle\Service\Evaluator;
use Tsf\GatekeeperBundle\Service\FieldReader;
use Tsf\GatekeeperBundle\Service\LanguageProvider;
use Tsf\GatekeeperBundle\Tests\Support\ObjectStub;

final class EvaluatorTest extends Unit
{
    /**
     * @var array<string, array{localized: bool, values: array<string, mixed>}>
     */
    private array $fields = [];

    private FieldReader&MockObject $reader;

    private LanguageProvider&MockObject $languages;

    private Evaluator $evaluator;

    protected function _before(): void
    {
        $this->reader = $this->createMock(FieldReader::class);
        $this->reader->method('getDefinition')->willReturnCallback(
            fn (ClassDefinition $class, string $field): ?Data => isset($this->fields[$field]) ? new Data\Input() : null
        );
        $this->reader->method('isLocalized')->willReturnCallback(
            fn (ClassDefinition $class, string $field): bool => $this->fields[$field]['localized'] ?? false
        );
        $this->reader->method('read')->willReturnCallback(
            fn (ObjectStub $object, string $field, ?string $language): mixed => $this->fields[$field]['values'][$language ?? ''] ?? null
        );

        $this->languages = $this->createMock(LanguageProvider::class);
        $this->languages->method('getValidLanguages')->willReturn(['en', 'de', 'fr']);

        $this->evaluator = new Evaluator($this->reader, new EmptinessChecker([new StringResolver()]), $this->languages);
    }

    public function testNonLocalizedProfileYieldsOneRow(): void
    {
        $this->field('sku', ['' => 'ABC']);
        $this->field('name', ['' => '']);
        $this->field('ean', ['' => '0']);

        $evaluation = $this->evaluator->evaluate(new ObjectStub('Product', 2), $this->rule([
            new Profile('default', ['sku', 'name', 'ean'], [], 100),
        ]));

        self::assertSame('Product', $evaluation->getClassName());
        self::assertCount(1, $evaluation->getResults());
        $result = $evaluation->getResults()[0];
        self::assertSame('', $result->getLanguage());
        self::assertSame(['name'], $result->getMissing());
        self::assertSame(67, $result->getScore());
        self::assertSame(67, $evaluation->getAggregateScore());
    }

    public function testLocalizedFieldsProduceOneRowPerLanguage(): void
    {
        $this->field('sku', ['' => 'ABC']);
        $this->field('title', ['en' => 'Title', 'de' => '', 'fr' => 'Titre'], true);

        $evaluation = $this->evaluator->evaluate(new ObjectStub('Product', 2), $this->rule([
            new Profile('default', ['sku', 'title'], [], 100),
        ]));

        $rows = [];
        foreach ($evaluation->getResults() as $result) {
            $rows[$result->getLanguage()] = $result->getMissing();
        }

        self::assertSame(['en' => [], 'de' => ['title'], 'fr' => []], $rows);
        self::assertSame(50, $evaluation->getAggregateScore());
        self::assertSame('default/de (50% < 100%): title', $evaluation->describeFailures());
    }

    public function testProfileLanguagesRestrictTheRows(): void
    {
        $this->field('title', ['en' => 'x', 'de' => '', 'fr' => ''], true);

        $evaluation = $this->evaluator->evaluate(new ObjectStub('Product', 2), $this->rule([
            new Profile('print', ['title'], ['en'], 80),
        ]));

        self::assertCount(1, $evaluation->getResults());
        self::assertSame('en', $evaluation->getResults()[0]->getLanguage());
        self::assertTrue($evaluation->isPassed());
    }

    public function testNonLocalizedFieldsCountTheSameInEveryLanguageRow(): void
    {
        $this->field('sku', ['' => '']);
        $this->field('title', ['en' => 'x', 'de' => 'y'], true);

        $evaluation = $this->evaluator->evaluate(new ObjectStub('Product', 2), $this->rule([
            new Profile('default', ['sku', 'title'], ['en', 'de'], 100),
        ]));

        foreach ($evaluation->getResults() as $result) {
            self::assertSame(['sku'], $result->getMissing());
            self::assertSame(50, $result->getScore());
        }
    }

    public function testUnknownFieldsCountAsMissing(): void
    {
        $this->field('sku', ['' => 'ABC']);

        $evaluation = $this->evaluator->evaluate(new ObjectStub('Product', 2), $this->rule([
            new Profile('default', ['sku', 'does_not_exist'], [], 100),
        ]));

        self::assertSame(['does_not_exist'], $evaluation->getResults()[0]->getMissing());
    }

    public function testMultipleProfilesAreEvaluatedIndependently(): void
    {
        $this->field('sku', ['' => 'ABC']);
        $this->field('ean', ['' => '']);

        $evaluation = $this->evaluator->evaluate(new ObjectStub('Product', 2), $this->rule([
            new Profile('default', ['sku'], [], 100),
            new Profile('print', ['sku', 'ean'], [], 50),
        ]));

        self::assertCount(2, $evaluation->getResults());
        self::assertTrue($evaluation->isPassed(), 'print scores 50 which meets its threshold of 50');
        self::assertSame(50, $evaluation->getAggregateScore());
    }

    public function testWithoutValidLanguagesLocalizedFieldsFallBackToASingleRow(): void
    {
        $languages = $this->createMock(LanguageProvider::class);
        $languages->method('getValidLanguages')->willReturn([]);
        $evaluator = new Evaluator($this->reader, new EmptinessChecker([new StringResolver()]), $languages);
        $this->field('title', ['' => 'x'], true);

        $evaluation = $evaluator->evaluate(new ObjectStub('Product', 2), $this->rule([new Profile('default', ['title'], [], 100)]));

        self::assertCount(1, $evaluation->getResults());
        self::assertSame('', $evaluation->getResults()[0]->getLanguage());
    }

    /**
     * @param array<string, mixed> $values keyed by language, "" for non-localized
     */
    private function field(string $name, array $values, bool $localized = false): void
    {
        $this->fields[$name] = ['localized' => $localized, 'values' => $values];
    }

    /**
     * @param Profile[] $profiles
     */
    private function rule(array $profiles): ClassRule
    {
        $keyed = [];
        foreach ($profiles as $profile) {
            $keyed[$profile->getName()] = $profile;
        }

        return new ClassRule('Product', true, Gate::Warn, null, $keyed);
    }
}
