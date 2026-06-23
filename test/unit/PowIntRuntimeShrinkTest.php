<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\PowIntJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** PowIntRuntime routes int**int through PowIntJitHelper PHP (#9515). */
final class PowIntRuntimeShrinkTest extends TestCase
{
    public function testPowIntRuntimeUsesJitHelperBridgeNotLlvmLoop(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/PowIntRuntime.php');
        $this->assertStringContainsString('PowIntJitHelper', $source);
        $this->assertStringContainsString('NestedJitCompileScope', $source);
        $this->assertStringNotContainsString('pow_int_loop_head', $source);
        $this->assertStringNotContainsString('mulOverflows', $source);
        $this->assertStringNotContainsString('private static function implementPowInt(', $source);
    }

    public function testVmMathPowIntMatchesPhpOperator(): void
    {
        $this->assertSame(8, VmMath::powInt(2, 3));
        $this->assertSame(1, VmMath::powInt(-1, 0));
        $this->assertSame(-27, VmMath::powInt(-3, 3));
        $result = VmMath::powInt(2, 62);
        $this->assertTrue(\is_int($result) || \is_float($result));
    }

    public function testPowIntJitHelperComputeTagging(): void
    {
        PowIntJitHelper::resetForTest();
        $this->assertSame(0, PowIntJitHelper::compute(2, 3));
        $this->assertSame(8, PowIntJitHelper::resultInt());

        PowIntJitHelper::resetForTest();
        $tag = PowIntJitHelper::compute(2, 62);
        if (0 === $tag) {
            $this->assertIsInt(PowIntJitHelper::resultInt());
        } else {
            $this->assertIsFloat(PowIntJitHelper::resultFloat());
        }
    }

    public function testJitPowStillUsesPowIntRuntime(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/JitPow.php');
        $this->assertStringContainsString('PowIntRuntime', $source);
        $this->assertStringContainsString('__phpc_pow_int', $source);
    }
}
