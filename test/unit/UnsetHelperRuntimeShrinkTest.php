<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\VmUnset;
use PHPUnit\Framework\TestCase;

/** UnsetHelper routes LLVM through UnsetHelperLlvm + VmUnset PHP guards (#10238). */
final class UnsetHelperRuntimeShrinkTest extends TestCase
{
    public function testUnsetHelperIsThinTrampoline(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/UnsetHelper.php');
        $this->assertStringContainsString('UnsetHelperLlvm::compileOffset', $helper);
        $this->assertStringNotContainsString('compilePropertyUnset', $helper);
        $this->assertStringNotContainsString('compileValueBoxOffsetUnset', $helper);
        $this->assertLessThan(35, substr_count($helper, "\n"));
    }

    public function testUnsetHelperLlvmUsesVmUnsetGuards(): void
    {
        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/UnsetHelperLlvm.php');
        $this->assertStringContainsString('VmUnset::', $llvm);
        $this->assertStringNotContainsString('isDefinitelyScalarContainerAtCompileTime', $llvm);
        $this->assertStringNotContainsString('private const ERROR_NON_ARRAY', $llvm);
    }

    public function testVmUnsetResolveDeclaringClass(): void
    {
        $this->assertSame('Foo', VmUnset::resolveDeclaringClass('Foo', null, ''));
        $this->assertSame('Bar', VmUnset::resolveDeclaringClass(null, 'Bar', ''));
        $this->assertSame('Baz', VmUnset::resolveDeclaringClass(null, null, 'Baz'));
        $this->assertSame('object', VmUnset::resolveDeclaringClass(null, null, ''));
    }

    public function testVmUnsetScalarErrorMessages(): void
    {
        $this->assertSame(
            VmUnset::ERROR_STRING_OFFSET,
            VmUnset::scalarUnsetDimErrorMessage(\PHPCompiler\JIT\Variable::TYPE_STRING)
        );
        $this->assertSame(
            VmUnset::ERROR_NON_ARRAY,
            VmUnset::scalarUnsetDimErrorMessage(\PHPCompiler\JIT\Variable::TYPE_NULL)
        );
    }
}
