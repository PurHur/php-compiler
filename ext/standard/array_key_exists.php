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
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
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
        if (Variable::TYPE_NULL === $key->type) {
            $emptyKey = new Variable();
            $emptyKey->string('');
            $key = $emptyKey;
        } elseif (Variable::TYPE_INTEGER !== $key->type && Variable::TYPE_STRING !== $key->type) {
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

            return self::jitKeyExistsOnHashTable($context, $ht, $key);
        }
        if ($array->type & JITVariable::IS_NATIVE_ARRAY) {
            if (JITVariable::TYPE_NULL === $key->type
                || JITVariable::TYPE_STRING === $key->type
                || JITVariable::TYPE_VALUE === $key->type) {
                return $context->constantFromInteger(0, 'int1');
            }
            if (JITVariable::TYPE_NATIVE_LONG !== $key->type) {
                throw new \LogicException(
                    'array_key_exists() on native arrays only supports integer keys in this compiler build'
                );
            }
            $index = JitLongArg::lower($context, $key, 'array_key_exists() key');
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

    /**
     * php-src: null lookup key coerces to empty string (ext/standard/array.c).
     */
    private static function jitKeyExistsOnHashTable(Context $context, Value $ht, JITVariable $key): Value
    {
        if (JITVariable::TYPE_NULL === $key->type) {
            return self::jitEmptyStringKeyExists($context, $ht);
        }
        if (JITVariable::TYPE_STRING === $key->type) {
            return $context->builder->call(
                $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
                $ht,
                (new self())->jitString($context, $key, 'array_key_exists() key')
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
        if (JITVariable::TYPE_VALUE === $key->type) {
            return self::jitKeyExistsValueBoxKey($context, $ht, $key);
        }

        throw new \LogicException(
            'array_key_exists() key must be an integer or string in this compiler build'
        );
    }

    private static function jitEmptyStringKeyExists(Context $context, Value $ht): Value
    {
        $emptyKey = $context->builder->load($context->constantStringFromString(''));

        return $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $ht,
            $emptyKey
        );
    }

    private static function jitKeyExistsValueBoxKey(Context $context, Value $ht, JITVariable $key): Value
    {
        if (JITVariable::TYPE_VALUE !== $key->type) {
            throw new \LogicException('jitKeyExistsValueBoxKey requires TYPE_VALUE');
        }
        $valPtr = JITVariable::KIND_VARIABLE === $key->kind
            ? JitValueBox::pointer($context, $key->value)
            : $context->helper->loadValue($key);
        $valueMap = $context->structFieldMap['__value__'];
        $i8 = $context->getTypeFromString('int8');
        $i1 = $context->getTypeFromString('int1');
        $sizeT = $context->getTypeFromString('size_t');
        $typeByte = $context->builder->load(
            $context->builder->structGep($valPtr, $valueMap['type'])
        );
        $fn = $context->builder->getInsertBlock()->getParent();
        $stringBlock = $fn->appendBasicBlock('ake_vk_str');
        $longBlock = $fn->appendBasicBlock('ake_vk_long');
        $nullBlock = $fn->appendBasicBlock('ake_vk_null');
        $falseBlock = $fn->appendBasicBlock('ake_vk_false');
        $merge = $fn->appendBasicBlock('ake_vk_merge');
        $afterString = $fn->appendBasicBlock('ake_vk_after_str');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_STRING, false)
            ),
            $stringBlock,
            $afterString
        );
        $context->builder->positionAtEnd($stringBlock);
        $keyStr = $context->builder->call($context->lookupFunction('__value__readString'), $valPtr);
        $strResult = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
            $ht,
            $keyStr
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($afterString);
        $afterLong = $fn->appendBasicBlock('ake_vk_after_long');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)
            ),
            $longBlock,
            $afterLong
        );
        $context->builder->positionAtEnd($longBlock);
        $index = $context->builder->truncOrBitCast(
            $context->builder->call($context->lookupFunction('__value__readLong'), $valPtr),
            $sizeT
        );
        $longResult = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $index
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($afterLong);
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_NULL, false)
            ),
            $nullBlock,
            $falseBlock
        );
        $context->builder->positionAtEnd($nullBlock);
        $nullResult = self::jitEmptyStringKeyExists($context, $ht);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($falseBlock);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($strResult, $stringBlock);
        $phi->addIncoming($longResult, $longBlock);
        $phi->addIncoming($nullResult, $nullBlock);
        $phi->addIncoming($i1->constInt(0, false), $falseBlock);

        return $phi;
    }
}
