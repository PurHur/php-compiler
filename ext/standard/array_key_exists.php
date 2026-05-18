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
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * array_key_exists() for arrays with int or string keys (subset of PHP).
 */
final class array_key_exists extends Internal
{
    public function execute(Frame $frame): void
    {
        if (2 !== \count($frame->calledArgs)) {
            throw new \LogicException('array_key_exists() requires exactly two arguments');
        }
        $key = $frame->calledArgs[0]->resolveIndirect();
        $array = $frame->calledArgs[1]->resolveIndirect();
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_ARRAY !== $array->type) {
            throw new \LogicException('array_key_exists() second argument must be an array in this compiler build');
        }
        if (Variable::TYPE_INTEGER !== $key->type && Variable::TYPE_STRING !== $key->type) {
            throw new \LogicException('array_key_exists() key must be an integer or string in this compiler build');
        }
        $frame->returnVar->bool($array->toArray()->hasKey($key));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (2 !== \count($args)) {
            throw new \LogicException('array_key_exists() requires exactly two arguments');
        }
        $key = $args[0];
        $array = $args[1];
        if (JITVariable::TYPE_HASHTABLE === $array->type) {
            $ht = $context->helper->loadValue($array);
            if (JITVariable::TYPE_STRING === $key->type) {
                return $context->builder->call(
                    $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
                    $ht,
                    $context->helper->loadValue($key)
                );
            }
            if (JITVariable::TYPE_NATIVE_LONG === $key->type) {
                $index = $context->builder->truncOrBitCast(
                    $context->helper->loadValue($key),
                    $context->getTypeFromString('size_t')
                );

                return $context->builder->call(
                    $context->lookupFunction('__hashtable__offsetIsSet'),
                    $ht,
                    $index
                );
            }
            throw new \LogicException(
                'array_key_exists() key must be an integer or string in this compiler build'
            );
        }
        if ($array->type & JITVariable::IS_NATIVE_ARRAY) {
            if (JITVariable::TYPE_NATIVE_LONG !== $key->type) {
                throw new \LogicException(
                    'array_key_exists() on native arrays only supports integer keys in this compiler build'
                );
            }
            $index = $context->helper->loadValue($key);
            $size = $context->constantFromInteger($array->nextFreeElement, 'int32');
            $i32 = $context->getTypeFromString('int32');
            $inRange = $context->builder->icmp(Builder::INT_SLT, $index, $size);
            $nonNeg = $context->builder->icmp(Builder::INT_SGE, $index, $i32->constInt(0, false));

            return $context->builder->and($inRange, $nonNeg);
        }
        throw new \LogicException(
            'array_key_exists() second argument must be an array in this compiler build'
        );
    }
}
