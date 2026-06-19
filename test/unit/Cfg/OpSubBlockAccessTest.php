<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCfg\Block;
use PHPCfg\Op\Stmt\JumpIf;
use PHPCfg\Operand\Literal;
use PHPCompiler\Cfg\OpSubBlockAccess;
use PHPUnit\Framework\TestCase;

/** @covers issue #1492 bootstrap spine — variable property syntax avoidance */
final class OpSubBlockAccessTest extends TestCase
{
    public function testPropertyValueReadsPublicCfgOpProperty(): void
    {
        $ifBlock = new Block();
        $elseBlock = new Block();
        $op = new JumpIf(new Literal(true), $ifBlock, $elseBlock);

        $this->assertSame($ifBlock, OpSubBlockAccess::propertyValue($op, 'if'));
        $this->assertSame($elseBlock, OpSubBlockAccess::propertyValue($op, 'else'));
        $this->assertNull(OpSubBlockAccess::propertyValue($op, 'missing'));
    }

    public function testWalkSubBlocksVisitsIfAndElse(): void
    {
        $ifBlock = new Block();
        $elseBlock = new Block();
        $op = new JumpIf(new Literal(true), $ifBlock, $elseBlock);
        $visited = [];

        OpSubBlockAccess::walkSubBlocks($op, static function (Block $block) use (&$visited): void {
            $visited[] = $block;
        });

        $this->assertSame([$ifBlock, $elseBlock], $visited);
    }
}
