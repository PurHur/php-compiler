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
            } elseif ($usage instanceof Op\Expr\BinaryOp
                || $usage instanceof Op\Expr\ArrayDimFetch
                || $usage instanceof Op\Phi
                || $usage instanceof Op\Expr\FuncCall
                || $usage instanceof Op\Expr\NsFuncCall
                || $usage instanceof Op\Expr\StaticCall
                || $usage instanceof Op\Expr\ConcatList
                || $usage instanceof Op\Expr\Assertion
                || $usage instanceof Op\Expr\New_
                || $usage instanceof Op\Expr\MethodCall
                || $usage instanceof Op\Expr\PropertyFetch
                || $usage instanceof Op\Expr\Empty_
                || $usage instanceof Op\Expr\In_
                || $usage instanceof Op\Expr\Include_
                || $usage instanceof Op\Terminal\Return_
                || $usage instanceof Op\Iterator\Reset
                || $usage instanceof Op\Iterator\Valid
                || $usage instanceof Op\Iterator\Key
                || $usage instanceof Op\Iterator\Value
                || $usage instanceof Op\Iterator\Next
                || $usage instanceof Op\Terminal\Echo_
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
                continue;
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
                // not a dynamic packed-array append
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
                || $op instanceof FirstClassCallable
                || $op instanceof Op\Terminal\Const_
                || $op instanceof Op\Iterator\Reset
                || $op instanceof Op\Iterator\Valid
                || $op instanceof Op\Iterator\Key
                || $op instanceof Op\Iterator\Value
                || $op instanceof Op\Iterator\Next) {
                // Dynamic / non-literal writes (foreach Iterator\\Value, AssignRef, calls) —
                // cannot prove a fixed packed size (#24010 nested foreach).
                return null;
            } else {
                throw new \LogicException('Unknown array write op: '.get_class($op));
            }
        }

        return $size > 0 ? $size : null;
    }
}
