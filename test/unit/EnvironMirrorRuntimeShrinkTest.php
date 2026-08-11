<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** Environ mirror routes through EnvironMirrorRuntime + PHP helper (#18984, #19157, #30225). */
final class EnvironMirrorRuntimeShrinkTest extends TestCase
{
    public function testEnvironMirrorUserScriptLlvmDeleted(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/EnvironMirrorUserScriptLlvm.php');
    }

    public function testEnvironMirrorRuntimeUsesJitHelperBridgeAlways(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/EnvironMirrorRuntime.php');
        $this->assertStringContainsString('MIRROR_HELPER', $runtime);
        $this->assertStringContainsString('implementEmbedBridge', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $runtime);
        $this->assertStringContainsString('__superglobals__mirror_process_environ', $runtime);
        $this->assertStringNotContainsString('implementThinKernelBridge', $runtime);
        $this->assertStringNotContainsString('isThinStandaloneAotMain', $runtime);
        $this->assertStringNotContainsString('JitEnvironMirrorKernel', $runtime);
        $this->assertStringNotContainsString('EnvironMirrorRuntimeUserScriptCstr', $runtime);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $runtime);
    }

    public function testSuperglobalRefreshUserScriptRoutesEnvironMirrorRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitSuperglobalRefreshKernel.php');
        $this->assertStringContainsString('EnvironMirrorRuntime::ensureLinked', $source);
        $this->assertStringContainsString('EnvironMirrorRuntime::emitFillCall', $source);
        $this->assertStringNotContainsString('EnvironMirrorUserScriptLlvm', $source);
    }

    public function testEnvironMirrorNativeJitHelperUsesVmEnvEnvironNative(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../ext/standard/EnvironMirrorNativeJitHelper.php');
        $this->assertStringContainsString('VmEnvEnvironNative::mirrorIntoNativeHashtable', $helper);
        $this->assertStringContainsString('Superglobals::applyProcessEnvironMirror', $helper);

        $vmEnviron = (string) file_get_contents(__DIR__.'/../../ext/standard/VmEnvEnvironNative.php');
        $this->assertStringContainsString('enumerate()', $vmEnviron);
        $this->assertStringContainsString('phpc_native_ht_set_string_key', $vmEnviron);
        $this->assertStringNotContainsString('phpc_native_environ_mirror_into_ht', $vmEnviron);
    }

    public function testUserScriptCstrDeleted(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/EnvironMirrorRuntimeUserScriptCstr.php');
    }

    public function testEnvironMirrorRuntimeCentralizesUserScriptLlvm(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/EnvironMirrorUserScriptLlvm.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/EnvironLibcWalkJit.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/JitEnvironMirrorKernel.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../ext/standard/phpc_native_environ_mirror_into_ht.php');
        $this->assertFileExists(__DIR__.'/../../lib/JIT/Builtin/EnvironMirrorRuntime.php');
    }
}
