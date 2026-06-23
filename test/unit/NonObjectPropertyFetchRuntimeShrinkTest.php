<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** NonObjectPropertyFetch routes warnings through NonObjectPropertyFetchJitHelper PHP (#10268). */
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

    public function testNonObjectPropertyFetchRuntimeCompilesJitHelper(): void
    {
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/NonObjectPropertyFetchRuntime.php');
        $this->assertStringContainsString('NonObjectPropertyFetchJitHelper', $runtime);
        $this->assertStringContainsString('ensureJitHelperCompiled', $runtime);
        $this->assertStringContainsString('emitWarning', $runtime);
    }

    public function testNonObjectPropertyFetchJitHelperDefinesEmitWarning(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/NonObjectPropertyFetchJitHelper.php');
        $this->assertStringContainsString('emitWarning', $source);
        $this->assertStringContainsString('compiler_language_warning', $source);
        $this->assertStringContainsString('warningMessage', $source);
    }
}
