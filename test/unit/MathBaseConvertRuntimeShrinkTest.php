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
        $this->assertStringContainsString('JitVmHelperLink::ensureCompiled', $source);
        $this->assertStringContainsString('BasicBlockHelper::tryGetInsertBlock', $source);
        $this->assertStringContainsString('BasicBlockHelper::restoreInsertBlock', $source);
        $this->assertStringContainsString('NestedJitCompileScope::isActive()', $source);
        $this->assertStringContainsString('declareRuntimeAbisForNestedJit', $source);
        $this->assertStringNotContainsString('NestedJitCompileScope::run', $source);
        $this->assertStringNotContainsString('parseAndCompile', $source);
        $this->assertStringNotContainsString('emitBaseToZvalCore', $source);
        $this->assertStringNotContainsString('emitDigitValue', $source);
        $this->assertStringNotContainsString('sgen_loop_head', $source);
        $this->assertLessThan(400, \substr_count($source, "\n") + 1);
        $this->assertStringContainsString('getNamedFunction', $source);
        $this->assertStringContainsString('#32420', $source);

        $module = (string) file_get_contents(__DIR__.'/../../ext/standard/Module.php');
        $this->assertStringContainsString('#32420', $module);
        $this->assertStringNotContainsString("lookupFunction('phpc_basetozval_result')", $module);
        $this->assertStringNotContainsString("addFunction('phpc_basetozval_result'", $module);
    }


    /** NestedJIT must not call VmMath — thin AOT stubs that to null (#26884). */
    public function testMathBaseConvertJitHelperIsNestedJitSafeInline(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/standard/MathBaseConvertJitHelper.php');
        $this->assertStringContainsString('radixDigitChar', $source);
        $this->assertStringContainsString('parseRec', $source);
        $this->assertStringContainsString('substr(', $source);
        $this->assertDoesNotMatchRegularExpression('/VmMath::\w+\s*\(/', $source);
        $this->assertStringNotContainsString('ctype_space', $source);
        $this->assertStringNotContainsString('ctype_digit', $source);
        $this->assertStringNotContainsString('sprintf(', $source);
        $this->assertStringNotContainsString('\\ord(', $source);
        $runtime = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/MathBaseConvertRuntime.php');
        $this->assertStringNotContainsString('stringFromCstr', $runtime);
        $this->assertStringContainsString('JitNestedHelperCoerce::callHelper', $runtime);
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

    /** php-src ext/standard/math.c — hexdec/bindec overflow double (#10452). */
    public function testBaseToZvalLargeOverflowMatchesZendVarExport(): void
    {
        $hex = VmMath::baseToZval('FFFFFFFFFFFFFFFF', 16);
        $this->assertIsFloat($hex);
        $this->assertSame('1.8446744073709552E+19', \var_export($hex, true));

        $bin = VmMath::baseToZval(\str_repeat('1', 65), 2);
        $this->assertIsFloat($bin);
        $this->assertSame('3.6893488147419103E+19', \var_export($bin, true));
    }
}
