<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

/** KIND_VALUE native operands must not LLVM-load a non-pointer (#11809 inventory argv emit). */
final class JitValueBoxNativeKindValueTest extends TestCase
{
    public function testValuePtrFromNativeVariableUsesKindValueDirectly(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/JitValueBox.php');
        $this->assertStringContainsString(
            'Variable::KIND_VALUE === $var->kind',
            $source
        );
        $this->assertStringContainsString(
            '? $var->value',
            $source
        );
        $this->assertStringNotContainsString(
            '$native = $context->builder->load($var->value);',
            $source
        );
    }

    /** By-ref NestedJIT args typed NATIVE_LONG but stored as __value__* (#22642). */
    public function testValuePtrFromVariableShortCircuitsValueBoxPointerBeforeNativeBox(): void
    {
        $source = (string) file_get_contents(__DIR__.'/../../lib/JIT/JitValueBox.php');
        $this->assertStringContainsString('#22642', $source);
        $this->assertStringContainsString(
            'By-ref NestedJIT formals / caller args are often',
            $source
        );
        $this->assertStringContainsString("'__value__*' === \$llvmType", $source);
        $this->assertStringContainsString("'__value__*' === \$storageTy", $source);
    }
}
