<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\GetcwdJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * getcwd() JIT: GetcwdJitHelper + GetcwdJit NestedJIT leaf (#29429, #25541, #26928).
 */
final class GetcwdJitRuntimeShrinkTest extends TestCase
{
    public function testJitGetcwdUsesPhpBridgeNotAlwaysOnLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitGetcwd.php');
        $this->assertStringContainsString('GetcwdJit::invoke', $source);
        $this->assertStringNotContainsString("lookupFunction('realpath')", $source);
        $this->assertStringNotContainsString('LibcExtern', $source);
    }

    public function testGetcwdJitUsesHelperBridgeAndNestedLeaf(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/GetcwdJit.php');
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringContainsString('invokeNestedLeaf', $source);
        $this->assertStringContainsString('GetcwdJitHelper', $source);
        $this->assertStringContainsString('__compiler_getcwd', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringNotContainsString("lookupFunction('realpath')", $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('LibcExtern::', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*.*VmGetcwdNative::/m',
            $source
        );
    }

    public function testGetcwdJitHelperUsesHostGetcwdNotVmNative(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/GetcwdJitHelper.php');
        $this->assertStringContainsString('public static function resolveJit(): string', $source);
        $this->assertStringContainsString('@\\getcwd', $source);
        $this->assertDoesNotMatchRegularExpression(
            '/^\s*.*VmGetcwdNative::/m',
            $source
        );
        $this->assertStringNotContainsString('realpath', $source);
        $this->assertMatchesRegularExpression(
            '/return\s+\\\\is_string\s*\(\s*\$cwd\s*\)\s*\?\s*\$cwd\s*:\s*[\'"][\'"]/',
            $source
        );

        if (!\function_exists('getcwd')) {
            $this->markTestSkipped('host getcwd unavailable');
        }
        $expected = \getcwd();
        if (false === $expected) {
            $this->assertSame('', GetcwdJitHelper::resolveJit());
        } else {
            $this->assertSame($expected, GetcwdJitHelper::resolveJit());
        }
    }

    public function testContextWhitelistsGetcwdNestedLeaf(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'getcwd'", $source);
        $this->assertStringContainsString('#29429', $source);
    }

    public function testSpineBundleIncludesGetcwdHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('GetcwdJitHelper.php', $spine);
        $this->assertStringContainsString('GetcwdJit.php', $spine);
    }
}
