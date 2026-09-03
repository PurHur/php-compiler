<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\JIT;

use PHPCfg\Op;
use PHPCfg\Op\Expr\FirstClassCallable;
use PHPCfg\Operand;
use PHPTypes\Type;
use SplObjectStorage;

class Analyzer
{
    public function needsBoundsCheck(Variable $var, Operand $dimOp): bool
    {
        if ($dimOp instanceof Operand\Literal) {
            return false;
        }
        if (count($dimOp->ops) !== 1) {
            return true;
        }
        if ($dimOp->ops[0] instanceof Op\Expr\BinaryOp\Mod) {
            // validate that the right side is <= var->nextFreeElement
            if ($dimOp->ops[0]->right instanceof Operand\Literal && $dimOp->ops[0]->right->type->type === Type::TYPE_LONG) {
                return $dimOp->ops[0]->right->value > $var->nextFreeElement;
            }
        }

        return true;
    }

    public function canEscape(Operand $operand, ?SplObjectStorage $seen = null): bool
    {
        if (null === $seen) {
            $seen = new SplObjectStorage();
        } elseif ($seen->contains($operand)) {
            return false;
        }
        $seen->attach($operand);
        foreach ($operand->usages as $usage) {
            if ($usage instanceof Op\Expr\Assign) {
                if ($this->canEscape($usage->var, $seen) || $this->canEscape($usage->result, $seen)) {
                    return true;
                }
            } elseif ($usage instanceof Op\Expr\FuncCall
                || $usage instanceof Op\Expr\NsFuncCall
                || $usage instanceof Op\Expr\StaticCall
                || $usage instanceof Op\Expr\MethodCall) {
                // Call operands must be refcounted hashtables / value boxes — native packed
                // literals cannot survive ARG_SEND (string keys lost, #26367 AOT).
                return true;
            } elseif ($usage instanceof Op\Expr\BinaryOp
                || $usage instanceof Op\Expr\ArrayDimFetch
                || $usage instanceof Op\Phi
                || $usage instanceof Op\Expr\ConcatList
                || $usage instanceof Op\Expr\Assertion
                || $usage instanceof Op\Expr\New_
                || $usage instanceof Op\Expr\PropertyFetch
                || $usage instanceof Op\Expr\Empty_
                || $usage instanceof Op\Expr\Isset_ // leftover of #32475 / #32556
                || $usage instanceof Op\Expr\BitwiseNot
                // Unary +/- on packed arrays must not abort Analyzer (#32553 leftover of #32475).
                || $usage instanceof Op\Expr\UnaryPlus
                || $usage instanceof Op\Expr\UnaryMinus
                || $usage instanceof Op\Expr\PreInc
                || $usage instanceof Op\Expr\PostInc
                || $usage instanceof Op\Expr\PreDec
                || $usage instanceof Op\Expr\PostDec
                || $usage instanceof Op\Expr\In_
                || $usage instanceof Op\Expr\Include_
                || $usage instanceof Op\Terminal\Return_
                || $usage instanceof Op\Iterator\Reset
                || $usage instanceof Op\Iterator\Valid
                || $usage instanceof Op\Iterator\Key
                || $usage instanceof Op\Iterator\Value
                || $usage instanceof Op\Iterator\Next
                || $usage instanceof Op\Terminal\Echo_
                || $usage instanceof Op\Expr\Print_
                || $usage instanceof Op\Expr\Array_
                || $usage instanceof Op\Expr\Cast\Array_
                || $usage instanceof Op\Expr\Cast\Object_
                || $usage instanceof Op\Expr\Cast\Unset_
                || $usage instanceof Op\Expr\Cast\Bool_
                || $usage instanceof Op\Expr\Cast\Int_
                || $usage instanceof Op\Expr\Cast\Double
                || $usage instanceof Op\Expr\Cast\String_
                || $usage instanceof Op\Expr\Cast\Void_
                || $usage instanceof Op\Expr\Yield_
                || $usage instanceof Op\Expr\YieldFrom
                || $usage instanceof FirstClassCallable
                || $usage instanceof Op\Terminal\StaticVar) {
                // isset() / print share Empty_ / Echo_ storage (#32556 leftover of #32475).
                continue;
            } elseif ($usage instanceof Op\Stmt\JumpIf || $usage instanceof Op\Expr\BooleanNot) {
                // if ($a) / !$a need zend_is_true; keep storage as hashtable (#32475 leftover of #32455).
                return true;
            } elseif ($usage instanceof Op\Terminal\Const_) {
                // Immutable global const arrays use module globals in __init__ (#4904, #4941).
                continue;
            } else {
                throw new \LogicException('Not implemented escape operand '.get_class($usage));
            }
        }

        return false;
    }

