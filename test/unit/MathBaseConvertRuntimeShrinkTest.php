<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\standard\MathBaseConvertJitHelper;
use PHPCompiler\ext\standard\VmMath;
use PHPUnit\Framework\TestCase;

/** MathBaseConvert JIT routes through MathBaseConvertJitHelper PHP not MathBaseConvertJit LLVM (#9584). */
final class MathBaseConvertRuntimeShrinkTest extends TestCase
{
    public function testMathBaseConvertJitFileDeleted(): void
    {
        $this->assertFileDoesNotExist(__DIR__.'/../../lib/JIT/Builtin/MathBaseConvertJit.php');
    }

    public function testMathBaseConvertRoutesThroughRuntimeBridge(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathBaseConvert.php');
        $this->assertStringContainsString('MathBaseConvertRuntime', $source);
        $this->assertStringNotContainsString('MathBaseConvertJit', $source);
    }

    public function testMathBaseConvertRuntimeUsesJitHelperNotLlvmLoops(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathBaseConvertRuntime.php');
        $this->assertStringContainsString('MathBaseConvertJitHelper', $source);
        $this->assertStringContainsString('NestedJitCompileScope', $source);
        $this->assertStringNotContainsString('emitBaseToZvalCore', $source);
        $this->assertStringNotContainsString('emitDigitValue', $source);
        $this->assertStringNotContainsString('sgen_loop_head', $source);
        $this->assertLessThan(320, \substr_count($source, "\n") + 1);
    }

    public function testMathBaseConvertJitHelperMatchesVmMath(): void
    {
        MathBaseConvertJitHelper::resetForTest();
        $this->assertSame(
            VmMath::baseConvert('1010', 2, 10),
            MathBaseConvertJitHelper::baseConvert('1010', 2, 10)
        );

        MathBaseConvertJitHelper::resetForTest();
        $tag = MathBaseConvertJitHelper::parseBaseToZval('ff', 16);
        $this->assertSame(0, $tag);
        $this->assertSame(255, MathBaseConvertJitHelper::lastLong());
    }
}
