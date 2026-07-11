<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** UndefinedPropertyFetch routes warnings through UndefinedPropertyFetchJitHelper PHP (#15752). */
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

    public function testUndefinedPropertyFetchRuntimeCompilesJitHelper(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/UndefinedPropertyFetchRuntime.php');
        $this->assertStringContainsString('UndefinedPropertyFetchJitHelper', $runtime);
        $this->assertStringContainsString('ensureJitHelperCompiled', $runtime);
        $this->assertStringContainsString('emitWarning', $runtime);
    }

    public function testUndefinedPropertyFetchJitHelperDefinesEmitWarning(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/UndefinedPropertyFetchJitHelper.php');
        $this->assertStringContainsString('emitWarning', $source);
        $this->assertStringContainsString('compiler_language_warning', $source);
        $this->assertStringContainsString('warningMessage', $source);
    }
}