    public function hasDynamicArrayAppend(Operand $operand, int $size, ?SplObjectStorage $seen = null): bool
    {
        if (null === $seen) {
            $seen = new SplObjectStorage();
        } elseif ($seen->contains($operand)) {
            return false;
        }
        $seen->attach($operand);
        foreach ($operand->usages as $usage) {
            if ($usage instanceof Op\Expr\Assign) {
                if ($this->hasDynamicArrayAppend($usage->var, $size, $seen) || $this->hasDynamicArrayAppend($usage->result, $size, $seen)) {
                    return true;
                }
            } elseif ($usage instanceof Op\Expr\ArrayDimFetch) {
                if (null !== $usage->dim) {
                    if (! $usage->dim instanceof Operand\Literal) {
                        if (count($usage->result->ops) > 1) {
                            // this means that it's a write, disallow it
                            return true;
                        }
                    } elseif ($usage->dim->type->type !== Type::TYPE_LONG) {
                        return true;
                    } elseif ($usage->dim->value >= $size) {
                        return true;
                    }
                } else {
                    return true;
                }
            } elseif ($usage instanceof Op\Expr\FuncCall || $usage instanceof Op\Expr\NsFuncCall) {
                $fnOperand = $usage instanceof Op\Expr\NsFuncCall ? $usage->nsName : $usage->name;
                if ($fnOperand instanceof Operand\Literal) {
                    $fn = strtolower($fnOperand->value);
                    if (in_array($fn, ['array_push', 'array_pop', 'array_shift', 'array_unshift', 'array_splice'], true)) {
                        return true;
                    }
                }
            } elseif ($usage instanceof Op\Expr\BinaryOp
                || $usage instanceof Op\Phi
                || $usage instanceof Op\Expr\ConcatList
                || $usage instanceof Op\Expr\Assertion
                || $usage instanceof Op\Expr\Empty_
                || $usage instanceof Op\Expr\Isset_
                || $usage instanceof Op\Expr\BooleanNot
                || $usage instanceof Op\Expr\BitwiseNot
                || $usage instanceof Op\Expr\UnaryPlus
                || $usage instanceof Op\Expr\UnaryMinus
                || $usage instanceof Op\Expr\PreInc
                || $usage instanceof Op\Expr\PostInc
                || $usage instanceof Op\Expr\PreDec
                || $usage instanceof Op\Expr\PostDec
                || $usage instanceof Op\Stmt\JumpIf
                || $usage instanceof Op\Expr\In_
                || $usage instanceof Op\Expr\New_
                || $usage instanceof Op\Expr\MethodCall
                || $usage instanceof Op\Expr\StaticCall
                || $usage instanceof Op\Expr\PropertyFetch
                || $usage instanceof Op\Expr\Param
                || $usage instanceof Op\Iterator\Reset
                || $usage instanceof Op\Iterator\Valid
                || $usage instanceof Op\Iterator\Key
                || $usage instanceof Op\Iterator\Value
                || $usage instanceof Op\Iterator\Next
                || $usage instanceof Op\Terminal\Return_
                || $usage instanceof Op\Terminal\Echo_
                || $usage instanceof Op\Expr\Print_
                || $usage instanceof Op\Expr\Array_
                || $usage instanceof Op\Expr\Cast\Array_
                || $usage instanceof Op\Expr\Cast\Object_
                || $usage instanceof Op\Expr\Cast\Unset_
                || $usage instanceof Op\Expr\Cast\Bool_
                || $usage instanceof Op\Expr\Cast\Int_
                || $usage instanceof Op\Expr\Cast\Double
                || $usage instanceof Op\Expr\Cast\String_
                || $usage instanceof Op\Expr\Cast\Void_
                || $usage instanceof Op\Expr\Yield_
                || $usage instanceof Op\Expr\YieldFrom
                || $usage instanceof Op\Terminal\StaticVar
                || $usage instanceof FirstClassCallable
                || $usage instanceof Op\Terminal\Const_) {
                // isset() / print are not packed-array appends (#32556 leftover of #32475).
            } else {
                throw new \LogicException('Not implemented dynamic append operand '.get_class($usage));
            }
        }

        return false;
    }

