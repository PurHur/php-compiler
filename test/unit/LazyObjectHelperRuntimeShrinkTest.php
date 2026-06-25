<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\VM\VmLazyObject;
use PHPUnit\Framework\TestCase;

/** LazyObjectHelper routes LLVM through LazyObjectHelperLlvm + VmLazyObject PHP guards (#10267). */
final class LazyObjectHelperRuntimeShrinkTest extends TestCase
{
    public function testLazyObjectHelperIsThinTrampoline(): void
    {
        $helper = (string) file_get_contents(__DIR__.'/../../lib/JIT/LazyObjectHelper.php');
        $this->assertStringContainsString('LazyObjectHelperLlvm::registerLazyObject', $helper);
        $this->assertStringContainsString('LazyObjectHelperLlvm::emitEnsureInitialized', $helper);
        $this->assertStringNotContainsString('emitInitBody', $helper);
        $this->assertStringNotContainsString('lazy_init_proxy_', $helper);
        $this->assertLessThan(45, substr_count($helper, "\n"));
    }

    public function testLazyObjectHelperLlvmUsesVmLazyObjectFieldNames(): void
    {
        $llvm = (string) file_get_contents(__DIR__.'/../../lib/JIT/LazyObjectHelperLlvm.php');
        $this->assertStringContainsString('VmLazyObject::', $llvm);
        $this->assertStringNotContainsString("'lazy_pending'", $llvm);
        $this->assertStringNotContainsString("'lazy_ghost'", $llvm);
    }

    public function testVmLazyObjectHeaderFields(): void
    {
        $this->assertSame(
            ['lazy_pending', 'lazy_ghost', 'lazy_init_index', 'constructed'],
            VmLazyObject::objectHeaderLazyFields()
        );
    }
}
