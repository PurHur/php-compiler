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

    private \SplObjectStorage $scope;

    /** 
     * @var Variable[] $constants
     */
    public array $constants = [];

    public \SplObjectStorage $args;

    public ?Handler $handler = null;

    /** When true, unresolved local reads in child frames become undefined (isset chains). */
    public bool $inheritUndefinedLocals = false;

    /** File-level declare(strict_types=1) for this function body (issue #156). */
    public bool $strictTypes = false;

    /** @var array<int, int> scope slot index => Variable::TYPE_* for typed parameters */
    public array $paramTypeConstraints = [];

    public function __construct(?CfgBlock $block) {
        $this->orig = $block;
        $this->scope = new \SplObjectStorage;
        $this->args = new \SplObjectStorage;
    }

    public function getOperand(int $offset): Operand {
        foreach ($this->scope as $operand) {
            if ($this->scope[$operand] === $offset) {
                return $operand;
            }
        }
    }

    public function getVarSlot(Operand $operand, bool $isRead): int {
        if (!$this->scope->contains($operand)) {
            $this->scope[$operand] = $this->scope->count();
            if ($isRead) {
                $this->args[$operand] = $this->scope[$operand];
            }
        }
        return $this->scope[$operand];
    }

    public function registerConstant(Operand $operand, Variable $const): int {
        $slot = $this->getVarSlot($operand, false);
        $this->constants[$slot] = $const;
        return $slot;
    }

    /**
     * Copy variable slot mappings from a parent block (for synthetic CFG branches).
     */
    public function inheritScopeFrom(Block $parent): void
    {
        foreach ($parent->scope as $operand) {
            if ($this->scope->contains($operand)) {
                continue;
            }
            $slot = $parent->scope[$operand];
            $this->scope[$operand] = $slot;
            if ($parent->args->contains($operand)) {
                $this->args[$operand] = $slot;
            }
            if (isset($parent->constants[$slot])) {
                $this->constants[$slot] = $parent->constants[$slot];
            }
        }
    }

    public function addOpCode(OpCode ...$ops): void {
        foreach ($ops as $op) {
            $this->nOpCodes++;
            $this->opCodes[] = $op;
        }
    }

    public function findSlot(Operand $op, Frame $frame): ?Variable {
        if (!$this->scope->contains($op)) {
            // check PHI vars
            if (!is_null($frame->parent)) {
                return $frame->parent->block->findSlot($op, $frame->parent);
            }
            return null;
        }
        $idx = $this->scope[$op];
        return $frame->scope[$idx];
    }

    public function slotIndexForVariableName(string $name): ?int
    {
        foreach ($this->scope as $operand) {
            if (self::resolveVariableName($operand) === $name) {
                return $this->scope[$operand];
            }
        }

        return null;
    }

    /**
     * Zend include/require: included file shares caller locals by name (issue #471).
     */
    private static function findVariableInParentFrames(Operand $op, Frame $frame): ?Variable
    {
        $name = self::resolveVariableName($op);
        if (null === $name) {
            return null;
        }
        for ($f = $frame; null !== $f; $f = $f->parent) {
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
        // Todo: build scope
        $scope = [];
        $scopeSize = $this->scope->count();
        foreach ($this->scope as $op) {
            $pos = $this->scope[$op];
            if (null !== $frame && 'this' === self::resolveVariableName($op) && !empty($frame->callArgs)) {
                $scope[$pos] = $frame->callArgs[0];
                continue;
            }

            if (isset($this->constants[$pos])) {
                $scope[$pos] = $this->constants[$pos];
            } elseif ($this->args->contains($op)) {
                if (is_null($frame)) {
                    $scope[$pos] = self::initialVariableForOperand($op, $context, $pos, $this);
                    continue;
                }
                $found = false;
                $parent = $frame->block->findSlot($op, $frame);
                if (!is_null($parent)) {
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
        if (!is_null($frame) && !is_null($frame->returnVar)) {
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
