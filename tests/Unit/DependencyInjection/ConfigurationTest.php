<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Tests\Unit\DependencyInjection;

use Codeception\Test\Unit;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;
use Tsf\GatekeeperBundle\DependencyInjection\Configuration;

final class ConfigurationTest extends Unit
{
    public function testEmptyConfigurationYieldsTheDocumentedDefaults(): void
    {
        self::assertSame(
            [
                'enabled' => true,
                'classes' => [],
                'report' => [
                    'studio' => true,
                    'asset' => [
                        'enabled' => false,
                        'folder' => Configuration::DEFAULT_ASSET_FOLDER,
                        'formats' => ['csv'],
                        'on_save' => false,
                    ],
                ],
            ],
            $this->process([])
        );
    }

    public function testClassDefaultsAreApplied(): void
    {
        $config = $this->process(['classes' => ['Product' => ['required' => ['sku']]]]);

        self::assertSame(
            [
                'required' => ['sku'],
                'enabled' => true,
                'languages' => [],
                'threshold' => 100,
                'gate' => 'warn',
                'score_field' => null,
                'profiles' => [],
            ],
            $config['classes']['Product']
        );
    }

    public function testProfilesKeepNullThresholdAndEmptyLanguagesForInheritance(): void
    {
        $config = $this->process(['classes' => ['Product' => [
            'required' => ['sku'],
            'profiles' => ['print' => ['required' => ['ean']]],
        ]]]);

        self::assertSame(['required' => ['ean'], 'languages' => [], 'threshold' => null], $config['classes']['Product']['profiles']['print']);
    }

    public function testAClassWithOnlyProfilesIsAllowed(): void
    {
        $config = $this->process(['classes' => ['Product' => [
            'profiles' => ['print' => ['required' => ['ean'], 'languages' => ['de'], 'threshold' => 50]],
        ]]]);

        self::assertSame([], $config['classes']['Product']['required']);
        self::assertSame(50, $config['classes']['Product']['profiles']['print']['threshold']);
    }

    /**
     * @dataProvider invalidConfigurations
     *
     * @param array<string, mixed> $config
     */
    public function testInvalidConfigurationsAreRejected(array $config, string $messagePart): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessageMatches('/' . preg_quote($messagePart, '/') . '/');

        $this->process($config);
    }

    /**
     * @return iterable<string, array{0: array<string, mixed>, 1: string}>
     */
    public static function invalidConfigurations(): iterable
    {
        yield 'no required and no profiles' => [['classes' => ['Product' => []]], 'non-empty "required" list or at least one profile'];
        yield 'duplicate field' => [['classes' => ['Product' => ['required' => ['sku', 'sku']]]], 'duplicate field names'];
        yield 'empty field name' => [['classes' => ['Product' => ['required' => ['']]]], 'cannot contain an empty value'];
        yield 'threshold too high' => [['classes' => ['Product' => ['required' => ['sku'], 'threshold' => 101]]], 'Should be less than or equal to 100'];
        yield 'threshold negative' => [['classes' => ['Product' => ['required' => ['sku'], 'threshold' => -1]]], 'Should be greater than or equal to 0'];
        yield 'unknown gate' => [['classes' => ['Product' => ['required' => ['sku'], 'gate' => 'maybe']]], 'Permissible values: "off", "warn", "block"'];
        yield 'bad language' => [['classes' => ['Product' => ['required' => ['sku'], 'languages' => ['english']]]], 'Invalid language code'];
        yield 'profile named default' => [['classes' => ['Product' => ['required' => ['sku'], 'profiles' => ['default' => ['required' => ['ean']]]]]], '"default" is reserved'];
        yield 'profile without required' => [['classes' => ['Product' => ['required' => ['sku'], 'profiles' => ['print' => []]]]], 'must be configured'];
        yield 'relative asset folder' => [['report' => ['asset' => ['folder' => 'reports']]], 'absolute asset path'];
        yield 'unknown asset format' => [['report' => ['asset' => ['formats' => ['xlsx']]]], 'Permissible values: "csv", "md"'];
    }

    public function testRegionalLanguageCodesAreAccepted(): void
    {
        $config = $this->process(['classes' => ['Product' => ['required' => ['sku'], 'languages' => ['de_AT', 'en', 'zh_Hans']]]]);

        self::assertSame(['de_AT', 'en', 'zh_Hans'], $config['classes']['Product']['languages']);
    }

    /**
     * @param array<string, mixed> $config
     *
     * @return array<string, mixed>
     */
    private function process(array $config): array
    {
        return (new Processor())->processConfiguration(new Configuration(), [$config]);
    }
}
