<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * IncludePathRuntime: always JitVmHelperLink + IncludePathJitHelper PHP (#9245, #20877).
 */
final class IncludePathRuntimeShrinkTest extends TestCase
{
    public function testIncludePathRuntimeAlwaysUsesJitHelperBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/IncludePathRuntime.php');
        $this->assertStringContainsString('IncludePathJitHelper', $source);
        $this->assertStringContainsString('IncludePathResolveJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('include_path_get_bridge_entry', $source);
        $this->assertStringContainsString('VmActiveContextInitLlvm::requestThinStandaloneInit', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('implementThinStandaloneStubs', $source);
        $this->assertStringNotContainsString('implementThinGetStub', $source);
        $this->assertStringNotContainsString('shouldDeferHeavyStreamIoEmitters', $source);
        $this->assertStringNotContainsString('IncludePathStandaloneLlvm', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/IncludePathStandaloneLlvm.php');
    }

    public function testIncludePathJitHelperIsNestedJitSafe(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/IncludePathJitHelper.php');
        $this->assertStringContainsString('ensureSeeded', $source);
        $this->assertStringContainsString('private static string $current', $source);
        $this->assertStringContainsString('private static string $previous', $source);
        $this->assertStringNotContainsString('?string $stack', $source);
        $this->assertStringNotContainsString('?string $current', $source);
        $this->assertStringNotContainsString('@ini_get', $source);
        $this->assertStringNotContainsString('ini_get(', $source);
        $this->assertStringNotContainsString('explode(', $source);
        $this->assertStringNotContainsString('implode(', $source);
        $this->assertStringNotContainsString('VmString::', $source);
        $this->assertStringNotContainsString('$hasPrevious', $source);
    }

    public function testSpineBundleIncludesIncludePathPhpJitPath(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('IncludePathJitHelper.php', $spine);
        $this->assertStringContainsString('IncludePathResolveJitHelper.php', $spine);
        $this->assertStringContainsString('IncludePathRuntime.php', $spine);
    }
}
