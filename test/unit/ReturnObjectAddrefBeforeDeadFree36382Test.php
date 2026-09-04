<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPUnit\Framework\TestCase;

/**
 * RETURN must addref before freeDeadVariables so post-new / clone props survive (#36382).
 *
 * @group unit
 */
final class ReturnObjectAddrefBeforeDeadFree36382Test extends TestCase
{
    public function testTypeReturnAddrefBeforeFreeDeadVariables(): void
    {
        $src = (string) file_get_contents(__DIR__.'/../../lib/JIT/Concern/CompileBlockInternal.php');
        $pos = strpos($src, 'case OpCode::TYPE_RETURN:');
        $this->assertNotFalse($pos);
        $chunk = substr($src, $pos, 12000);
        $freePos = strpos($chunk, 'freeDeadVariables($func, $returnBlock, $block, $returnOperand)');
        $this->assertNotFalse($freePos, 'expected freeDeadVariables(..., $returnOperand) on TYPE_RETURN');
        // Prefer the addref immediately before that free (not inline-include addrefs earlier).
        $beforeFree = substr($chunk, 0, $freePos);
        $addrefPos = strrpos($beforeFree, '$return->addref();');
        $this->assertNotFalse($addrefPos);
        $this->assertLessThan(
            $freePos,
            $addrefPos,
            'TYPE_RETURN must addref the return value before freeDeadVariables (#36382)'
        );
    }
}
