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
 * arsort() — sort by value descending, preserve keys (subset of PHP; issue #2296).
 *
 * VM: homogeneous string or integer values; packed lists sort values in place.
 * JIT/AOT: packed list via __hashtable__sortPackedReverse; string-key via __hashtable__sortStringKeyValuesReverse.
 */
final class arsort_ extends Internal
{
    public function __construct()
    {
        parent::__construct('arsort');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('arsort() requires exactly one argument');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('arsort() argument must be an array in this compiler build');
        }
        $ht = $array->toArray();
        if ($ht->getNumElements() < 2) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(true);
            }

            return;
        }
        if (VmArray::isList($ht)) {
            $values = [];
            foreach ($ht->iterate(true) as $value) {
                $copy = new Variable();
                $copy->copyFrom($value);
                $values[] = $copy;
            }
            $first = $values[0]->resolveIndirect();
            if (Variable::TYPE_STRING === $first->type) {
                VmInternalCompare::sortVariableValuesDesc(
                    $values,
                    VmInternalCompare::resolveStringCallback('strcmp')
                );
            } elseif (Variable::TYPE_INTEGER === $first->type) {
                $n = \count($values);
                for ($i = 1; $i < $n; ++$i) {
                    $j = $i;
                    while ($j > 0) {
                        $a = $values[$j - 1]->resolveIndirect();
                        $b = $values[$j]->resolveIndirect();
                        if (Variable::TYPE_INTEGER !== $a->type || Variable::TYPE_INTEGER !== $b->type) {
                            throw new \LogicException(
                                'arsort() only supports homogeneous string or integer values in this compiler build'
                            );
                        }
                        if ($a->toInt() >= $b->toInt()) {
                            break;
                        }
                        $tmp = $values[$j - 1];
                        $values[$j - 1] = $values[$j];
                        $values[$j] = $tmp;
                        --$j;
                    }
                }
            } else {
                throw new \LogicException(
                    'arsort() only supports homogeneous string or integer values in this compiler build'
                );
            }
            $ht->replacePackedValues($values);
        } else {
            $array->array(VmArray::arsortCopy($ht));
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('arsort() requires exactly one argument');
        }
        ArrayBuiltinHelper::arsortByValue($context, $args[0]);

        return $context->getTypeFromString('int1')->constInt(1, false);
    }
}
