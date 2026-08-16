<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * putenv: PutenvJitHelper via @putenv NestedJIT leaf — no kernel (#23414, #29334).
 */
final class PutenvRuntimeShrinkTest extends TestCase
{
    public function testJitEnvPutenvAlwaysUsesHelperOverlay(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitEnv.php');
        $this->assertStringContainsString('PutenvJitHelper::putenv', $source);
        $this->assertStringContainsString('emitPutenvSyntaxGuard', $source);
        $this->assertStringContainsString('ensurePutenvLinked', $source);
        $this->assertStringContainsString('extractBoolFromHelperResult', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringContainsString('putenvNestedLeaf', $source);
        $this->assertStringContainsString('invokePutenvNestedLeaf', $source);
        $this->assertStringNotContainsString('emitLibcPutenvMirror', $source);
        $this->assertStringNotContainsString('phpc_putenv_kernel', $source);
        $this->assertStringNotContainsString('JitPutenvKernel', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('StreamIoRuntime', $source);
    }

    public function testPutenvJitHelperUsesHostPutenvNotKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/PutenvJitHelper.php');
        $this->assertStringContainsString('@\\putenv', $source);
        $this->assertStringNotContainsString('phpc_putenv_kernel', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitPutenvKernel.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/phpc_putenv_kernel.php');
    }

    public function testStringGetenvPutenvUsesSlimHelperPathAndNestedLeaf(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetenv.php');
        $this->assertStringContainsString('PUTENV_HELPER_PATH', $source);
        $this->assertStringContainsString('PutenvJitHelper.php', $source);
        $this->assertStringContainsString('PutenvJitHelper::putenv', $source);
        $this->assertStringContainsString('invokePutenvNestedLeaf', $source);
        $this->assertStringContainsString('ensureLibcSetenvUnsetenv', $source);
        $this->assertStringContainsString('#31558', $source);
        $this->assertStringContainsString("lookupFunction('setenv')", $source);
        $this->assertStringContainsString("lookupFunction('unsetenv')", $source);
        $this->assertStringNotContainsString('phpc_putenv_kernel', $source);
    }

    public function testSpineBundleIncludesPutenvHelperNotKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitEnv.php', $spine);
        $this->assertStringContainsString('PutenvJitHelper.php', $spine);
        $this->assertStringNotContainsString('JitPutenvKernel.php', $spine);
        $this->assertStringNotContainsString('phpc_putenv_kernel.php', $spine);
    }

    public function testNestedJitAllowlistsPutenvBuiltinNotKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'putenv'", $source);
        $this->assertStringContainsString('#29334', $source);
        $this->assertStringNotContainsString('phpc_putenv_kernel', $source);
        $this->assertStringContainsString('isPreRegisterModuleNestedJitKernel', $source);
    }
}
