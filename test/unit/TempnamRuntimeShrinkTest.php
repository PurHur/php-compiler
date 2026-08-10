<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\TempnamJitHelper;
use PHPCompiler\ext\standard\VmSysGetTempDirNative;
use PHPUnit\Framework\TestCase;

/**
 * tempnam() AOT via TempnamJitHelper PHP + NestedJIT mkstemp leaf (#15685, #29940).
 */
final class TempnamRuntimeShrinkTest extends TestCase
{
    public function testJitTempnamDelegatesToStringTempnamBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitTempnam.php');
        $this->assertStringContainsString('StringTempnam::invoke', $source);
        $this->assertStringContainsString('materializeStringOrFalse', $source);
        $this->assertStringNotContainsString('__compiler_tempnam', $source);
    }

    public function testTempnamJitHelperUsesHostPeelNotFsDirNative(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/TempnamJitHelper.php');
        $this->assertStringContainsString('public static function resolveArgv(string $directory, string $prefix): ?string', $source);
        $this->assertStringContainsString('\\tempnam(', $source);
        $this->assertDoesNotMatchRegularExpression('/^\s*.*FsDirJitHelper::/m', $source);
        $this->assertDoesNotMatchRegularExpression('/^\s*.*VmFsTempnamNative::/m', $source);
        $this->assertStringNotContainsString('consumeNotice', $source);
    }

    public function testStringTempnamUsesHelperBridgeAndNestedLeaf(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringTempnam.php');
        $this->assertStringContainsString('TempnamJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink', $source);
        $this->assertStringContainsString('ensureCompiled', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive', $source);
        $this->assertStringContainsString('JitTempnamKernel::invoke', $source);
        $this->assertStringContainsString('__string__*', $source);
        $this->assertStringNotContainsString('JitValueBox::alloc', $source);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $source);
        $this->assertStringNotContainsString('implementForThinAot', $source);
        $this->assertStringNotContainsString('FsDirJitHelper.php', $source);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString("lookupFunction('mkstemp')", $source);
    }

    public function testJitTempnamKernelIsNestedJitLeafOnly(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitTempnamKernel.php');
        $this->assertStringContainsString('__phpc_jit_tempnam_leaf', $source);
        $this->assertStringContainsString('ensureNestedLeafBody', $source);
        $this->assertStringContainsString("lookupFunction('mkstemp')", $source);
        $this->assertStringContainsString('SysGetTempDirRuntime::invokeNestedLeaf', $source);
        $this->assertStringNotContainsString('implementForThinAot', $source);
    }

    public function testContextWhitelistsTempnamNestedLeaf(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Context.php');
        $this->assertStringContainsString("'tempnam'", $source);
        $this->assertStringContainsString('#29940', $source);
    }

    public function testSpineBundleIncludesTempnamHelper(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('TempnamJitHelper.php', $spine);
        $this->assertStringContainsString('StringTempnam.php', $spine);
        $this->assertStringContainsString('JitTempnamKernel.php', $spine);
    }

    public function testTempnamJitHelperMatchesHostTempnam(): void
    {
        $dir = VmSysGetTempDirNative::resolve();
        $path = TempnamJitHelper::resolveArgv($dir, 'phpc');
        $this->assertNotNull($path);
        $this->assertIsString($path);
        $this->assertStringStartsWith($dir, $path);
        $this->assertFileExists($path);
        @unlink($path);
    }
}
