<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** __compiler_readfile: PHP helper for embed; ext kernel for user-script AOT (#9188, #19311). */
final class ReadfileRuntimeShrinkTest extends TestCase
{
    public function testStringReadfileRoutesDeferThroughExtKernelNotLibcBuiltin(): void
    {
        $bridge = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringReadfile.php');
        $this->assertStringContainsString('ReadfileJitHelper', $bridge);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $bridge);
        $this->assertStringContainsString('JitReadfileKernel', $bridge);
        $this->assertStringContainsString('UserScriptAotDeferNestedJit', $bridge);
        $this->assertStringNotContainsString('ensureJitHelperCompiled', $bridge);
        $this->assertStringNotContainsString('StringReadfileLibc', $bridge);
        $this->assertStringNotContainsString("lookupFunction('open')", $bridge);
        $this->assertStringNotContainsString("lookupFunction('read')", $bridge);
        $this->assertStringNotContainsString("lookupFunction('write')", $bridge);
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringReadfileLibc.php');
        $this->assertFileExists(__DIR__.'/../../ext/standard/JitReadfileKernel.php');
    }

    public function testReadfileJitHelperDelegatesToVmFs(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/standard/ReadfileJitHelper.php');
        $this->assertStringContainsString('VmFs::readfile', $source);
        $this->assertStringNotContainsString("lookupFunction('open')", $source);
        $this->assertStringNotContainsString("lookupFunction('read')", $source);
    }

    public function testReadfileJitHelperReturnsMinusOneWhenOpenFails(): void
    {
        $this->assertSame(
            -1,
            \PHPCompiler\ext\standard\ReadfileJitHelper::readfile('/no/such/phpc-readfile-jit-helper-'.bin2hex(random_bytes(4)))
        );
    }

    public function testSpineBundleIncludesReadfilePhpPath(): void
    {
        $spine = (string) file_get_contents(__DIR__.'/../../test/selfhost/compiler_lib_spine_smoke/main.php');
        $this->assertStringContainsString('ReadfileJitHelper.php', $spine);
        $this->assertStringContainsString('JitReadfileKernel.php', $spine);
        $this->assertStringContainsString('StringReadfile.php', $spine);
        $this->assertStringNotContainsString('StringReadfileLibc.php', $spine);
    }
}
