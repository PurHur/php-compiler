<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * putenv: always GetenvJitHelper via NestedJIT + libc mirror — no thin libc-only fork (#21023, #20499, #20644).
 */
final class PutenvRuntimeShrinkTest extends TestCase
{
    public function testJitEnvPutenvAlwaysUsesHelperOverlay(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitEnv.php');
        $this->assertStringContainsString('GetenvJitHelper::putenv', $source);
        $this->assertStringContainsString('emitLibcPutenvMirror', $source);
        $this->assertStringContainsString('emitPutenvSyntaxGuard', $source);
        $this->assertStringContainsString('ensurePutenvLinked', $source);
        $this->assertStringContainsString('extractBoolFromHelperResult', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('StreamIoRuntime', $source);
    }

    public function testSpineBundleIncludesJitEnv(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('JitEnv.php', $spine);
        $this->assertStringContainsString('GetenvJitHelper.php', $spine);
    }
}
