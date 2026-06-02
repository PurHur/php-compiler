<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\LazyBuiltins;
use PHPUnit\Framework\TestCase;

/** Lazy ext/* JIT lowering policy (issue #94). */
final class JitLazyBuiltinsTest extends TestCase
{
    protected function tearDown(): void
    {
        putenv('PHP_COMPILER_JIT_LAZY_BUILTINS');
        putenv('PHP_COMPILER_SELFHOST_AOT');
        putenv('PHP_COMPILER_M3_EMIT_MINIMAL');
        unset($_ENV['PHP_COMPILER_JIT_LAZY_BUILTINS'], $_ENV['PHP_COMPILER_SELFHOST_AOT'], $_ENV['PHP_COMPILER_M3_EMIT_MINIMAL']);
    }

    public function testEnabledByDefaultForEmbedMode(): void
    {
        $this->assertTrue(LazyBuiltins::isEnabled(Builtin::LOAD_TYPE_EMBED));
    }

    public function testDisabledForStandaloneMode(): void
    {
        $this->assertFalse(LazyBuiltins::isEnabled(Builtin::LOAD_TYPE_STANDALONE));
    }

    public function testOptOutViaEnv(): void
    {
        putenv('PHP_COMPILER_JIT_LAZY_BUILTINS=0');
        $_ENV['PHP_COMPILER_JIT_LAZY_BUILTINS'] = '0';

        $this->assertFalse(LazyBuiltins::isEnabled(Builtin::LOAD_TYPE_EMBED));
    }

    public function testDisabledForSelfHostAot(): void
    {
        putenv('PHP_COMPILER_SELFHOST_AOT=1');
        $_ENV['PHP_COMPILER_SELFHOST_AOT'] = '1';

        $this->assertFalse(LazyBuiltins::isEnabled(Builtin::LOAD_TYPE_EMBED));
    }

    public function testRuntimeSkipsEagerModuleFuncsWhenLazyEnabled(): void
    {
        $runtime = new Runtime();
        $ref = new \ReflectionClass($runtime);
        $method = $ref->getMethod('shouldSkipLoadJitCompileModuleFuncs');
        $method->setAccessible(true);

        $this->assertTrue($method->invoke($runtime));
    }

    public function testRuntimeEagerWhenLazyOptOut(): void
    {
        putenv('PHP_COMPILER_JIT_LAZY_BUILTINS=0');
        $_ENV['PHP_COMPILER_JIT_LAZY_BUILTINS'] = '0';

        $runtime = new Runtime();
        $ref = new \ReflectionClass($runtime);
        $method = $ref->getMethod('shouldSkipLoadJitCompileModuleFuncs');
        $method->setAccessible(true);

        $this->assertFalse($method->invoke($runtime));
    }
}
