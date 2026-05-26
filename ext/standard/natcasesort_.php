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
 * natcasesort() — sort by value using natural case-insensitive order, preserve keys (#2372).
 *
 * VM: homogeneous string or integer values; packed lists sort values in place.
 * JIT/AOT: packed list via __hashtable__sortPackedNaturalCase; string-key via __hashtable__sortStringKeyValuesNaturalCase.
 */
final class natcasesort_ extends Internal
{
    public function __construct()
    {
        parent::__construct('natcasesort');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('natcasesort() requires exactly one argument');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('natcasesort() argument must be an array in this compiler build');
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
                VmInternalCompare::sortVariableValues(
                    $values,
                    VmInternalCompare::resolveStringCallback('strnatcasecmp')
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
                                'natcasesort() only supports homogeneous string or integer values in this compiler build'
                            );
                        }
                        if ($a->toInt() <= $b->toInt()) {
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
                    'natcasesort() only supports homogeneous string or integer values in this compiler build'
                );
            }
            $ht->replacePackedValues($values);
        } else {
            $array->array(VmArray::natcasesortCopy($ht));
        }
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('natcasesort() requires exactly one argument');
        }
        ArrayBuiltinHelper::natcasesortByValue($context, $args[0]);

        return $context->getTypeFromString('int1')->constInt(1, false);
    }
}
