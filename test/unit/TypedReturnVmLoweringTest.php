<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * bin/jit.php VM-fallback when user code declares non-void return types (#55, #2055, #58).
 *
 * MCJIT native return ABI still segfaults; VM + AOT execute match Zend.
 */
final class TypedReturnVmLoweringTest extends TestCase
{
    public function testUntypedFunctionDoesNotRequireVmLowering(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
function greet() {
    return 'ok';
}
echo greet();
PHP,
            'untyped_return.php'
        );
        $this->assertNotNull($block);
        $this->assertFalse(Block::containsTypedNonVoidReturnOpcodes($block));
        $this->assertFalse(Block::requiresVmLowering($block));
    }

    public function testTypedStringReturnRequiresVmLowering(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
class C {
    public function label(): string {
        return 'ok';
    }
}
echo (new C())->label();
PHP,
            'typed_method_return.php'
        );
        $this->assertNotNull($block);
        $this->assertTrue(Block::containsTypedNonVoidReturnOpcodes($block));
        $this->assertTrue(Block::requiresVmLowering($block));
    }

    public function testVoidReturnDoesNotRequireVmLowering(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            <<<'PHP'
<?php
function f(): void {
    echo 'ok';
}
f();
PHP,
            'void_return.php'
        );
        $this->assertNotNull($block);
        $this->assertFalse(Block::containsTypedNonVoidReturnOpcodes($block));
        $this->assertFalse(Block::requiresVmLowering($block));
    }
}
