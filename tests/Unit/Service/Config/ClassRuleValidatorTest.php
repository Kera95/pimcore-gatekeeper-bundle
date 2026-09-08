<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Tests\Unit\Service\Config;

use Codeception\Test\Unit;
use Pimcore\Model\DataObject\ClassDefinition;
use Pimcore\Model\DataObject\ClassDefinition\Data;
use Tsf\GatekeeperBundle\Model\ClassRule;
use Tsf\GatekeeperBundle\Model\Gate;
use Tsf\GatekeeperBundle\Model\Profile;
use Tsf\GatekeeperBundle\Service\Config\ClassRuleValidator;
use Tsf\GatekeeperBundle\Service\FieldReader;
use Tsf\GatekeeperBundle\Service\LanguageProvider;

final class ClassRuleValidatorTest extends Unit
{
    public function testAValidRuleHasNoProblems(): void
    {
        $validator = $this->validator(['sku' => new Data\Input(), 'title' => new Data\Input(), 'completeness' => new Data\Numeric()]);

        self::assertSame([], $validator->validate($this->rule(['sku', 'title'], ['en'], 'completeness')));
    }

    public function testMissingClass(): void
    {
        $validator = $this->validator([], classExists: false);

        self::assertSame(['Class "Product" does not exist.'], $validator->validate($this->rule(['sku'])));
    }

    public function testEveryProblemIsReported(): void
    {
        $validator = $this->validator(['sku' => new Data\Input(), 'completeness' => new Data\Input()]);

        $problems = $validator->validate($this->rule(['sku', 'nope', 'localizedfields'], ['en', 'xx'], 'completeness'));

        self::assertCount(4, $problems);
        $all = implode("\n", $problems);
        self::assertStringContainsString('field "nope" does not exist', $all);
        self::assertStringContainsString('list the localized fields by name', $all);
        self::assertStringContainsString('language "xx" is not a valid system language (en, de)', $all);
        self::assertStringContainsString('must be a Numeric field, input given', $all);
    }

    public function testMissingScoreFieldSuggestsTheCommand(): void
    {
        $validator = $this->validator(['sku' => new Data\Input()]);

        $problems = $validator->validate($this->rule(['sku'], [], 'completeness'));

        self::assertCount(1, $problems);
        self::assertStringContainsString('tsf:gatekeeper:add-score-field Product --name=completeness', $problems[0]);
    }

    /**
     * @param array<string, Data> $fields top-level fields of the class
     */
    private function validator(array $fields, bool $classExists = true): ClassRuleValidator
    {
        $reader = $this->createMock(FieldReader::class);
        $reader->method('getDefinition')->willReturnCallback(static fn (ClassDefinition $c, string $f): ?Data => $fields[$f] ?? null);
        $reader->method('topLevel')->willReturnCallback(static fn (ClassDefinition $c, string $f): ?Data => $fields[$f] ?? null);

        $languages = $this->createMock(LanguageProvider::class);
        $languages->method('getValidLanguages')->willReturn(['en', 'de']);

        return new class($reader, $languages, $classExists) extends ClassRuleValidator {
            public function __construct(FieldReader $reader, LanguageProvider $languages, private readonly bool $exists)
            {
                parent::__construct($reader, $languages);
            }

            protected function loadClass(string $name): ?ClassDefinition
            {
                return $this->exists ? (new ClassDefinition())->setName($name) : null;
            }
        };
    }

    /**
     * @param string[] $required
     * @param string[] $languages
     */
    private function rule(array $required, array $languages = [], ?string $scoreField = null): ClassRule
    {
        return new ClassRule('Product', true, Gate::Warn, $scoreField, ['default' => new Profile('default', $required, $languages, 100)]);
    }
}