    public function computeStaticArraySize(Operand $operand, ?SplObjectStorage $seen = null): ?int
    {
        if (null === $seen) {
            $seen = new SplObjectStorage();
        } elseif ($seen->contains($operand)) {
            return null;
        }
        $seen->attach($operand);
        $size = 0;
        foreach ($operand->ops as $op) {
            if ($op instanceof Op\Expr\Array_) {
                $newSize = 0;
                $nextListIndex = 0;
                $unpackFlags = property_exists($op, 'unpack') && \is_array($op->unpack)
                    ? $op->unpack
                    : [];
                foreach ($op->keys as $i => $key) {
                    // `[...$a]` uses NullOperand keys with unpack=true — runtime length / string
                    // keys; never a fixed native packed list (#28673).
                    if (!empty($unpackFlags[$i])) {
                        return null;
                    }
                    if ($key instanceof Operand\NullOperand) {
                        ++$newSize;
                        ++$nextListIndex;
                    } elseif (! $key instanceof Operand\Literal || $key->type->type !== Type::TYPE_LONG) {
                        return null;
                    } elseif ($key->value !== $nextListIndex) {
                        // Sparse or non-zero-based int keys (e.g. 10 => 'x') need __hashtable__.
                        return null;
                    } else {
                        ++$nextListIndex;
                        if ($key->value >= $newSize) {
                            $newSize = $key->value + 1;
                        }
                    }
                }
                $size = max($size, $newSize);
            } elseif ($op instanceof Op\Expr\Assign) {
                $newSize = $this->computeStaticArraySize($op->expr, $seen);
                if (null === $newSize) {
                    return null;
                }
                $size = max($size, $newSize);
            } elseif ($op instanceof Op\Terminal\StaticVar) {
                // Function-static CVs are now typed as their default (string[] stays array;
                // #32806). Follow the default like Assign — do not abort Analyzer.
                if (null === $op->defaultVar) {
                    return null;
                }
                $newSize = $this->computeStaticArraySize($op->defaultVar, $seen);
                if (null === $newSize) {
                    return null;
                }
                $size = max($size, $newSize);
            } elseif ($op instanceof Op\Phi) {
                $phiSize = null;
                foreach ($op->vars as $var) {
                    $branchSize = $this->computeStaticArraySize($var, $seen);
                    if (null === $branchSize) {
                        return null;
                    }
                    if (null === $phiSize) {
                        $phiSize = $branchSize;
                    } elseif ($phiSize !== $branchSize) {
                        return null;
                    }
                }
                if (null !== $phiSize) {
                    $size = max($size, $phiSize);
                }
            } elseif ($op instanceof Op\Expr\Cast\Array_
                || $op instanceof Op\Expr\Cast\Object_
                || $op instanceof Op\Expr\Cast\Unset_
                || $op instanceof Op\Expr\Cast\Bool_
                || $op instanceof Op\Expr\Cast\Int_
                || $op instanceof Op\Expr\Cast\Double
                || $op instanceof Op\Expr\Cast\String_
                || $op instanceof Op\Expr\Cast\Void_) {
                return null;
            } elseif ($op instanceof Op\Expr\BinaryOp
                || $op instanceof Op\Expr\ArrayDimFetch
                || $op instanceof Op\Expr\Assertion
                || $op instanceof Op\Expr\New_
                || $op instanceof Op\Expr\FuncCall
                || $op instanceof Op\Expr\NsFuncCall
                || $op instanceof Op\Expr\StaticCall
                || $op instanceof Op\Expr\MethodCall
                || $op instanceof Op\Expr\PropertyFetch
                || $op instanceof Op\Expr\StaticPropertyFetch
                || $op instanceof Op\Expr\Param
                || $op instanceof Op\Expr\ConstFetch
                || $op instanceof Op\Expr\AssignRef
                || $op instanceof Op\Expr\PreInc
                || $op instanceof Op\Expr\PostInc
                || $op instanceof Op\Expr\PreDec
                || $op instanceof Op\Expr\PostDec
                || $op instanceof FirstClassCallable
                || $op instanceof Op\Terminal\Const_
                || $op instanceof Op\Terminal\StaticVar
                || $op instanceof Op\Iterator\Reset
                || $op instanceof Op\Iterator\Valid
                || $op instanceof Op\Iterator\Key
                || $op instanceof Op\Iterator\Value
                || $op instanceof Op\Iterator\Next) {
                // Dynamic / non-literal writes (foreach Iterator\\Value, AssignRef, calls) —
                // cannot prove a fixed packed size (#24010 nested foreach).
                // StaticVar is the declaration binding, not a size-setting write (#32806).
                return null;
            } else {
                throw new \LogicException('Unknown array write op: '.get_class($op));
            }
        }

        return $size > 0 ? $size : null;
    }

