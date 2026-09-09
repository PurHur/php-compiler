<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ExtensionRegistry;
use PHPCompiler\ext\apcu\ApcuExtensionPolicy;
use PHPCompiler\ext\igbinary\IgbinaryExtensionPolicy;
use PHPCompiler\ext\phar\PharExtensionPolicy;
use PHPCompiler\ext\redis\RedisExtensionPolicy;
use PHPUnit\Framework\TestCase;

/**
 * Manifest-driven advertise fold (#36204).
 */
final class ExtensionPolicyAdvertiseTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_ENABLE_REDIS');
        unset($_ENV['PHP_COMPILER_ENABLE_REDIS'], $_SERVER['PHP_COMPILER_ENABLE_REDIS']);
        parent::tearDown();
    }

    public function testPharAlwaysAdvertises(): void
    {
        self::assertTrue(ExtensionRegistry::advertisesExtensionFor('phar'));
        self::assertTrue(PharExtensionPolicy::advertisesExtension());
    }

    public function testIgbinaryMatchesCompilerVersion(): void
    {
        self::assertSame(
            CompilerVersion::supportsIgbinary(),
            ExtensionRegistry::advertisesExtensionFor('igbinary')
        );
        self::assertSame(
            CompilerVersion::supportsIgbinary(),
            IgbinaryExtensionPolicy::advertisesExtension()
        );
    }

    public function testApcuHostOrCompilerVersion(): void
    {
        $expected = \extension_loaded('apcu') || CompilerVersion::supportsApcu();
        self::assertSame($expected, ExtensionRegistry::advertisesExtensionFor('apcu'));
        self::assertSame($expected, ApcuExtensionPolicy::advertisesExtension());
    }

    public function testRedisEnvOptIn(): void
    {
        putenv('PHP_COMPILER_ENABLE_REDIS=1');
        $_ENV['PHP_COMPILER_ENABLE_REDIS'] = '1';
        self::assertTrue(ExtensionRegistry::advertisesExtensionFor('redis'));
        self::assertTrue(RedisExtensionPolicy::advertisesExtension());
    }

    public function testUnknownAdvertiseThrows(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        ExtensionRegistry::advertisesExtensionFor('standard');
    }

    public function testGeneratorCheckStaysCurrent(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/script/generate-extension-registry.php').' --check';
        exec($cmd, $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
    }

    public function testSyncPreservesAdvertise(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = escapeshellarg(PHP_BINARY).' '.escapeshellarg($root.'/script/sync-extension-manifests.php').' --check';
        exec($cmd, $out, $code);
        self::assertSame(0, $code, implode("\n", $out));
    }
}
