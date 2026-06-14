<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

final class NullableNeNullTernaryRewriteTest extends TestCase
{
    public function testReturnNeNullTernaryRewritesToIdenticalJumpIf(): void
    {
        $runtime = new Runtime();
        $block = $runtime->parseAndCompile(
            '<?php declare(strict_types=1); function f(?string $name): ?string { return null !== $name ? $name : null; }',
            'ne_null.php'
        );
        $funcBlock = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCDEF === $op->type) {
                $funcBlock = $op->block1;
                break;
            }
        }
        $this->assertNotNull($funcBlock);
        $hasCoalesce = false;
        $hasTernaryJump = false;
        foreach ($funcBlock->opCodes as $op) {
            if (OpCode::TYPE_COALESCE === $op->type) {
                $hasCoalesce = true;
            }
            if (OpCode::TYPE_JUMPIF === $op->type) {
                $hasTernaryJump = true;
            }
        }
        $this->assertTrue($hasCoalesce, 'expected TYPE_COALESCE for rewritten ?: return');
        $this->assertFalse($hasTernaryJump, '?: ternary JumpIf should be rewritten away');
    }
}
