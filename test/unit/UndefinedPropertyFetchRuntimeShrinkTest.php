<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * UndefinedPropertyFetch NestedJIT via JitVmHelperLink::ensureCompiled (#23174 / peer #23143).
 */
final class UndefinedPropertyFetchRuntimeShrinkTest extends TestCase
{
    public function testUndefinedPropertyFetchHelperUsesRuntimeNotInlineTriggerError(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/UndefinedPropertyFetchHelper.php');
        $this->assertStringContainsString('UndefinedPropertyFetchRuntime', $helper);
        $this->assertStringContainsString('UndefinedPropertyFetchJitHelper', $helper);
        $this->assertStringNotContainsString('__compiler_trigger_error', $helper);
        $this->assertStringNotContainsString('ErrorReporter::E_WARNING', $helper);
    }

    public function testUndefinedPropertyFetchRuntimeUsesJitVmHelperLink(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/UndefinedPropertyFetchRuntime.php');
        $this->assertStringContainsString('UndefinedPropertyFetchJitHelper', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $runtime);
        $this->assertStringContainsString('emitWarning', $runtime);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $runtime);
        $this->assertStringNotContainsString('parseAndCompile', $runtime);
        $this->assertStringNotContainsString('new JIT(', $runtime);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $runtime);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $runtime);
    }

    public function testUndefinedPropertyFetchJitHelperDefinesEmitWarning(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/UndefinedPropertyFetchJitHelper.php');
        $this->assertStringContainsString('emitWarning', $source);
        $this->assertStringContainsString('compiler_language_warning', $source);
        $this->assertStringContainsString('warningMessage', $source);
    }
}
