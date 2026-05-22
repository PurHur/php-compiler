<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler;

// used as a property type.
// @phan-suppress-next-line PhanUnreferencedUseNormal
use PHPCfg\Func;
use PHPCfg\Block as CfgBlock;
use PHPCfg\Operand;
use PHPCfg\Operand\Literal;
use PHPCfg\Operand\Temporary;
use PHPCfg\Operand\Variable as VarOperand;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

class Block {

    /**
     * @var OpCode[] $opCodes
     */
    public array $opCodes = [];

    public array $blocks = [];

    /** @var Block[] CFG parent blocks (filled during compilation) */
    public array $parents = [];

    public int $nOpCodes = 0;

    public ?Func $func = null;

    public ?CfgBlock $orig;

    /** @var array<int, Operand> */
    public array $scopeOperandBySlot = [];

    public int $scopeSlotCount = 0;

    /** @var array<int, true> */
    public array $argSlots = [];

    /**
     * @var Variable[] $constants
     */
    public array $constants = [];

    public ?Handler $handler = null;

    public bool $inheritUndefinedLocals = false;

    public bool $strictTypes = false;

    /** @var array<int, int> */
    public array $paramTypeConstraints = [];

    /** Resolved absolute paths for TYPE_INCLUDE opcodes (arg3 index, issue #54). */
    public array $literalIncludePaths = [];

    public function __construct(?CfgBlock $block) {
        $this->orig = $block;
    }

    public function getOperand(int $offset): Operand {
        return $this->scopeOperandBySlot[$offset];
    }


    /**
     * @return list<Operand>
     */
    public function scopedOperands(): array
    {
        $operands = [];
        for ($slot = 0; $slot < $this->scopeSlotCount; ++$slot) {
            $operands[] = $this->scopeOperandBySlot[$slot];
        }

        return $operands;
    }

    /**
     * @return list<Operand>
     */
    public function argOperands(): array
    {
        $operands = [];
        for ($slot = 0; $slot < $this->scopeSlotCount; ++$slot) {
            if (isset($this->argSlots[$slot])) {
                $operands[] = $this->scopeOperandBySlot[$slot];
            }
        }

        return $operands;
    }

    public function getVarSlot(Operand $operand, bool $isRead): int {
        for ($slot = 0; $slot < $this->scopeSlotCount; ++$slot) {
            if ($this->scopeOperandBySlot[$slot] === $operand) {
                return $slot;
            }
        }
        $slot = $this->scopeSlotCount++;
        $this->scopeOperandBySlot[$slot] = $operand;
        if ($isRead) {
            $this->argSlots[$slot] = true;
        }

        return $slot;
    }

    public function registerConstant(Operand $operand, Variable $const): int {
        $slot = $this->getVarSlot($operand, false);
        $this->constants[$slot] = $const;

        return $slot;
    }

    public function inheritScopeFrom(Block $parent): void
    {
        $this->scopeOperandBySlot = $parent->scopeOperandBySlot;
        $this->scopeSlotCount = $parent->scopeSlotCount;
        $this->argSlots = $parent->argSlots;
        $this->constants = $parent->constants;
        if ([] !== $parent->literalIncludePaths) {
            $this->literalIncludePaths = $parent->literalIncludePaths;
        }
    }

    public function addOpCode(OpCode ...$ops): void
    {
        foreach ($ops as $op) {
            ++$this->nOpCodes;
            $this->opCodes[] = $op;
        }
    }

    public function findSlot(Operand $op, Frame $frame): ?Variable {
        $byName = self::findVariableInParentFrames($op, $frame);
        if (null !== $byName) {
            return $byName;
        }
        for ($slot = 0; $slot < $this->scopeSlotCount; ++$slot) {
            if ($this->scopeOperandBySlot[$slot] === $op) {
                return $frame->scope[$slot] ?? null;
            }
        }
        if (!\is_null($frame->parent)) {
            return $frame->parent->block->findSlot($op, $frame->parent);
        }

        return null;
    }

    public function slotIndexForVariableName(string $name): ?int
    {
        for ($slot = 0; $slot < $this->scopeSlotCount; ++$slot) {
            if (self::resolveVariableName($this->scopeOperandBySlot[$slot]) === $name) {
                return $slot;
            }
        }

        return null;
    }