    /**
     * Named CV used only as an int counter/accumulator may stay {@see Variable::TYPE_NATIVE_LONG}
     * even when php-types leaves it {@code inferred:unknown} (#36386 / #36217).
     *
     * Shape: `$i = 0;` / `++$i` / `$i < N` (and the dual for `$a` with only assign+inc).
     * Loop CVs are SSA-split across {@see Op\Phi} edges — walk the whole named web.
     * Rejects call args, dims, properties, by-ref, and non-int assigns so the generic
     * {@see Variable::TYPE_VALUE} path remains the bail-out.
     */
    public function canStayNativeLong(Operand $operand): bool
    {
        $name = OperandName::resolve($operand);
        if (null === $name || '' === $name) {
            return false;
        }

        $sawIntLiteralAssign = false;
        foreach ($this->collectNamedNativeLongWeb($operand, $name) as $node) {
            foreach ($node->ops as $def) {
                if ($def instanceof Op\Expr\Param || $def instanceof Op\Expr\AssignRef) {
                    return false;
                }
                if ($def instanceof Op\Phi) {
                    continue;
                }
                if ($def instanceof Op\Expr\Assign) {
                    if (!$this->isNativeLongAssignExpr($def->expr)) {
                        return false;
                    }
                    $sawIntLiteralAssign = $sawIntLiteralAssign
                        || ($def->expr instanceof Operand\Literal && $this->isIntLiteral($def->expr));
                    continue;
                }
                if (
                    $def instanceof Op\Expr\PreInc
                    || $def instanceof Op\Expr\PostInc
                    || $def instanceof Op\Expr\PreDec
                    || $def instanceof Op\Expr\PostDec
                ) {
                    continue;
                }

                return false;
            }
            foreach ($node->usages as $usage) {
                if ($usage instanceof Op\Phi) {
                    continue;
                }
                if (!$this->isNativeLongUsage($usage)) {
                    return false;
                }
            }
        }

        return $sawIntLiteralAssign;
    }

    /**
     * @return list<Operand>
     */
    private function collectNamedNativeLongWeb(Operand $start, string $name): array
    {
        $seen = new SplObjectStorage();
        $out = [];
        $queue = [$start];
        while ([] !== $queue) {
            $cur = array_pop($queue);
            if (!$cur instanceof Operand || $seen->contains($cur)) {
                continue;
            }
            if (OperandName::resolve($cur) !== $name) {
                continue;
            }
            $seen->attach($cur);
            $out[] = $cur;
            if ($cur instanceof Operand\Temporary && $cur->original instanceof Operand) {
                $queue[] = $cur->original;
            }
            foreach ($cur->ops as $def) {
                $this->enqueueNativeLongRelatedOperands($def, $queue);
            }
            foreach ($cur->usages as $use) {
                $this->enqueueNativeLongRelatedOperands($use, $queue);
            }
        }

        return $out;
    }

