<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\DependencyInjection;

use Nowo\HttpHardeningBundle\Command\LintCspNoncesCommand;
use Nowo\HttpHardeningBundle\Csp\CspSourceProviderInterface;
use Nowo\HttpHardeningBundle\Csp\InlineNonceLinter;
use Nowo\HttpHardeningBundle\EventSubscriber\AnonymousSessionCookieStripSubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\CanonicalHostRedirectSubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\ContentSecurityPolicySubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\PublicCacheControlSubscriber;
use Nowo\HttpHardeningBundle\EventSubscriber\SecurityHeadersSubscriber;
use Nowo\HttpHardeningBundle\Http\SafeInternalRedirect;
use Nowo\HttpHardeningBundle\Twig\CspNonceExtension;
use Nowo\HttpHardeningBundle\Twig\SafeUrlExtension;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\DependencyInjection\Argument\TaggedIteratorArgument;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;
use Twig\Extension\AbstractExtension;

/**
 * @author Héctor Franco Aceituno <hectorfranco@nowo.tech>
 * @copyright 2026 Nowo.tech
 */
final class NowoHttpHardeningExtension extends Extension
{
    /**
     * @param array<int, array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $config = $this->processConfiguration(new Configuration(), $configs);

        $headers = $config['security_headers'];
        if ($headers['enabled']) {
            $definition = new Definition(SecurityHeadersSubscriber::class);
            $definition->setAutowired(true);
            $definition->setAutoconfigured(true);
            $definition->setArgument('$xContentTypeOptions', (string) $headers['x_content_type_options']);
            $definition->setArgument('$xFrameOptions', (string) $headers['x_frame_options']);
            $definition->setArgument('$referrerPolicy', (string) $headers['referrer_policy']);
            $definition->setArgument('$permissionsPolicy', (string) $headers['permissions_policy']);
            $definition->setArgument('$crossOriginResourcePolicy', (string) $headers['cross_origin_resource_policy']);
            $definition->setArgument('$hstsEnabled', (bool) $headers['hsts_enabled']);
            $definition->setArgument('$hsts', (string) $headers['hsts']);
            $definition->setArgument('$hstsSkipLoopback', (bool) $headers['hsts_skip_loopback']);
            $definition->addTag('kernel.event_listener', [
                'event' => 'kernel.response',
                'method' => '__invoke',
                'priority' => -80,
            ]);
            $container->setDefinition(SecurityHeadersSubscriber::class, $definition);
        }

        $strip = $config['anonymous_session_strip'];
        if ($strip['enabled']) {
            $definition = new Definition(AnonymousSessionCookieStripSubscriber::class);
            $definition->setAutowired(true);
            $definition->setAutoconfigured(true);
            $definition->setArgument('$tokenStorage', new Reference('security.token_storage'));
            $definition->setArgument('$sessionCookieName', (string) $strip['session_cookie_name']);
            $definition->setArgument('$publicRouteNamePrefixes', array_values($strip['public_route_name_prefixes']));
            $definition->setArgument('$publicRouteNames', array_values($strip['public_route_names']));
            $definition->setArgument('$cacheControl', (string) $strip['cache_control']);
            $definition->addTag('kernel.event_listener', [
                'event' => 'kernel.response',
                'method' => '__invoke',
                'priority' => -1024,
            ]);
            $container->setDefinition(AnonymousSessionCookieStripSubscriber::class, $definition);
        }

        $this->loadCsp($config['csp'], $container);
        $this->loadPublicCache($config['public_cache'], $container);
        $this->loadCanonicalHost($config['canonical_host'], $container);
        $this->loadSafeUrls($config['safe_urls'], $container);
    }

    /**
     * @param array<string, mixed> $csp
     */
    private function loadCsp(array $csp, ContainerBuilder $container): void
    {
        $container->registerForAutoconfiguration(CspSourceProviderInterface::class)
            ->addTag(CspSourceProviderInterface::TAG);

        // The linter needs no runtime config: available even while the CSP header is disabled.
        $container->setDefinition(InlineNonceLinter::class, new Definition(InlineNonceLinter::class));
        if ($this->classAvailable(Command::class)) {
            $command = new Definition(LintCspNoncesCommand::class);
            $command->setArgument('$linter', new Reference(InlineNonceLinter::class));
            $command->setArgument('$projectDir', '%kernel.project_dir%');
            $command->addTag('console.command', ['command' => LintCspNoncesCommand::NAME]);
            $container->setDefinition(LintCspNoncesCommand::class, $command);
        }

        if (!$csp['enabled']) {
            return;
        }

        $directives = [];
        $extras = [];
        foreach (array_keys(ContentSecurityPolicySubscriber::DEFAULT_DIRECTIVES) as $directive) {
            $key = Configuration::cspKey($directive);
            $directives[$directive] = array_values(array_map(strval(...), (array) $csp[$key]));
            $extras[$directive] = array_values(array_map(strval(...), (array) $csp[$key.'_extra']));
        }

        $definition = new Definition(ContentSecurityPolicySubscriber::class);
        $definition->setArgument('$kernelDebug', '%kernel.debug%');
        $definition->setArgument('$directives', $directives);
        $definition->setArgument('$extraSources', $extras);
        $definition->setArgument('$sourceProviders', new TaggedIteratorArgument(CspSourceProviderInterface::TAG));
        $definition->setArgument('$excludedPathPrefixes', array_values(array_map(strval(...), (array) $csp['excluded_path_prefixes'])));
        $definition->setArgument('$stampNonces', (bool) $csp['stamp_nonces']);
        $definition->setArgument('$debugRelaxations', (bool) $csp['debug_relaxations']);
        $definition->setArgument('$reportOnly', (bool) $csp['report_only']);
        $definition->addTag('kernel.event_listener', [
            'event' => 'kernel.request',
            'method' => 'onRequest',
            'priority' => 1024,
        ]);
        $definition->addTag('kernel.event_listener', [
            'event' => 'kernel.response',
            'method' => 'onResponse',
            'priority' => -100,
        ]);
        $container->setDefinition(ContentSecurityPolicySubscriber::class, $definition);

        if ($this->classAvailable(AbstractExtension::class)) {
            $twig = new Definition(CspNonceExtension::class);
            $twig->setArgument('$requestStack', new Reference('request_stack'));
            $twig->addTag('twig.extension');
            $container->setDefinition(CspNonceExtension::class, $twig);
        }
    }

