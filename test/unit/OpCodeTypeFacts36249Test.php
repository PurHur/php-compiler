<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPUnit\Framework\TestCase;

/**
 * OpCode carries php-types facts stamped at compile time (#36249).
 */
final class OpCodeTypeFacts36249Test extends TestCase
{
    public function testBinaryOpStampsResultAndArgTypes(): void
    {
        $runtime = new Runtime();
        $block = $runtime->compile($runtime->parse('<?php $a = 1 + 2;', 'opcodetype.php'));
        $this->assertInstanceOf(Block::class, $block);

        $plus = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_PLUS === $op->type) {
                $plus = $op;
                break;
            }
        }
        $this->assertInstanceOf(OpCode::class, $plus, 'expected TYPE_PLUS in main block');
        $this->assertInstanceOf(\PHPTypes\Type::class, $plus->resultType);
        $this->assertGreaterThanOrEqual(2, \count($plus->argTypes));
        $this->assertInstanceOf(\PHPTypes\Type::class, $plus->argTypes[1] ?? null);
        $this->assertInstanceOf(\PHPTypes\Type::class, $plus->argTypes[2] ?? null);
    }

    public function testFuncdefBodyOpcodesAreStamped(): void
    {
        $runtime = new Runtime();
        $block = $runtime->compile($runtime->parse(
            '<?php function f(int $x): int { return $x + 1; } f(2);',
            'opcodetype_func.php'
        ));
        $this->assertInstanceOf(Block::class, $block);

        $funcdef = null;
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_FUNCDEF === $op->type) {
                $funcdef = $op;
                break;
            }
        }
        $this->assertInstanceOf(OpCode::class, $funcdef);
        $this->assertInstanceOf(Block::class, $funcdef->block1);

        $returnPlus = null;
        foreach ($funcdef->block1->opCodes as $op) {
            if (OpCode::TYPE_PLUS === $op->type) {
                $returnPlus = $op;
                break;
            }
        }
        $this->assertInstanceOf(OpCode::class, $returnPlus);
        $this->assertNotEmpty($returnPlus->argTypes);
    }
}
