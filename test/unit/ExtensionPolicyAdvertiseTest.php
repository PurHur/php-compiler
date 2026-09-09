<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\CompilerVersion;
use PHPCompiler\ExtensionRegistry;
use PHPCompiler\ext\apcu\ApcuExtensionPolicy;
use PHPCompiler\ext\eio\EioExtensionPolicy;
use PHPCompiler\ext\igbinary\IgbinaryExtensionPolicy;
use PHPCompiler\ext\phar\PharExtensionPolicy;
use PHPCompiler\ext\rar\RarExtensionPolicy;
use PHPCompiler\ext\redis\RedisExtensionPolicy;
use PHPCompiler\ext\uuid\UuidExtensionPolicy;
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
        putenv('PHP_COMPILER_ENABLE_RAR');
        unset($_ENV['PHP_COMPILER_ENABLE_RAR'], $_SERVER['PHP_COMPILER_ENABLE_RAR']);
        putenv('PHP_COMPILER_ENABLE_EIO');
        unset($_ENV['PHP_COMPILER_ENABLE_EIO'], $_SERVER['PHP_COMPILER_ENABLE_EIO']);
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

    public function testUuidHostOrCompilerVersion(): void
    {
        $expected = \extension_loaded('uuid') || CompilerVersion::supportsUuid();
        self::assertSame($expected, ExtensionRegistry::advertisesExtensionFor('uuid'));
        self::assertSame($expected, UuidExtensionPolicy::advertisesExtension());
    }

    public function testRarEnvOptIn(): void
    {
        putenv('PHP_COMPILER_ENABLE_RAR=1');
        $_ENV['PHP_COMPILER_ENABLE_RAR'] = '1';
        self::assertTrue(ExtensionRegistry::advertisesExtensionFor('rar'));
        self::assertTrue(RarExtensionPolicy::advertisesExtension());
    }

    public function testEioEnvOptIn(): void
    {
        putenv('PHP_COMPILER_ENABLE_EIO=1');
        $_ENV['PHP_COMPILER_ENABLE_EIO'] = '1';
        self::assertTrue(ExtensionRegistry::advertisesExtensionFor('eio'));
        self::assertTrue(EioExtensionPolicy::advertisesExtension());
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
