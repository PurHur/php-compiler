<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\ext\bcmath\BcmathJitHelper;
use PHPUnit\Framework\TestCase;

/** BcmathJit must route __compiler_bc* through BcmathJitHelper PHP not libc LLVM (#9235). */
final class BcmathRuntimeShrinkTest extends TestCase
{
    public function testBcmathJitUsesBcmathJitHelperNotLlvmLibc(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/Builtin/BcmathJit.php');
        $this->assertStringContainsString('BcmathJitHelper', $source);
        $this->assertStringContainsString('NestedJitCompileScope', $source);
        $this->assertStringNotContainsString('strtod', $source);
        $this->assertStringNotContainsString('snprintf', $source);
        $this->assertStringNotContainsString('__phpc_bcmath_read_double', $source);
        $this->assertStringNotContainsString('__phpc_bcmath_format', $source);
        $this->assertStringNotContainsString('__phpc_bcmath_default_scale', $source);
        $lineCount = \substr_count($source, "\n") + 1;
        $this->assertLessThan(300, $lineCount);
        $this->assertGreaterThan(180, 483 - $lineCount);
    }

    public function testBcmathJitHelperDelegatesToVmBcmath(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../ext/bcmath/BcmathJitHelper.php');
        $this->assertStringContainsString('VmBcmath::add', $source);
        $this->assertStringContainsString('VmBcmath::comp', $source);
        $this->assertStringContainsString('VmBcmath::round', $source);
    }

    public function testBcmathJitHelperMatchesVmBcmathSemantics(): void
    {
        BcmathJitHelper::bcscaleAsInt(2, 1);
        $this->assertSame('6.91', BcmathJitHelper::add('1.234', '5.678', 0, -1));
        $this->assertSame('9.00', BcmathJitHelper::mul('3', '3', 0, -1));
        $this->assertSame(0, BcmathJitHelper::comp('1.00', '1.0', 0, -1));
        BcmathJitHelper::bcscaleAsInt(0, 1);
    }
}
