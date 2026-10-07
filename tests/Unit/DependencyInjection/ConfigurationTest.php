<?php

declare(strict_types=1);

namespace Nowo\HttpHardeningBundle\Tests\Unit\DependencyInjection;

use Nowo\HttpHardeningBundle\DependencyInjection\Configuration;
use PHPUnit\Framework\TestCase;
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
}
