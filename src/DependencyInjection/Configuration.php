<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\DependencyInjection;

use Nowo\HttpHardeningBundle\EventSubscriber\ContentSecurityPolicySubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\PublicCacheControlSubscriber;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function sprintf;

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

        $this->addCspSection($root);
        $this->addPublicCacheSection($root);
        $this->addCanonicalHostSection($root);
        $this->addSafeUrlsSection($root);

        return $treeBuilder;
    }

    /**
     * Config key (`script_src`) for a CSP directive name (`script-src`).
     */
    public static function cspKey(string $directive): string
    {
        return str_replace('-', '_', $directive);
    }

    private function addCspSection(ArrayNodeDefinition $root): void
    {
        $children = $root
            ->children()
                ->arrayNode('csp')
                    ->info('Content-Security-Policy with a per-request nonce (request attribute "csp_nonce").')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->booleanNode('report_only')
                            ->info('Send Content-Security-Policy-Report-Only instead of Content-Security-Policy.')
                            ->defaultFalse()
                        ->end()
                        ->booleanNode('stamp_nonces')
                            ->info('Stamp the nonce on inline <script>/<style> unless <html> carries data-csp-strict-nonce.')
                            ->defaultTrue()
                        ->end()
                        ->booleanNode('debug_relaxations')
                            ->info("In kernel.debug add 'unsafe-eval' (script-src), 'unsafe-inline' (style-src-elem) and ws: wss: (connect-src).")
                            ->defaultTrue()
                        ->end()
                        ->arrayNode('excluded_path_prefixes')
                            ->info('Paths that never receive the header (Web Profiler fragments by default).')
                            ->scalarPrototype()->end()
                            ->defaultValue(['/_wdt', '/_profiler'])
                        ->end();

        foreach (ContentSecurityPolicySubscriber::DEFAULT_DIRECTIVES as $directive => $sources) {
            $key = self::cspKey($directive);
            $children
                ->arrayNode($key)
                    ->info(sprintf('Base sources of %s (replaces the default list; [] omits the directive).', $directive))
                    ->scalarPrototype()->end()
                    ->defaultValue($sources)
                ->end()
                ->arrayNode($key.'_extra')
                    ->info(sprintf('Sources appended to %s.', $directive))
                    ->scalarPrototype()->end()
                    ->defaultValue([])
                ->end();
        }

        $children->end()->end()->end();
    }

    private function addPublicCacheSection(ArrayNodeDefinition $root): void
    {
        $root
            ->children()
                ->arrayNode('public_cache')
                    ->info('Cacheable Cache-Control for anonymous GET/HEAD on public routes.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->arrayNode('page_route_name_prefixes')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->arrayNode('page_route_names')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->scalarNode('page_cache_control')
                            ->defaultValue(PublicCacheControlSubscriber::DEFAULT_PAGE_CACHE_CONTROL)
                        ->end()
                        ->arrayNode('file_route_names')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                        ->scalarNode('file_cache_control')
                            ->defaultValue(PublicCacheControlSubscriber::DEFAULT_FILE_CACHE_CONTROL)
                        ->end()
                        ->scalarNode('session_cookie_name')
                            ->defaultValue('PHPSESSID')
                            ->cannotBeEmpty()
                        ->end()
                        ->scalarNode('remember_me_cookie_name')
                            ->info('Empty string disables the remember-me opt-out.')
                            ->defaultValue('REMEMBERME')
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    private function addCanonicalHostSection(ArrayNodeDefinition $root): void
    {
        $root
            ->children()
                ->arrayNode('canonical_host')
                    ->info('Single 301 from www.<apex> to the apex host of base_url.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->scalarNode('base_url')
                            ->info('Public base URL, e.g. "%env(SITE_PUBLIC_BASE_URL)%".')
                            ->defaultValue('')
                        ->end()
                        ->integerNode('status_code')
                            ->defaultValue(301)
                            ->validate()
                                ->ifNotInArray([301, 302, 307, 308])
                                ->thenInvalid('canonical_host.status_code must be 301, 302, 307 or 308, got %s.')
                            ->end()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    private function addSafeUrlsSection(ArrayNodeDefinition $root): void
    {
        $root
            ->children()
                ->arrayNode('safe_urls')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('twig_functions')
                            ->info('Register safe_internal_path() and safe_href() Twig functions (requires twig/twig).')
                            ->defaultFalse()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }
}
