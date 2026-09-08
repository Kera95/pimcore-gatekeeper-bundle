<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\DependencyInjection;

use Symfony\Component\Config\FileLocator;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Extension\PrependExtensionInterface;
use Symfony\Component\DependencyInjection\Loader\YamlFileLoader;
use Tsf\GatekeeperBundle\EventListener\DataObjectListener;
use Tsf\GatekeeperBundle\Service\Config\RuleSet;
use Tsf\GatekeeperBundle\Service\Report\AssetWriter;
use Tsf\GatekeeperBundle\Service\Report\StudioReportDefinitions;

use function is_array;

final class TsfGatekeeperExtension extends Extension implements PrependExtensionInterface
{
    public const CUSTOM_REPORTS_BUNDLE = 'PimcoreCustomReportsBundle';

    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $container->setParameter('tsf_gatekeeper.config', $config);
        $container->setParameter('tsf_gatekeeper.enabled', $config['enabled']);

        $loader = new YamlFileLoader($container, new FileLocator(__DIR__ . '/../../config'));
        $loader->load('services.yaml');

        $container->getDefinition(RuleSet::class)
            ->setArgument('$classes', $config['classes']);

        $container->getDefinition(AssetWriter::class)
            ->setArgument('$config', $config['report']['asset']);

        $container->getDefinition(DataObjectListener::class)
            ->setArgument('$enabled', $config['enabled'])
            ->setArgument('$assetConfig', $config['report']['asset']);
    }

    /**
     * Ships the "Completeness" reports to PimcoreCustomReportsBundle, when it is registered and
     * report.studio is not disabled. Runs before load(), so the raw extension config is inspected.
     */
    public function prepend(ContainerBuilder $container): void
    {
        $bundles = $container->hasParameter('kernel.bundles') ? $container->getParameter('kernel.bundles') : [];
        if (!is_array($bundles) || !isset($bundles[self::CUSTOM_REPORTS_BUNDLE])) {
            return;
        }

        foreach ($container->getExtensionConfig('tsf_gatekeeper') as $config) {
            if (isset($config['report']['studio']) && $config['report']['studio'] === false) {
                return;
            }
        }

        $container->prependExtensionConfig('pimcore_custom_reports', [
            'definitions' => StudioReportDefinitions::all(),
        ]);
    }
}
