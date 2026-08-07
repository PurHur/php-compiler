<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * gettext JIT lowering routes through GettextJitHelper PHP; NestedJIT via JitVmHelperLink (#9859, #26226, #27391).
 */
final class StringGettextRuntimeShrinkTest extends TestCase
{
    public function testStringGettextRoutesThroughRuntimeNotJitMonolith(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGettext.php');
        $this->assertStringContainsString('StringGettextRuntime', $source);
        $this->assertStringNotContainsString('StringGettextJit', $source);

        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/StringGettextRuntime.php');
        $this->assertStringContainsString('GettextJitHelper', $runtime);
        $this->assertStringContainsString('VmGettextNative', $runtime);
        $this->assertStringContainsString('HELPER_BUNDLE', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiledBundle', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $runtime);
        $this->assertStringContainsString('/ext/gettext/GettextJitHelper.php', $runtime);
        $this->assertStringContainsString('/ext/gettext/VmGettextNative.php', $runtime);
        $this->assertStringNotContainsString('/ext/gettext/VmGettextPure.php', $runtime);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $runtime);
        $this->assertStringNotContainsString('parseAndCompile', $runtime);
        $this->assertStringNotContainsString('new JIT(', $runtime);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $runtime);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $runtime);
        $this->assertStringNotContainsString('StringGettextStandaloneLlvm', $runtime);
        $this->assertStringNotContainsString('phpc_gettext_bound_dir', $runtime);
        $this->assertStringNotContainsString('ensureLibc', $runtime);

        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringGettextJit.php');
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/StringGettextStandaloneLlvm.php');
        $this->assertFileExists(__DIR__.'/../../ext/gettext/GettextJitHelper.php');
    }

    public function testGettextJitHelperDelegatesToVmGettextNative(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/gettext/GettextJitHelper.php');
        $this->assertStringContainsString('VmGettextNative::gettext', $source);
        $this->assertStringContainsString('VmGettextNative::bindtextdomain', $source);
        $this->assertStringContainsString('VmGettextNative::textdomain', $source);
    }

    public function testVmGettextNativeUsesPureMoReaderNotLibintlFfi(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/gettext/VmGettextNative.php');
        $this->assertStringContainsString('VmGettextPure', $source);
        $this->assertStringNotContainsString('FFI::cdef', $source);
        $this->assertStringNotContainsString('extension_loaded(\'ffi\')', $source);
        $this->assertFileExists(__DIR__.'/../../ext/gettext/VmGettextPure.php');
    }
}
