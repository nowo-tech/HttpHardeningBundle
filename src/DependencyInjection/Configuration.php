<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\DependencyInjection;

use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class Configuration implements ConfigurationInterface
{
    public const ALIAS = 'nowo_http_hardening';

    public function getConfigTreeBuilder(): TreeBuilder
    {
        $treeBuilder = new TreeBuilder(self::ALIAS);
        $root = $treeBuilder->getRootNode();

        $root
            ->children()
                ->arrayNode('security_headers')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultTrue()->end()
                        ->scalarNode('x_content_type_options')->defaultValue('nosniff')->end()
                        ->scalarNode('x_frame_options')->defaultValue('SAMEORIGIN')->end()
                        ->scalarNode('referrer_policy')->defaultValue('strict-origin-when-cross-origin')->end()
                        ->scalarNode('permissions_policy')->defaultValue('camera=(), microphone=(), geolocation=()')->end()
                        ->scalarNode('cross_origin_resource_policy')->defaultValue('same-origin')->end()
                        ->booleanNode('hsts_enabled')->defaultTrue()->end()
                        ->scalarNode('hsts')->defaultValue('max-age=31536000; includeSubDomains')->end()
                        ->booleanNode('hsts_skip_loopback')->defaultTrue()->end()
                    ->end()
                ->end()
                ->arrayNode('anonymous_session_strip')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->scalarNode('session_cookie_name')
                            ->defaultValue('PHPSESSID')
                            ->cannotBeEmpty()
                        ->end()
                        ->arrayNode('public_route_name_prefixes')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->arrayNode('public_route_names')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->scalarNode('cache_control')
                            ->defaultValue('public, max-age=120, must-revalidate')
                        ->end()
                    ->end()
                ->end()
            ->end();

        return $treeBuilder;
    }
}
