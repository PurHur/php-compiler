<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\RuntimeStrictness;
use PHPUnit\Framework\TestCase;

/** Runtime strictness env policy (#7361). */
final class RuntimeStrictnessTest extends TestCase
{
    private const ENV = 'PHP_COMPILER_RUNTIME_STRICT';

    /** @var array<string, mixed> */
    private array $savedServer = [];

    /** @var array<string, mixed> */
    private array $savedEnv = [];

    protected function setUp(): void
    {
        $this->savedServer = $_SERVER;
        $this->savedEnv = $_ENV;
        RuntimeStrictness::resetCacheForTests();
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->savedServer;
        $_ENV = $this->savedEnv;
        RuntimeStrictness::resetCacheForTests();
        putenv(self::ENV);
    }

    public function testDefaultModeIsPhpSrcWhenUnset(): void
    {
        unset($_SERVER[self::ENV], $_ENV[self::ENV]);
        putenv(self::ENV);

        $this->assertSame(RuntimeStrictness::MODE_PHP_SRC, RuntimeStrictness::mode());
        $this->assertTrue(RuntimeStrictness::isPhpSrcStrict());
        $this->assertFalse(RuntimeStrictness::isPhpCompilerStrict());
    }

    public function testExplicitPhpSrcMode(): void
    {
        $this->setEnv('php-src');

        $this->assertSame(RuntimeStrictness::MODE_PHP_SRC, RuntimeStrictness::mode());
        $this->assertTrue(RuntimeStrictness::isPhpSrcStrict());
    }

    public function testOptInPhpCompilerMode(): void
    {
        $this->setEnv('php-compiler');

        $this->assertSame(RuntimeStrictness::MODE_PHP_COMPILER, RuntimeStrictness::mode());
        $this->assertTrue(RuntimeStrictness::isPhpCompilerStrict());
    }

    public function testUnknownValueFallsBackToPhpSrc(): void
    {
        $this->setEnv('bogus');

        $this->assertSame(RuntimeStrictness::MODE_PHP_SRC, RuntimeStrictness::mode());
        $this->assertTrue(RuntimeStrictness::isPhpSrcStrict());
    }

    public function testEnforceStringBuiltinParityGuardsStaysEnabledInV1(): void
    {
        $this->setEnv('php-compiler');

        $this->assertTrue(RuntimeStrictness::enforceStringBuiltinParityGuards());
    }

    public function testCiGuardRejectsPhpCompilerStrict(): void
    {
        $root = dirname(__DIR__, 2);
        $cmd = 'PHP_COMPILER_RUNTIME_STRICT=php-compiler bash -lc '
            .escapeshellarg('source script/ci-common.sh && ci_guard_runtime_strictness').' 2>&1';
        $out = [];
        $code = 0;
        exec($cmd, $out, $code);
        $joined = implode("\n", $out);
        $this->assertSame(1, $code, $joined);
        $this->assertStringContainsString('php-compiler is forbidden in CI', $joined);
    }

    public function testCiGuardAllowsUnsetOrPhpSrc(): void
    {
        $root = dirname(__DIR__, 2);
        foreach (['', 'php-src'] as $value) {
            $prefix = '' === $value ? 'unset PHP_COMPILER_RUNTIME_STRICT; ' : "export PHP_COMPILER_RUNTIME_STRICT={$value}; ";
            $cmd = 'bash -lc '
                .escapeshellarg('cd '.$root.' && '.$prefix.'source script/ci-common.sh && ci_guard_runtime_strictness').' 2>&1';
            $out = [];
            $code = 0;
            exec($cmd, $out, $code);
            $this->assertSame(0, $code, implode("\n", $out));
        }
    }

    private function setEnv(string $value): void
    {
        $_SERVER[self::ENV] = $value;
        $_ENV[self::ENV] = $value;
        putenv(self::ENV.'='.$value);
        RuntimeStrictness::resetCacheForTests();
    }
}
