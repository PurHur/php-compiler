<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * NonObjectPropertyFetch NestedJIT via JitVmHelperLink::ensureCompiled (#24526 / peer #23174).
 */
final class NonObjectPropertyFetchRuntimeShrinkTest extends TestCase
{
    public function testNonObjectPropertyFetchHelperUsesRuntimeNotInlineTriggerError(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/NonObjectPropertyFetchHelper.php');
        $this->assertStringContainsString('NonObjectPropertyFetchRuntime', $helper);
        $this->assertStringContainsString('NonObjectPropertyFetchJitHelper', $helper);
        $this->assertStringNotContainsString('__compiler_trigger_error', $helper);
        $this->assertStringNotContainsString('ErrorReporter::E_WARNING', $helper);
    }

    public function testNonObjectPropertyFetchRuntimeUsesJitVmHelperLink(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/NonObjectPropertyFetchRuntime.php');
        $this->assertStringContainsString('NonObjectPropertyFetchJitHelper', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $runtime);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $runtime);
        $this->assertStringContainsString('emitWarning', $runtime);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $runtime);
        $this->assertStringNotContainsString('parseAndCompile', $runtime);
        $this->assertStringNotContainsString('new JIT(', $runtime);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $runtime);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT\\NestedJitCompileScope;', $runtime);
        $this->assertStringNotContainsString('UserScriptAotDeferNestedJit', $runtime);
    }

    public function testNonObjectPropertyFetchJitHelperDefinesEmitWarning(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/NonObjectPropertyFetchJitHelper.php');
        $this->assertStringContainsString('emitWarning', $source);
        $this->assertStringContainsString('compiler_language_warning', $source);
        $this->assertStringContainsString('warningMessage', $source);
    }
}
