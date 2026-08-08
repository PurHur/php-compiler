<?php

declare(strict_types=1);

namespace PHPCompiler\Test\Unit;

use PHPCfg\Operand\Literal;
use PHPCompiler\OpCode;
use PHPCompiler\Runtime;
use PHPUnit\Framework\TestCase;

/**
 * Include inherit with PHP_COMPILER_INCLUDE_SCOPE_REMAP=0 must not corrupt CONST_FETCH
 * literals (TimezoneAbbreviationsData / spine {main}, #29111).
 */
final class IncludeScopeConstFetchLiteralTest extends TestCase
{
    public function testRemapOffDoesNotReplaceCalleeTrueWithParentDefined(): void
    {
        $runtime = new Runtime();
        $parent = $runtime->parseAndCompile(
            '<?php defined("PHP_VERSION"); defined("PHP_OS");',
            'issue_29111_parent.php'
        );
        $child = $runtime->parseAndCompile(
            '<?php return ["dst" => true];',
            'issue_29111_child.php'
        );
        $this->assertNotNull($parent);
        $this->assertNotNull($child);

        $before = [];
        foreach ($child->opCodes as $i => $op) {
            if (OpCode::TYPE_CONST_FETCH !== $op->type) {
                continue;
            }
            $name = $child->getOperand($op->arg2);
            $this->assertInstanceOf(Literal::class, $name);
            $before[$i] = $name->value;
        }
        $this->assertNotEmpty($before);
        $this->assertContains('true', $before);

        $child->inheritScopeFrom($parent, false);

        foreach ($child->opCodes as $i => $op) {
            if (OpCode::TYPE_CONST_FETCH !== $op->type || !isset($before[$i])) {
                continue;
            }
            $name = $child->getOperand($op->arg2);
            $this->assertInstanceOf(Literal::class, $name);
            $this->assertSame(
                $before[$i],
                $name->value,
                'CONST_FETCH op '.$i.' must keep callee literal after inheritScopeFrom(REMAP=0)'
            );
            $this->assertNotSame(
                'defined',
                $name->value,
                'parent defined() name must not steal CONST_FETCH slot (#29111)'
            );
        }
    }

    public function testRemapOffStillAllowsVarOperandSharedSlots(): void
    {
        $runtime = new Runtime();
        $parent = $runtime->parseAndCompile(
            '<?php $x = 1;',
            'issue_29111_var_parent.php'
        );
        $child = $runtime->parseAndCompile(
            '<?php $y = 2;',
            'issue_29111_var_child.php'
        );
        $this->assertNotNull($parent);
        $this->assertNotNull($child);

        $child->inheritScopeFrom($parent, false);

        $parentSlots = [];
        foreach ($parent->scopedOperands() as $op) {
            $slot = $parent->slotForOperand($op);
            if (null !== $slot) {
                $parentSlots[$slot] = true;
            }
        }
        $shared = 0;
        foreach ($child->scopedOperands() as $op) {
            $slot = $child->slotForOperand($op);
            if (null !== $slot && isset($parentSlots[$slot])) {
                ++$shared;
            }
        }
        $this->assertGreaterThan(
            0,
            $shared,
            'REMAP=0 must still share some VarOperand slot indices for spine pace (#22642)'
        );
    }
}
