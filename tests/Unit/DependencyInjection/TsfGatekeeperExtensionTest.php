<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\Tests\Unit\DependencyInjection;

use Codeception\Test\Unit;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Tsf\GatekeeperBundle\DependencyInjection\TsfGatekeeperExtension;
use Tsf\GatekeeperBundle\EventListener\DataObjectListener;
use Tsf\GatekeeperBundle\Service\Config\RuleSet;
use Tsf\GatekeeperBundle\Service\Emptiness\Resolver\StringResolver;
use Tsf\GatekeeperBundle\Service\Report\AssetWriter;
use Tsf\GatekeeperBundle\Service\Report\StudioReportDefinitions;

final class TsfGatekeeperExtensionTest extends Unit
{
    public function testLoadRegistersServicesAndParameters(): void
    {
        $container = new ContainerBuilder();
        (new TsfGatekeeperExtension())->load([['classes' => ['Product' => ['required' => ['sku']]]]], $container);

        self::assertTrue($container->hasDefinition(RuleSet::class));
        self::assertTrue($container->hasDefinition(DataObjectListener::class));
        self::assertTrue($container->hasDefinition(StringResolver::class));
        self::assertTrue($container->hasDefinition(AssetWriter::class));
        self::assertTrue($container->getParameter('tsf_gatekeeper.enabled'));

        $classes = $container->getDefinition(RuleSet::class)->getArgument('$classes');
        self::assertSame(['sku'], $classes['Product']['required']);

        $listenerTags = $container->getDefinition(DataObjectListener::class)->getTag('kernel.event_listener');
        self::assertSame(
            ['pimcore.dataobject.preAdd', 'pimcore.dataobject.preUpdate', 'pimcore.dataobject.postAdd', 'pimcore.dataobject.postUpdate', 'pimcore.dataobject.postDelete'],
            array_column($listenerTags, 'event')
        );
    }

    public function testPrependRegistersTheReportsWhenCustomReportsIsAvailable(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', [TsfGatekeeperExtension::CUSTOM_REPORTS_BUNDLE => 'Some\\Bundle']);

        (new TsfGatekeeperExtension())->prepend($container);

        $prepended = $container->getExtensionConfig('pimcore_custom_reports');
        self::assertCount(1, $prepended);
        self::assertArrayHasKey(StudioReportDefinitions::OBJECTS, $prepended[0]['definitions']);
        self::assertArrayHasKey(StudioReportDefinitions::SUMMARY, $prepended[0]['definitions']);
    }

    public function testPrependDoesNothingWithoutCustomReports(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', []);

        (new TsfGatekeeperExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('pimcore_custom_reports'));
    }

    public function testPrependRespectsReportStudioFalse(): void
    {
        $container = new ContainerBuilder();
        $container->setParameter('kernel.bundles', [TsfGatekeeperExtension::CUSTOM_REPORTS_BUNDLE => 'Some\\Bundle']);
        $container->prependExtensionConfig('tsf_gatekeeper', ['report' => ['studio' => false]]);

        (new TsfGatekeeperExtension())->prepend($container);

        self::assertSame([], $container->getExtensionConfig('pimcore_custom_reports'));
    }

    public function testReportDefinitionsUseOnlyTheKeysStudioAccepts(): void
    {
        foreach (StudioReportDefinitions::all() as $definition) {
            self::assertIsInt($definition['modificationDate']);
            self::assertIsInt($definition['creationDate']);
            self::assertStringStartsWith('SELECT', $definition['dataSourceConfig'][0]['sql']);
            foreach ($definition['columnConfiguration'] as $column) {
                self::assertSame([], array_diff(array_keys($column), ['name', 'id', 'label', 'display', 'export', 'order', 'action', 'width', 'displayType', 'filter', 'filter_drilldown']));
            }
        }
    }
}