    private static function findVariableInParentFrames(Operand $op, Frame $frame): ?Variable
    {
        $name = self::resolveVariableName($op);
        if (null === $name) {
            return null;
        }
        for ($f = $frame; null !== $f; $f = $f->parent) {
            if ('this' === $name && !\empty($f->calledArgs)) {
                return $f->calledArgs[0];
            }
            if (null === $f->block) {
                continue;
            }
            $idx = $f->block->slotIndexForVariableName($name);
            if (null !== $idx && isset($f->scope[$idx])) {
                return $f->scope[$idx];
            }
        }

        return null;
    }

    public function getFrame(Context $context, ?Frame $frame = null): Frame {
        $scope = [];
        for ($pos = 0; $pos < $this->scopeSlotCount; ++$pos) {
            $op = $this->scopeOperandBySlot[$pos];
            if (null !== $frame && 'this' === self::resolveVariableName($op)) {
                if (!\empty($frame->callArgs)) {
                    $scope[$pos] = $frame->callArgs[0];
                    continue;
                }
                if (!\empty($frame->calledArgs)) {
                    $scope[$pos] = $frame->calledArgs[0];
                    continue;
                }
            }

            if (isset($this->constants[$pos])) {
                $scope[$pos] = $this->constants[$pos];
            } elseif (isset($this->argSlots[$pos])) {
                if (\is_null($frame)) {
                    $scope[$pos] = self::initialVariableForOperand($op, $context, $pos, $this);
                    continue;
                }
                $found = false;
                $parent = $frame->block->findSlot($op, $frame);
                if (!\is_null($parent)) {
                    $scope[$pos] = $parent;
                    $found = true;
                }
                if (!$found) {
                    $inherited = self::findVariableInParentFrames($op, $frame);
                    if (null !== $inherited) {
                        $scope[$pos] = $inherited;
                        continue;
                    }
                }
                if (!$found) {
                    $name = self::resolveVariableName($op);
                    if (null !== $name && Superglobals::isSuperglobalName($name)) {
                        $scope[$pos] = self::initialVariableForOperand($op, $context, $pos, $this);
                        continue;
                    }
                    if ($this->inheritUndefinedLocals) {
                        $scope[$pos] = new Variable(Variable::TYPE_UNDEFINED);
                        continue;
                    }
                    throw new \LogicException("Could not resolve argument");
                }
            } else {
                if (null !== $frame) {
                    $inherited = self::findVariableInParentFrames($op, $frame);
                    if (null !== $inherited) {
                        $scope[$pos] = $inherited;
                        continue;
                    }
                }
                if (
                    $this->inheritUndefinedLocals
                    && null !== $frame
                    && isset($frame->scope[$pos])
                ) {
                    $scope[$pos] = $frame->scope[$pos];
                } else {
                    $scope[$pos] = self::initialVariableForOperand($op, $context, $pos, $this);
                }
            }
        }

        $return = new Frame(null, $this, $frame, ...$scope);
        if (!\is_null($frame) && !\is_null($frame->returnVar)) {
            $return->returnVar = $frame->returnVar;
        }

        return $return;
    }

    private static function initialVariableForOperand(
        Operand $op,
        Context $context,
        int $slot,
        self $block
    ): Variable {
        $name = self::resolveVariableName($op);
        if (null !== $name && Superglobals::isSuperglobalName($name)) {
            $existing = $context->getSuperglobal($name);
            if (null !== $existing) {
                return $existing;
            }

            return $context->ensureSuperglobal($name);
        }

        $var = new Variable(Variable::TYPE_NULL);
        if (isset($block->paramTypeConstraints[$slot])) {
            $var->typeConstraint = $block->paramTypeConstraints[$slot];
        }

        return $var;
    }

    private static function resolveVariableName(Operand $op): ?string
    {
        while ($op instanceof Temporary) {
            if (null === $op->original) {
                return null;
            }
            $op = $op->original;
        }
        if (!$op instanceof VarOperand) {
            return null;
        }
        $nameOp = $op->name;
        if (!$nameOp instanceof Literal) {
            return null;
        }
        if (null !== $nameOp->type && Variable::mapFromType($nameOp->type) !== Variable::TYPE_STRING) {
            return null;
        }
        if (!is_string($nameOp->value)) {
            return null;
        }

        return $nameOp->value;
    }
}
