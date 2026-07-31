<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * ReadonlyRaise routes pending state through ReadonlyRaiseJitHelper via JitVmHelperLink (#9522, #26041).
 */
final class ReadonlyRaiseRuntimeShrinkTest extends TestCase
{
    public function testReadonlyRaiseUsesJitHelperNotLlvmGlobals(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/ReadonlyRaise.php');
        $this->assertStringContainsString('ReadonlyRaiseJitHelper', $source);
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('JitVmHelperLink::lookupCompiled', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('new JIT(', $source);
        $this->assertStringNotContainsString('use PHPCompiler\\JIT;', $source);
        $this->assertStringNotContainsString("addGlobal(\$i8, 'phpc_jit_pending_flag')", $source);
        $this->assertStringNotContainsString("addGlobal(\$msgTy, 'phpc_jit_pending_msg')", $source);
        $this->assertFileExists(__DIR__.'/../../ext/standard/ReadonlyRaiseJitHelper.php');
    }
}
