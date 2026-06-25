<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/** ScalarDimFetchRuntime routes warnings through ScalarDimFetchJitHelper PHP (#10343). */
final class ScalarDimFetchRuntimeShrinkTest extends TestCase
{
    public function testScalarDimFetchRuntimeUsesCompiledJitHelperNotInlineSwitch(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ScalarDimFetchRuntime.php');
        $this->assertStringContainsString('ScalarDimFetchJitHelper', $source);
        $this->assertStringContainsString('emitWarningForJitType', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureBridge', $source);
        $this->assertStringNotContainsString('__compiler_trigger_error', $source);
        $this->assertStringNotContainsString('scalar_dim_fetch_warn_t', $source);
    }

    public function testScalarDimFetchJitHelperDefinesEmitWarning(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/VM/ScalarDimFetchJitHelper.php');
        $this->assertStringContainsString('emitWarningForJitType', $source);
        $this->assertStringContainsString('compiler_language_warning', $source);
    }
}
