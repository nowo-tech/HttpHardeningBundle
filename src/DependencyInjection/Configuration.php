<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\DependencyInjection;

use Nowo\HttpHardeningBundle\EventSubscriber\ContentSecurityPolicySubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\PublicCacheControlSubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\RateLimitSubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\TenantExistenceMaskSubscriber;
use Symfony\Component\Config\Definition\Builder\ArrayNodeDefinition;
use Symfony\Component\Config\Definition\Builder\TreeBuilder;
use Symfony\Component\Config\Definition\ConfigurationInterface;

use function array_filter;
use function array_keys;
use function array_map;
use function is_array;
use function is_string;
use function preg_match;
use function sprintf;
use function str_starts_with;
use function strtoupper;

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
        $this->addNoStoreSection($root);
        $this->addTenantExistenceMaskSection($root);
        $this->addCanonicalHostSection($root);
        $this->addSafeUrlsSection($root);
        $this->addWebProfilerSection($root);
        $this->addRateLimitSection($root);

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
                        ->end()
                        ->arrayNode('nonce_free_style_path_prefixes')
                            ->info("Paths (segment-aware prefix) whose style-src-elem drops the nonce and allows 'unsafe-inline' — routes mounting editors that inject <style> at runtime (e.g. CKEditor 5).")
                            ->scalarPrototype()->end()
                            ->defaultValue([])
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

    private function addNoStoreSection(ArrayNodeDefinition $root): void
    {
        $root
            ->children()
                ->arrayNode('no_store')
                    ->info('Cache-Control: no-store, private (and no ETag / Last-Modified) on sensitive main requests.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->arrayNode('path_patterns')
                            ->info('PCRE patterns (with delimiters) matched against Request::getPathInfo(), e.g. "#^/admin(?:/|$)#".')
                            ->scalarPrototype()
                                ->validate()
                                    ->ifTrue(static fn (mixed $pattern): bool => !self::isValidPattern($pattern))
                                    ->thenInvalid('no_store.path_patterns entry %s is not a valid PCRE pattern (delimiters included, e.g. "#^/admin(?:/|$)#").')
                                ->end()
                            ->end()
                            ->defaultValue([])
                        ->end()
                        ->arrayNode('routes')
                            ->info('Exact route names (matched on the _route request attribute).')
                            ->scalarPrototype()->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    private function addTenantExistenceMaskSection(ArrayNodeDefinition $root): void
    {
        $root
            ->children()
                ->arrayNode('tenant_existence_mask')
                    ->info('No existence oracle on tenant URLs: an access denial on a masked path for a signed-in user with no access at all to the captured tenant answers 404 (like an unknown id) instead of 403.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->arrayNode('path_patterns')
                            ->info('PCRE patterns (with delimiters) matched against Request::getPathInfo(); the tenant id is the "tenant" named group, else the first capture group, e.g. "#^/projects/(?<tenant>[0-9a-f-]{36})(?:/|$)#i".')
                            ->scalarPrototype()
                                ->validate()
                                    ->ifTrue(static fn (mixed $pattern): bool => !self::isValidPattern($pattern) || !self::hasCaptureGroup((string) $pattern))
                                    ->thenInvalid('tenant_existence_mask.path_patterns entry %s is not a valid PCRE pattern with a capture group for the tenant id (e.g. "#^/projects/(?<tenant>[^/]+)(?:/|$)#").')
                                ->end()
                            ->end()
                            ->defaultValue([])
                        ->end()
                        ->scalarNode('access_checker')
                            ->info('Service id implementing Nowo\\HttpHardeningBundle\\Security\\TenantAccessCheckerInterface (default: the autowiring alias of that interface).')
                            ->defaultNull()
                        ->end()
                        ->integerNode('priority')
                            ->info('kernel.exception listener priority; must stay above the firewall ExceptionListener (1).')
                            ->defaultValue(TenantExistenceMaskSubscriber::DEFAULT_PRIORITY)
                            ->min(2)
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $mask): bool => true === $mask['enabled'] && [] === $mask['path_patterns'])
                        ->thenInvalid('tenant_existence_mask.enabled requires at least one path_patterns entry.')
                    ->end()
                ->end()
            ->end();
    }

    /**
     * Whether a pattern has a capturing group (plain, `(?<name>…)`, `(?P<name>…)` or `(?'name'…)`); escaped
     * parentheses do not count. Config processing only.
     */
    public static function hasCaptureGroup(string $pattern): bool
    {
        return 1 === preg_match("/(?<!\\\\)\\((?:(?!\\?)|\\?P?<(?![=!])|\\?')/", $pattern);
    }

    /**
     * Whether a no_store path pattern compiles (`preg_match` returns false and warns otherwise).
     */
    public static function isValidPattern(mixed $pattern): bool
    {
        if (!is_string($pattern) || '' === $pattern) {
            return false;
        }

        // Config processing only (container build), never on the request path.
        return false !== @preg_match($pattern, '');
    }

    private function addCanonicalHostSection(ArrayNodeDefinition $root): void
    {
        $root
            ->children()
                ->arrayNode('trusted_hosts')
                    ->info('Env var processor %env(nowo_trusted_hosts:SYMFONY_TRUSTED_HOSTS)% for framework.trusted_hosts: named var, then fallback_env, then (outside local_environments) the host of public_url_env + internal_hosts.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->scalarNode('fallback_env')->defaultValue('TRUSTED_HOSTS')->end()
                        ->scalarNode('public_url_env')->defaultValue('DEFAULT_URI')->end()
                        ->arrayNode('internal_hosts')
                            ->info('Plain host names always allowed next to the public host (container alias, loopback for in-container probes).')
                            ->scalarPrototype()->end()
                            ->defaultValue(['localhost', '127.0.0.1'])
                        ->end()
                        ->arrayNode('local_environments')
                            ->scalarPrototype()->end()
                            ->defaultValue(['dev', 'test'])
                        ->end()
                    ->end()
                ->end()
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

    private function addWebProfilerSection(ArrayNodeDefinition $root): void
    {
        $root
            ->children()
                ->arrayNode('web_profiler')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('isolate_csp_handler')
                            ->info('Make WebProfilerBundle\'s web_profiler.csp.handler non-shared so a /_wdt or /_profiler request cannot strip the CSP header from later pages in a long-running worker (FrankenPHP worker mode). No-op without WebProfilerBundle.')
                            ->defaultTrue()
                        ->end()
                    ->end()
                ->end()
            ->end();
    }

    private function addRateLimitSection(ArrayNodeDefinition $root): void
    {
        $root
            ->children()
                ->arrayNode('rate_limit')
                    ->info('Per-client-IP "N per minute" sliding-window limit on configured paths (429 + Retry-After). Needs symfony/rate-limiter. The PerMinuteRateLimiter service is available whenever symfony/rate-limiter is installed.')
                    ->addDefaultsIfNotSet()
                    ->children()
                        ->booleanNode('enabled')->defaultFalse()->end()
                        ->scalarNode('cache_pool')
                            ->info('PSR-6 cache pool service id holding the buckets (shared by every worker / replica when it is Redis-backed).')
                            ->defaultValue('cache.app')
                            ->cannotBeEmpty()
                        ->end()
                        ->integerNode('priority')
                            ->info('kernel.request listener priority; keep it above the firewall (8) so throttled requests never reach credential lookups.')
                            ->defaultValue(RateLimitSubscriber::DEFAULT_PRIORITY)
                        ->end()
                        ->arrayNode('rules')
                            ->info('Named rules; the first one whose method and path match a main request applies. The name separates the buckets of each rule.')
                            ->useAttributeAsKey('name')
                            ->arrayPrototype()
                                ->children()
                                    ->arrayNode('path_prefixes')
                                        ->info('Path prefixes matched against Request::getPathInfo(), segment-aware ("/hooks/slack" matches "/hooks/slack" and "/hooks/slack/x", not "/hooks/slackware"; a trailing "/" makes it a plain prefix).')
                                        ->scalarPrototype()
                                            ->validate()
                                                ->ifTrue(static fn (mixed $prefix): bool => !is_string($prefix) || !str_starts_with($prefix, '/'))
                                                ->thenInvalid('rate_limit.rules.*.path_prefixes entry %s must start with "/".')
                                            ->end()
                                        ->end()
                                        ->defaultValue([])
                                    ->end()
                                    ->arrayNode('path_patterns')
                                        ->info('PCRE patterns (with delimiters) matched against Request::getPathInfo(), e.g. "#^/api/[^/]+/ingest(?:/|$)#".')
                                        ->scalarPrototype()
                                            ->validate()
                                                ->ifTrue(static fn (mixed $pattern): bool => !self::isValidPattern($pattern))
                                                ->thenInvalid('rate_limit.rules.*.path_patterns entry %s is not a valid PCRE pattern (delimiters included).')
                                            ->end()
                                        ->end()
                                        ->defaultValue([])
                                    ->end()
                                    ->arrayNode('methods')
                                        ->info('HTTP methods the rule applies to (empty: every method).')
                                        ->beforeNormalization()
                                            ->ifArray()
                                            ->then(static fn (array $methods): array => array_map(static fn (mixed $m): mixed => is_string($m) ? strtoupper($m) : $m, $methods))
                                        ->end()
                                        ->scalarPrototype()->end()
                                        ->defaultValue([])
                                    ->end()
                                    ->integerNode('limit_per_minute')
                                        ->info('Requests allowed per client IP and sliding minute; 0 disables the rule.')
                                        ->defaultValue(60)
                                        ->min(0)
                                    ->end()
                                    ->scalarNode('limit_provider')
                                        ->info('Service id implementing Nowo\\HttpHardeningBundle\\RateLimit\\RateLimitProviderInterface; its runtime value replaces limit_per_minute.')
                                        ->defaultNull()
                                    ->end()
                                ->end()
                                ->validate()
                                    ->ifTrue(static fn (array $rule): bool => [] === $rule['path_prefixes'] && [] === $rule['path_patterns'])
                                    ->thenInvalid('Each rate_limit.rules entry needs at least one path_prefixes or path_patterns entry.')
                                ->end()
                            ->end()
                            ->validate()
                                ->ifTrue(static fn (mixed $rules): bool => is_array($rules) && [] !== array_filter(array_keys($rules), static fn (int|string $name): bool => 1 !== preg_match('/^[A-Za-z0-9_.-]+$/', (string) $name)))
                                ->thenInvalid('rate_limit.rules names may only contain letters, digits, "_", "." and "-": %s.')
                            ->end()
                            ->defaultValue([])
                        ->end()
                    ->end()
                    ->validate()
                        ->ifTrue(static fn (array $rateLimit): bool => true === $rateLimit['enabled'] && [] === $rateLimit['rules'])
                        ->thenInvalid('rate_limit.enabled requires at least one rules entry.')
                    ->end()
                ->end()
            ->end();
    }
}
