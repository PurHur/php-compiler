<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * putenv thin AOT: libc-only via isThinStandaloneAotMain; embed NestedJIT GetenvJitHelper (#20499, #20156, #20443).
 */
final class PutenvRuntimeShrinkTest extends TestCase
{
    public function testJitEnvPutenvGatesThinOnIsThinStandaloneAotMain(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitEnv.php');
        $this->assertStringContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringContainsString('GetenvJitHelper::putenv', $source);
        $this->assertStringContainsString('emitLibcPutenvMirror', $source);
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