    /**
     * @param list<Operand> $queue
     */
    private function enqueueNativeLongRelatedOperands(object $op, array &$queue): void
    {
        if ($op instanceof Op\Phi) {
            if ($op->result instanceof Operand) {
                $queue[] = $op->result;
            }
            foreach ($op->vars as $v) {
                if ($v instanceof Operand) {
                    $queue[] = $v;
                }
            }

            return;
        }
        foreach (['var', 'read', 'write', 'result', 'expr', 'left', 'right'] as $prop) {
            if (isset($op->{$prop}) && $op->{$prop} instanceof Operand) {
                $queue[] = $op->{$prop};
            }
        }
    }

    private function isNativeLongAssignExpr(Operand $expr): bool
    {
        if ($this->isIntLiteral($expr)) {
            return true;
        }
        // `$i = $i + 1` / `$i = 1 + $i` lowers with a BinaryOp result Temporary.
        if ($expr instanceof Operand\Temporary && 1 === \count($expr->ops)) {
            $op = $expr->ops[0];
            if ($op instanceof Op\Expr\BinaryOp) {
                return $this->isNativeLongBinaryOperands($op->left, $op->right);
            }
            if (
                $op instanceof Op\Expr\PreInc
                || $op instanceof Op\Expr\PostInc
                || $op instanceof Op\Expr\PreDec
                || $op instanceof Op\Expr\PostDec
                || $op instanceof Op\Expr\UnaryMinus
                || $op instanceof Op\Expr\UnaryPlus
                || $op instanceof Op\Expr\Cast\Int_
            ) {
                return true;
            }
        }

        return false;
    }

    private function isNativeLongUsage(object $usage): bool
    {
        if ($usage instanceof Op\Expr\Assign) {
            return $this->isNativeLongAssignExpr($usage->expr);
        }
        if (
            $usage instanceof Op\Expr\PreInc
            || $usage instanceof Op\Expr\PostInc
            || $usage instanceof Op\Expr\PreDec
            || $usage instanceof Op\Expr\PostDec
            || $usage instanceof Op\Expr\UnaryMinus
            || $usage instanceof Op\Expr\UnaryPlus
            || $usage instanceof Op\Expr\Cast\Int_
            || $usage instanceof Op\Expr\Cast\Double
            || $usage instanceof Op\Expr\Cast\Bool_
            || $usage instanceof Op\Expr\Cast\String_
            || $usage instanceof Op\Terminal\Echo_
            || $usage instanceof Op\Expr\Print_
            || $usage instanceof Op\Terminal\Return_
        ) {
            return true;
        }
        if ($usage instanceof Op\Expr\BinaryOp) {
            return $this->isNativeLongBinaryOperands($usage->left, $usage->right);
        }

        return false;
    }

    private function isNativeLongBinaryOperands(Operand $left, Operand $right): bool
    {
        return $this->isNativeLongBinaryOperand($left) && $this->isNativeLongBinaryOperand($right);
    }

    private function isNativeLongBinaryOperand(Operand $op): bool
    {
        if ($this->isIntLiteral($op)) {
            return true;
        }
        // Peer of `$i < $n` / `$i + $j`: named or temporary operands coerce on the
        // compare/arith path; reject only obvious non-int literals.
        if (null !== OperandName::resolve($op)) {
            return true;
        }
        if ($op instanceof Operand\Temporary) {
            return true;
        }

        return false;
    }

    private function isIntLiteral(Operand $op): bool
    {
        if (!$op instanceof Operand\Literal) {
            return false;
        }
        if (null === $op->type) {
            return \is_int($op->value);
        }
        if (Type::TYPE_LONG === $op->type->type) {
            return true;
        }

        return \is_int($op->value);
    }
}
