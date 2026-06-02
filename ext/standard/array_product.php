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
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * array_product() for arrays of integers and floats (subset of PHP; native LLVM in JIT).
 */
final class array_product extends Internal
{
    private const ELEMENT_TYPE_ERROR =
        'array_product(): Argument #1 ($array) must contain only int and float values';

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(
                'array_product() expects exactly 1 argument, '.$argc.' given'
            );
        }
        $ht = VmArray::requireArray($frame->calledArgs[0]->resolveIndirect(), 'array_product');
        $prodInt = 1;
        $prodFloat = 1.0;
        $useFloat = false;
        foreach ($ht->iterate(true) as $value) {
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
                    throw new \TypeError(self::ELEMENT_TYPE_ERROR);
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
            throw new \TypeError(self::ELEMENT_TYPE_ERROR);
        }
        if (null === $frame->returnVar) {
            return;
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
        $argc = \count($args);
        TypeErrorRaise::ensureLinked($context);
        if (1 !== $argc) {
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'array_product() expects exactly 1 argument, '.$argc.' given'
            );

            return $context->getTypeFromString('int64')->constInt(1, false);
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
        if (JITVariable::TYPE_VALUE === $args[0]->type) {
            JitArrayElem::requireArrayArg($context, $args[0], 'array_product');

            return ArrayBuiltinHelper::arrayProduct($context, $args[0]);
        }
        TypeErrorRaise::emitRaise(
            $context,
            'array_product(): Argument #1 ($array) must be of type array, '
            .$this->jitArgTypeLabel($args[0]).' given'
        );

        return $context->getTypeFromString('int64')->constInt(1, false);
    }

    private function jitArgTypeLabel(JITVariable $arg): string
    {
        switch ($arg->type) {
            case JITVariable::TYPE_NATIVE_LONG:
                return 'int';
            case JITVariable::TYPE_NATIVE_DOUBLE:
                return 'float';
            case JITVariable::TYPE_NATIVE_BOOL:
                return 'bool';
            case JITVariable::TYPE_STRING:
                return 'string';
            case JITVariable::TYPE_OBJECT:
                return 'object';
            default:
                return 'mixed';
        }
    }
}
