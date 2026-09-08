<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Tests\Unit\Service\Config;

use Codeception\Test\Unit;
use Symfony\Component\Config\Definition\Processor;
use Tsf\GatekeeperBundle\DependencyInjection\Configuration;
use Tsf\GatekeeperBundle\Model\Gate;
use Tsf\GatekeeperBundle\Service\Config\RuleSet;

final class RuleSetTest extends Unit
{
    public function testClassLevelRequiredBecomesTheDefaultProfile(): void
    {
        $rules = $this->build(['Product' => ['required' => ['sku', 'name'], 'languages' => ['en', 'de'], 'threshold' => 90]]);
        $rule = $rules->get('Product');

        self::assertNotNull($rule);
        self::assertSame(['default'], $rule->getProfileNames());
        self::assertSame(['sku', 'name'], $rule->getProfile('default')->getRequired());
        self::assertSame(['en', 'de'], $rule->getProfile('default')->getLanguages());
        self::assertSame(90, $rule->getProfile('default')->getThreshold());
        self::assertSame(Gate::Warn, $rule->getGate());
        self::assertNull($rule->getScoreField());
        self::assertTrue($rule->isEnabled());
    }

    public function testProfilesInheritLanguagesAndThresholdUnlessOverridden(): void
    {
        $rules = $this->build(['Product' => [
            'required' => ['sku'],
            'languages' => ['en', 'de'],
            'threshold' => 90,
            'gate' => 'block',
            'score_field' => 'completeness',
            'profiles' => [
                'print' => ['required' => ['ean']],
                'web' => ['required' => ['name'], 'languages' => ['fr'], 'threshold' => 50],
            ],
        ]]);
        $rule = $rules->get('Product');

        self::assertSame(['default', 'print', 'web'], $rule->getProfileNames());
        self::assertSame(['en', 'de'], $rule->getProfile('print')->getLanguages());
        self::assertSame(90, $rule->getProfile('print')->getThreshold());
        self::assertSame(['fr'], $rule->getProfile('web')->getLanguages());
        self::assertSame(50, $rule->getProfile('web')->getThreshold());
        self::assertSame(Gate::Block, $rule->getGate());
        self::assertSame('completeness', $rule->getScoreField());
        self::assertSame(['sku', 'ean', 'name'], $rule->getAllRequiredFields());
    }

    public function testClassWithoutRequiredHasNoDefaultProfile(): void
    {
        $rules = $this->build(['Product' => ['profiles' => ['print' => ['required' => ['ean']]]]]);

        self::assertSame(['print'], $rules->get('Product')->getProfileNames());
    }

    public function testDisabledClassesAreExcludedFromEnabled(): void
    {
        $rules = $this->build([
            'Product' => ['required' => ['sku'], 'enabled' => false],
            'Category' => ['required' => ['name']],
        ]);

        self::assertSame(['Category'], array_keys($rules->enabled()));
        self::assertNull($rules->getEnabled('Product'));
        self::assertNotNull($rules->get('Product'));
        self::assertNull($rules->get('Unknown'));
    }

    /**
     * @param array<string, mixed> $classes
     */
    private function build(array $classes): RuleSet
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [['classes' => $classes]]);

        return new RuleSet($config['classes']);
    }
}
