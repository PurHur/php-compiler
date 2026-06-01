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
 * array_product() for arrays of integers and floats (subset of PHP; native LLVM in JIT).
 */
final class array_product extends Internal
{
    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \LogicException('array_product() requires exactly one argument');
        }
        $array = $frame->calledArgs[0]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_product() argument must be an array in this compiler build');
        }
        $prodInt = 1;
        $prodFloat = 1.0;
        $useFloat = false;
        foreach ($array->toArray()->iterate(true) as $value) {
            $v = $value->resolveIndirect();
            if (Variable::TYPE_INTEGER === $v->type) {
                if ($useFloat) {
                    $prodFloat *= (float) $v->toInt();
                } else {
                    $prodInt *= $v->toInt();
                }
                continue;
            }
            if (Variable::TYPE_FLOAT === $v->type) {
                if (!$useFloat) {
                    $useFloat = true;
                    $prodFloat = (float) $prodInt * $v->toFloat();
                } else {
                    $prodFloat *= $v->toFloat();
                }
                continue;
            }
            if (Variable::TYPE_STRING === $v->type) {
                $s = $v->toString();
                if (!\is_numeric($s)) {
                    throw new \TypeError('Unsupported operand types: string');
                }
                $num = $v->toNumeric();
                if (\is_int($num)) {
                    if ($useFloat) {
                        $prodFloat *= (float) $num;
                    } else {
                        $prodInt *= $num;
                    }
                } else {
                    if (!$useFloat) {
                        $useFloat = true;
                        $prodFloat = (float) $prodInt * (float) $num;
                    } else {
                        $prodFloat *= (float) $num;
                    }
                }
                continue;
            }
            throw new \LogicException('array_product() only supports integer, float, and numeric string elements in this compiler build');
        }
        if ($useFloat) {
            $frame->returnVar->float($prodFloat);
        } else {
            $frame->returnVar->int($prodInt);
        }
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (1 !== \count($args)) {
            throw new \LogicException('array_product() requires exactly one argument');
        }
        foreach ($args as $i => $arg) {
            if (JITVariable::TYPE_STRING === $arg->type || JITVariable::TYPE_VALUE === $arg->type) {
                $this->jitString($context, $arg, 'array_product() argument #'.((int) $i + 1));
            }
        }
        if ($args[0]->type & JITVariable::IS_NATIVE_ARRAY) {
            return ArrayBuiltinHelper::arrayProduct($context, $args[0]);
        }
        if (JITVariable::TYPE_HASHTABLE === $args[0]->type) {
            return ArrayBuiltinHelper::arrayProduct($context, $args[0]);
        }

        throw new \LogicException('array_product() only supports arrays in this compiler build');
    }
}
