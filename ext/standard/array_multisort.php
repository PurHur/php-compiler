<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_multisort() — sort multiple packed arrays by the first (subset of PHP; issue #1212).
 *
 * VM: homogeneous string or integer arrays, same length, optional trailing SORT_ASC (4) or
 * SORT_DESC (3) for the primary array. JIT/AOT: coupled packed bubble sort (#1212).
 */
final class array_multisort extends Internal
{
    private const SORT_DESC = 3;
    private const SORT_ASC = 4;

    public function __construct()
    {
        parent::__construct('array_multisort');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \LogicException(
                'array_multisort() requires at least two arguments in this compiler build'
            );
        }
        $arrays = [];
        $descending = false;
        for ($i = 0; $i < $argc; ++$i) {
            $arg = $frame->calledArgs[$i]->resolveIndirect();
            if (Variable::TYPE_ARRAY === $arg->type) {
                $arrays[] = $arg;
                continue;
            }
            if (Variable::TYPE_INTEGER === $arg->type) {
                $order = $arg->toInt();
                if (self::SORT_DESC === $order) {
                    $descending = true;
                } elseif (self::SORT_ASC !== $order) {
                    throw new \LogicException(
                        'array_multisort() only supports SORT_ASC or SORT_DESC in this compiler build'
                    );
                }
                continue;
            }
            throw new \LogicException(
                'array_multisort() arguments must be arrays or SORT_* order flags in this compiler build'
            );
        }
        if (\count($arrays) < 2) {
            throw new \LogicException(
                'array_multisort() requires at least two array arguments in this compiler build'
            );
        }
        $length = null;
        $primaryValues = [];
        foreach ($arrays as $idx => $array) {
            $ht = $array->toArray();
            $count = $ht->getNumElements();
            if (null === $length) {
                $length = $count;
            } elseif ($count !== $length) {
                throw new \LogicException(
                    'array_multisort() array lengths must match in this compiler build'
                );
            }
            if (0 === $idx) {
                foreach ($ht->iterate(true) as $value) {
                    $copy = new Variable();
                    $copy->copyFrom($value);
                    $primaryValues[] = $copy;
                }
            }
        }
        if (null === $length || $length < 2) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(true);
            }

            return;
        }
        $indices = range(0, $length - 1);
        \usort($indices, function (int $a, int $b) use ($primaryValues, $descending): int {
            $left = $primaryValues[$a]->resolveIndirect();
            $right = $primaryValues[$b]->resolveIndirect();
            $cmp = self::compareValues($left, $right);
            if ($descending) {
                return -$cmp;
            }

            return $cmp;
        });
        foreach ($arrays as $array) {
            $ht = $array->toArray();
            $values = [];
            foreach ($ht->iterate(true) as $value) {
                $copy = new Variable();
                $copy->copyFrom($value);
                $values[] = $copy;
            }
            $reordered = [];
            foreach ($indices as $idx) {
                $reordered[] = $values[$idx];
            }
            $ht->replacePackedValues($reordered);
            $array->array($ht);
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2) {
            throw new \LogicException(
                'array_multisort() requires at least two arguments in this compiler build'
            );
        }
        $arrays = [];
        $descending = false;
        for ($i = 0; $i < $argc; ++$i) {
            $arg = $args[$i];
            if (JITVariable::TYPE_HASHTABLE === ($arg->type & ~JITVariable::IS_NATIVE_ARRAY)
                || ArrayBuiltinHelper::isNativeArray($arg->type)) {
                $arrays[] = $arg;
                continue;
            }
            if (JITVariable::TYPE_NATIVE_LONG === $arg->type
                && ($arg->isConstant ?? false)
                && JITVariable::KIND_VALUE === $arg->kind) {
                $order = (int) $context->llvm->lib->LLVMConstIntGetZExtValue($arg->value->value);
                if (self::SORT_DESC === $order) {
                    $descending = true;
                } elseif (self::SORT_ASC !== $order) {
                    throw new \LogicException(
                        'array_multisort() only supports SORT_ASC or SORT_DESC in this compiler build'
                    );
                }
                continue;
            }
            throw new \LogicException(
                'array_multisort() arguments must be arrays or SORT_* order flags in this compiler build'
            );
        }
        if (\count($arrays) < 2) {
            throw new \LogicException(
                'array_multisort() requires at least two array arguments in this compiler build'
            );
        }
        ArrayBuiltinHelper::multisortPacked($context, $arrays, $descending);

        return $context->getTypeFromString('int1')->constInt(1, false);
    }

    private static function compareValues(Variable $a, Variable $b): int
    {
        $a = $a->resolveIndirect();
        $b = $b->resolveIndirect();
        if (Variable::TYPE_STRING === $a->type && Variable::TYPE_STRING === $b->type) {
            return VmString::strcmp($a->toString(), $b->toString());
        }
        if (Variable::TYPE_INTEGER === $a->type && Variable::TYPE_INTEGER === $b->type) {
            return $a->toInt() <=> $b->toInt();
        }

        throw new \LogicException(
            'array_multisort() only supports homogeneous string or integer arrays in this compiler build'
        );
    }
}
