<?php

declare(strict_types=1);

namespace Tsf\GatekeeperBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;
use Tsf\GatekeeperBundle\Model\Gate;
use Tsf\GatekeeperBundle\Model\Profile;

use function count;
use function is_string;

final class Configuration implements ConfigurationInterface
{
    public const LANGUAGE_PATTERN = '/^[a-z]{2,3}(_[A-Za-z]{2,4})?$/';

    public const DEFAULT_ASSET_FOLDER = '/reports/completeness';

    public const ASSET_FORMATS = ['csv', 'md'];

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder('tsf_gatekeeper');

        $treeBuilder->getRootNode()
            ->children()
                ->booleanNode('enabled')
                    ->defaultTrue()
                    ->info('Master switch. When false nothing is evaluated on save; commands still work.')
                ->end()
                ->arrayNode('classes')
                    ->info('Completeness rules keyed by DataObject class name (e.g. Product).')
                    ->useAttributeAsKey('name')
                    ->arrayPrototype()
                        ->children()
                            ->booleanNode('enabled')->defaultTrue()->end()
                            ->append($this->requiredNode(false))
                            ->append($this->languagesNode())
                            ->integerNode('threshold')
                                ->defaultValue(100)
                                ->min(0)->max(100)
                                ->info('Score (0-100) at which an object counts as complete.')
                            ->end()
                            ->enumNode('gate')
                                ->values(Gate::values())
                                ->defaultValue(Gate::Warn->value)
                                ->info('off: score only. warn: log a warning when a published object fails. block: refuse to save a published object that fails.')
                            ->end()
                            ->scalarNode('score_field')
                                ->defaultNull()
                                ->info('Optional Numeric field on the class that receives the aggregate score on every save.')
                            ->end()
                            ->arrayNode('profiles')
                                ->info('Additional named rule sets (channels, markets). Each inherits languages and threshold from the class unless it sets its own.')
                                ->useAttributeAsKey('name')
                                ->arrayPrototype()
                                    ->children()
                                        ->append($this->requiredNode(true))
                                        ->append($this->languagesNode())
                                        ->integerNode('threshold')
                                            ->defaultNull()
                                            ->min(0)->max(100)
                                        ->end()
                                    ->end()
                                ->end()
                                ->validate()
                                    ->ifTrue(static fn (array $profiles): bool => isset($profiles[Profile::DEFAULT_NAME]))
                                    ->thenInvalid('"default" is reserved for the class-level "required" list; pick another profile name.')
                                ->end()
                            ->end()
                        ->end()
                        ->validate()
                            ->ifTrue(static fn (array $class): bool => count($class['required']) === 0 && count($class['profiles']) === 0)
                            ->thenInvalid('A class needs a non-empty "required" list or at least one profile.')
                        ->end()
                    ->end()
                ->end()
                ->arrayNode('report')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('studio')
                            ->defaultTrue()
                            ->info('Register the "Completeness" reports in the admin UI (needs PimcoreCustomReportsBundle).')
                        ->end()
                        ->arrayNode('asset')
                            ->addDefaultsIfNotSet()
                            ->children()
                                ->booleanNode('enabled')
                                    ->defaultFalse()
                                    ->info('Allow writing report files into the asset tree.')
                                ->end()
                                ->scalarNode('folder')
                                    ->defaultValue(self::DEFAULT_ASSET_FOLDER)
                                    ->cannotBeEmpty()
                                    ->validate()
                                        ->ifTrue(static fn ($v): bool => !is_string($v) || !str_starts_with($v, '/'))
                                        ->thenInvalid('The asset folder must be an absolute asset path such as "/reports/completeness".')
                                    ->end()
                                ->end()
                                ->arrayNode('formats')
                                    ->defaultValue(['csv'])
                                    ->enumPrototype()->values(self::ASSET_FORMATS)->end()
                                    ->info('csv: one file per class with every result row. md: one summary.md across all classes.')
                                ->end()
                                ->booleanNode('on_save')
                                    ->defaultFalse()
                                    ->info('Also rewrite the files after every save of a gated object. Off by default: it reads all results each time.')
                                ->end()
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }

    private function requiredNode(bool $mandatory): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('required');
        $node
            ->info('Field names that must be filled. Localized fields by their plain name.')
            ->scalarPrototype()
                ->cannotBeEmpty()
            ->end()
            ->validate()
                ->ifTrue(static fn (array $fields): bool => count($fields) !== count(array_unique($fields)))
                ->thenInvalid('"required" contains duplicate field names.')
            ->end();

        if ($mandatory) {
            $node->isRequired()->requiresAtLeastOneElement();
        }

        return $node;
    }

    private function languagesNode(): ArrayNodeDefinition
    {
        $node = new ArrayNodeDefinition('languages');
        $node
            ->info('Languages evaluated for localized fields. Empty: all valid system languages (class) or the class languages (profile).')
            ->scalarPrototype()
                ->validate()
                    ->ifTrue(static fn ($v): bool => !is_string($v) || preg_match(self::LANGUAGE_PATTERN, $v) !== 1)
                    ->thenInvalid('Invalid language code %s; expected something like "en" or "de_AT".')
                ->end()
            ->end();

        return $node;
    }
}
