<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/** ctype JIT helpers route through VmCtype PHP, not hand-written LLVM (#9234). */
final class CtypeJitRuntimeShrinkTest extends TestCase
{
    public function testCtypeJitHelperDelegatesToVmCtype(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/ctype/CtypeJitHelper.php');
        $this->assertStringContainsString('VmCtype::checkString', $source);
        $this->assertStringContainsString('VmCtype::checkInt', $source);
    }

    public function testJitCtypeRoutesThroughCtypeRuntime(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../ext/ctype/JitCtype.php');
        $this->assertStringContainsString('CtypeRuntime::ensureLinked', $source);
        $this->assertStringNotContainsString('CtypeJit', $source);
    }

    public function testCtypeRuntimeRoutesThroughCtypeJitHelper(): void
    {
        $source = (string) \file_get_contents(__DIR__.'/../../lib/JIT/Builtin/CtypeRuntime.php');
        $this->assertStringContainsString('CtypeJitHelper', $source);
        $this->assertStringNotContainsString('emitIsDigit', $source);
        $this->assertStringNotContainsString('emitCheckChar', $source);
    }

    public function testCtypeJitHelperSemanticsMatchVmCtype(): void
    {
        $this->assertSame(1, \PHPCompiler\ext\ctype\CtypeJitHelper::checkString('A', \PHPCompiler\ext\ctype\VmCtype::KIND_ALPHA));
        $this->assertSame(0, \PHPCompiler\ext\ctype\CtypeJitHelper::checkString('5', \PHPCompiler\ext\ctype\VmCtype::KIND_ALPHA));
        $this->assertSame(1, \PHPCompiler\ext\ctype\CtypeJitHelper::checkInt(97, \PHPCompiler\ext\ctype\VmCtype::KIND_LOWER, 0, 0));
    }
}
