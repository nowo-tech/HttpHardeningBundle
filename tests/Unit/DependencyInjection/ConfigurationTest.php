<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\DependencyInjection;

use Nowo\HttpHardeningBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\Config\Definition\Processor;

final class ConfigurationTest extends TestCase
{
    public function testDefaults(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertTrue($config['security_headers']['enabled']);
        self::assertFalse($config['anonymous_session_strip']['enabled']);
        self::assertSame('PHPSESSID', $config['anonymous_session_strip']['session_cookie_name']);
    }

    public function testNewSectionsDefaultToDisabled(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[]]);

        self::assertFalse($config['csp']['enabled']);
        self::assertFalse($config['csp']['report_only']);
        self::assertTrue($config['csp']['stamp_nonces']);
        self::assertTrue($config['csp']['debug_relaxations']);
        self::assertSame(['/_wdt', '/_profiler'], $config['csp']['excluded_path_prefixes']);
        self::assertSame([], $config['csp']['nonce_free_style_path_prefixes']);
        self::assertSame(["'self'"], $config['csp']['script_src']);
        self::assertSame(["'unsafe-inline'"], $config['csp']['style_src_attr']);
        self::assertSame([], $config['csp']['connect_src_extra']);

        self::assertFalse($config['public_cache']['enabled']);
        self::assertSame('private, max-age=120, must-revalidate', $config['public_cache']['page_cache_control']);
        self::assertSame('public, max-age=3600, must-revalidate', $config['public_cache']['file_cache_control']);
        self::assertSame('PHPSESSID', $config['public_cache']['session_cookie_name']);
        self::assertSame('REMEMBERME', $config['public_cache']['remember_me_cookie_name']);

        self::assertFalse($config['no_store']['enabled']);
        self::assertSame([], $config['no_store']['path_patterns']);
        self::assertSame([], $config['no_store']['routes']);

        self::assertFalse($config['canonical_host']['enabled']);
        self::assertSame('', $config['canonical_host']['base_url']);
        self::assertSame(301, $config['canonical_host']['status_code']);

        self::assertFalse($config['safe_urls']['twig_functions']);
        self::assertTrue($config['web_profiler']['isolate_csp_handler']);
        self::assertSame('frame_ancestors', Configuration::cspKey('frame-ancestors'));
    }

    public function testRejectsNonRedirectStatusCode(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new Processor())->processConfiguration(new Configuration(), [['canonical_host' => ['status_code' => 304]]]);
    }

    public function testNoStoreAcceptsValidPatterns(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'no_store' => ['enabled' => true, 'path_patterns' => ['#^/admin(?:/|$)#'], 'routes' => ['app_keys']],
        ]]);

        self::assertSame(['#^/admin(?:/|$)#'], $config['no_store']['path_patterns']);
        self::assertSame(['app_keys'], $config['no_store']['routes']);
    }

    /**
     * @return iterable<string, array{mixed}>
     */
    public static function invalidPatterns(): iterable
    {
        yield 'no delimiters' => ['^/admin'];
        yield 'unbalanced group' => ['#^/(admin#'];
        yield 'empty' => [''];
    }

    #[DataProvider('invalidPatterns')]
    public function testNoStoreRejectsInvalidPatterns(mixed $pattern): void
    {
        $this->expectException(InvalidConfigurationException::class);
        $this->expectExceptionMessage('is not a valid PCRE pattern');

        (new Processor())->processConfiguration(new Configuration(), [[
            'no_store' => ['enabled' => true, 'path_patterns' => [$pattern]],
        ]]);
    }

    public function testIsValidPatternRejectsNonStrings(): void
    {
        self::assertFalse(Configuration::isValidPattern(null));
        self::assertTrue(Configuration::isValidPattern('#^/a#'));
    }

    public function testWebProfilerIsolationCanBeDisabled(): void
    {
        $config = (new Processor())->processConfiguration(new Configuration(), [[
            'web_profiler' => ['isolate_csp_handler' => false],
        ]]);

        self::assertFalse($config['web_profiler']['isolate_csp_handler']);
    }
}
