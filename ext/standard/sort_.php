<?php

declare(strict_types=1);

/**
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * sort() for homogeneous packed string or integer arrays (subset of PHP).
 *
 * VM: full support. JIT/AOT: dynamic hashtable arrays only (not fixed native literals).
 */
final class sort_ extends Internal
{
    public function __construct()
    {
        parent::__construct('sort');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('sort() requires exactly one argument');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('sort() argument must be an array in this compiler build');
        }
        $ht = $array->toArray();
        if ($ht->getNumElements() < 2) {
            if (null !== $frame->returnVar) {
                $frame->returnVar->bool(true);
            }

            return;
        }
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
                            'sort() only supports homogeneous string or integer arrays in this compiler build'
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
                'sort() only supports homogeneous string or integer arrays in this compiler build'
            );
        }
        $ht->replacePackedValues($values);
        if (null !== $frame->returnVar) {
            $frame->returnVar->bool(true);
        }
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('sort() requires exactly one argument');
        }
        ArrayBuiltinHelper::sortPacked($context, $args[0]);

        return $context->getTypeFromString('int1')->constInt(1, false);
    }
}
