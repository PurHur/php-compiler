<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class NullableStringReturnAbiTest extends TestCase
{
    public function testNullableStringReturnRegistersValuePointerAbi(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php declare(strict_types=1); function f(?string $name): ?string { return $name; }',
            'nullable_return_abi.php'
        );
        $funcBlock = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCDEF === $op->type) {
                $funcBlock = $op->block1;
                break;
            }
        }
        $this->assertNotNull($funcBlock);
        $jit = new JIT($runtime->loadJitContext());
        $jit->compile($block);
        $this->assertSame(
            '__value__*',
            $jit->context->functionReturnType['f'] ?? null,
            'nullable string return must use __value__* ABI'
        );
    }
}
