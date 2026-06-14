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
        $hasIdentical = false;
        $hasNotIdentical = false;
        foreach ($funcBlock->opCodes as $op) {
            if (OpCode::TYPE_IDENTICAL === $op->type) {
                $hasIdentical = true;
            }
            if (OpCode::TYPE_NOT_IDENTICAL === $op->type) {
                $hasNotIdentical = true;
            }
        }
        $this->assertTrue($hasIdentical, 'expected TYPE_IDENTICAL for rewritten ?: return');
        $this->assertFalse($hasNotIdentical, 'TYPE_NOT_IDENTICAL should be rewritten away');
    }
}
