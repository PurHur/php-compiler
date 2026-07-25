<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCompiler\Block;
use PHPCompiler\JIT\Context;
use PHPCompiler\OpCode;
use PHPCompiler\ext\standard\IncludeBindingJitHelper;
use PHPUnit\Framework\TestCase;

/**
 * ASSIGN slots missing from block scope must not TypeError in OperandName::resolve
 * (honest full-spine AOT / #22642 r15: IncludeBindingJitHelper.php:270).
 */
final class IncludeBindingNullOperandTest extends TestCase
{
    public function testLastAssignSkipsMissingScopeSlots(): void
    {
        $block = new Block(null);
        $assign = new OpCode(OpCode::TYPE_ASSIGN);
        // Non-null indices that are not present in $block->scope → getOperand() null.
        $assign->arg1 = 42;
        $assign->arg2 = 43;
        $block->opCodes[] = $assign;
        $block->nOpCodes = 1;

        $context = $this->createMock(Context::class);
        $context->method('hasVariableOpInScopes')->willReturn(false);

        $this->assertNull(
            IncludeBindingJitHelper::lastAssignVariableForName($context, $block, 'x')
        );
    }

    public function testCountAssignsSkipsMissingScopeSlots(): void
    {
        $block = new Block(null);
        $assign = new OpCode(OpCode::TYPE_ASSIGN);
        $assign->arg1 = 7;
        $assign->arg2 = null;
        $block->opCodes[] = $assign;
        $block->nOpCodes = 1;

        $this->assertFalse(
            IncludeBindingJitHelper::hasMultipleAssignsInCaller($block, 'x')
        );
    }

    public function testVariableFromCallerAssignConstantSkipsMissingSlots(): void
    {
        $block = new Block(null);
        $assign = new OpCode(OpCode::TYPE_ASSIGN);
        $assign->arg1 = 99;
        $assign->arg2 = 100;
        $assign->arg3 = 0;
        $block->opCodes[] = $assign;
        $block->nOpCodes = 1;

        $context = $this->createMock(Context::class);

        $this->assertNull(
            IncludeBindingJitHelper::variableFromCallerAssignConstant($context, $block, 'name')
        );
    }
}