    /**
     * @param array<string, mixed> $cache
     */
    private function loadPublicCache(array $cache, ContainerBuilder $container): void
    {
        if (!$cache['enabled']) {
            return;
        }

        $definition = new Definition(PublicCacheControlSubscriber::class);
        $definition->setArgument('$tokenStorage', new Reference('security.token_storage'));
        $definition->setArgument('$pageRouteNamePrefixes', array_values((array) $cache['page_route_name_prefixes']));
        $definition->setArgument('$pageRouteNames', array_values((array) $cache['page_route_names']));
        $definition->setArgument('$pageCacheControl', (string) $cache['page_cache_control']);
        $definition->setArgument('$fileRouteNames', array_values((array) $cache['file_route_names']));
        $definition->setArgument('$fileCacheControl', (string) $cache['file_cache_control']);
        $definition->setArgument('$sessionCookieName', (string) $cache['session_cookie_name']);
        $definition->setArgument('$rememberMeCookieName', (string) $cache['remember_me_cookie_name']);
        // After the session listener (-1000) and the anonymous session strip (-1024).
        $definition->addTag('kernel.event_listener', [
            'event' => 'kernel.response',
            'method' => '__invoke',
            'priority' => -1100,
        ]);
        $container->setDefinition(PublicCacheControlSubscriber::class, $definition);
    }

    /**
     * @param array<string, mixed> $canonical
     */
    private function loadCanonicalHost(array $canonical, ContainerBuilder $container): void
    {
        if (!$canonical['enabled']) {
            return;
        }

        $definition = new Definition(CanonicalHostRedirectSubscriber::class);
        $definition->setArgument('$publicBaseUrl', (string) $canonical['base_url']);
        $definition->setArgument('$statusCode', (int) $canonical['status_code']);
        $definition->addTag('kernel.event_listener', [
            'event' => 'kernel.request',
            'method' => '__invoke',
            'priority' => 2048,
        ]);
        $container->setDefinition(CanonicalHostRedirectSubscriber::class, $definition);
    }

    /**
     * @param array<string, mixed> $safeUrls
     */
    private function loadSafeUrls(array $safeUrls, ContainerBuilder $container): void
    {
        $definition = new Definition(SafeInternalRedirect::class);
        $definition->setArgument('$requestStack', new Reference('request_stack', ContainerBuilder::NULL_ON_INVALID_REFERENCE));
        $container->setDefinition(SafeInternalRedirect::class, $definition);

        if (!$safeUrls['twig_functions'] || !$this->classAvailable(AbstractExtension::class)) {
            return;
        }

        $twig = new Definition(SafeUrlExtension::class);
        $twig->setArgument('$safeInternalRedirect', new Reference(SafeInternalRedirect::class));
        $twig->addTag('twig.extension');
        $container->setDefinition(SafeUrlExtension::class, $twig);
    }

    /**
     * Optional integrations (symfony/console, twig/twig) are only wired when installed.
     */
    private function classAvailable(string $class): bool
    {
        return class_exists($class);
    }

    public function getAlias(): string
    {
        return Configuration::ALIAS;
    }
}
