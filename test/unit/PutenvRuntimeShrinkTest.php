<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * putenv: PutenvJitHelper + phpc_putenv_kernel — no caller-side LibcExtern in JitEnv (#23414).
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
        $this->assertStringNotContainsString('emitLibcPutenvMirror', $source);
        $this->assertStringNotContainsString('LibcExtern', $source);
        $this->assertStringNotContainsString("lookupFunction('malloc')", $source);
        $this->assertStringNotContainsString("lookupFunction('setenv')", $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('StreamIoRuntime', $source);
    }

    public function testPutenvJitHelperDelegatesToKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/PutenvJitHelper.php');
        $this->assertStringContainsString('phpc_putenv_kernel', $source);
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitPutenvKernel.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/phpc_putenv_kernel.php');
        $kernel = (string) file_get_contents(__DIR__.'/../../ext/standard/JitPutenvKernel.php');
        $this->assertStringContainsString("lookupFunction('setenv')", $kernel);
        $this->assertStringContainsString("lookupFunction('unsetenv')", $kernel);
        $this->assertStringContainsString('LibcExtern::register', $kernel);
        // Proven emitLibcPutenvMirror shape: assignment string + malloc + strchr + setenv.
        $this->assertStringContainsString("lookupFunction('malloc')", $kernel);
        $this->assertStringContainsString("lookupFunction('strchr')", $kernel);
    }

    public function testStringGetenvPutenvUsesSlimHelperPath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGetenv.php');
        $this->assertStringContainsString('PUTENV_HELPER_PATH', $source);
        $this->assertStringContainsString('PutenvJitHelper.php', $source);
        $this->assertStringContainsString('PutenvJitHelper::putenv', $source);
        $this->assertStringContainsString('phpc_putenv_kernel', $source);
    }

    public function testSpineBundleIncludesPutenvKernel(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitEnv.php', $spine);
        $this->assertStringContainsString('PutenvJitHelper.php', $spine);
        $this->assertStringContainsString('JitPutenvKernel.php', $spine);
        $this->assertStringContainsString('phpc_putenv_kernel.php', $spine);
    }

    public function testNestedJitAllowlistsPutenvKernel(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString('phpc_putenv_kernel', $source);
    }
}
