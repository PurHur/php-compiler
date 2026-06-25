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
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * array_key_exists() / key_exists() for arrays with int, float, or string keys (php-src subset).
 */
final class array_key_exists extends Internal
{
    public function __construct(string $name = 'array_key_exists')
    {
        parent::__construct($name);
    }

    public function execute(Frame $frame): void
    {
        $fn = $this->getName();
        $this->requireExactArgCount($frame, $fn, 2);
        $key = $frame->calledArgs[0]->resolveIndirect();
        EnumCaseSupport::rejectIllegalArrayOffset($key);
        $array = VmArray::requireArrayParam(
            $frame->calledArgs[1],
            $fn,
            2,
            'array'
        );
        if (null === $frame->returnVar) {
            return;
        }
        if (Variable::TYPE_NULL === $key->type) {
            $emptyKey = new Variable();
            $emptyKey->string('');
            $key = $emptyKey;
        } elseif (Variable::TYPE_INTEGER !== $key->type
            && Variable::TYPE_STRING !== $key->type
            && Variable::TYPE_FLOAT !== $key->type) {
            throw new \TypeError('Illegal offset type');
        }
        $frame->returnVar->bool($array->hasKey($key));
    }

    public Context $context;

    public function call(Context $context, JITVariable ...$args): Value
    {
        $fn = $this->getName();
        if (!$this->requireExactJitArgCount($context, $args, $fn, 2)) {
            return $context->getTypeFromString('int1')->constInt(0, false);
        }
        $key = $args[0];
        $array = $args[1];
        if (JITVariable::TYPE_HASHTABLE !== $array->type
            && !($array->type & JITVariable::IS_NATIVE_ARRAY)
        ) {
            if (JITVariable::TYPE_VALUE === $array->type) {
                JitArrayElem::requireArrayParam($context, $array, $fn, 2, 'array');

                return self::jitKeyExistsOnHashTable(
                    $context,
                    ArrayBuiltinHelper::loadHashTable($context, $array),
                    $key,
                    $fn
                );
            }
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::registerDeclarations($context);
            TypeErrorRaise::emitRaise(
                $context,
                \sprintf(
                    '%s(): Argument #2 ($array) must be of type array, %s given',
                    $fn,
                    self::jitArgTypeLabel($array)
                )
            );
            $context->builder->call($context->lookupFunction('abort'));

            return $context->constantFromInteger(0, 'int1');
        }
        if (JITVariable::TYPE_HASHTABLE === $array->type) {
            $ht = $context->helper->loadValue($array);

            return self::jitKeyExistsOnHashTable($context, $ht, $key, $fn);
        }
        if ($array->type & JITVariable::IS_NATIVE_ARRAY) {
            if (JITVariable::TYPE_NULL === $key->type
                || JITVariable::TYPE_STRING === $key->type
                || JITVariable::TYPE_VALUE === $key->type) {
                return $context->constantFromInteger(0, 'int1');
            }
            if (JITVariable::TYPE_NATIVE_LONG !== $key->type) {
                throw new \LogicException(
                    $fn.'() on native arrays only supports integer keys in this compiler build'
                );
            }
            $index = JitLongArg::lower($context, $key, $fn.'() key');
            $size = $context->constantFromInteger($array->nextFreeElement, 'int32');
            $i32 = $context->getTypeFromString('int32');
            $inRange = $context->builder->icmp(Builder::INT_SLT, $index, $size);
            $nonNeg = $context->builder->icmp(Builder::INT_SGE, $index, $i32->constInt(0, false));

            return $context->builder->and($inRange, $nonNeg);
        }
    }

    private static function jitArgTypeLabel(JITVariable $arg): string
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

    /**
     * php-src: null lookup key coerces to empty string (ext/standard/array.c).
     */
    private static function jitKeyExistsOnHashTable(
        Context $context,
        Value $ht,
        JITVariable $key,
        string $function
    ): Value {
        if (JITVariable::TYPE_NULL === $key->type) {
            return self::jitEmptyStringKeyExists($context, $ht);
        }
        if (JITVariable::TYPE_STRING === $key->type) {
            return $context->builder->call(
                $context->lookupFunction('__hashtable__offsetIsSetStringKey'),
                $ht,
                (new self($function))->jitString($context, $key, $function.'() key')
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
        if (JITVariable::TYPE_NATIVE_DOUBLE === $key->type) {
            $index = $context->builder->fptosi(
                $context->helper->loadValue($key),
                $context->getTypeFromString('size_t')
            );

            return $context->builder->call(
                $context->lookupFunction('__hashtable__offsetIsSet'),
                $ht,
                $index
            );
        }
        if (JITVariable::TYPE_OBJECT === $key->type) {
            HashTableHelper::emitIllegalOffsetType($context, 'Illegal offset type');

            return $context->constantFromInteger(0, 'int1');
        }
        if (JITVariable::TYPE_VALUE === $key->type) {
            return self::jitKeyExistsValueBoxKey($context, $ht, $key);
        }

        throw new \LogicException(
            $function.'() key must be an integer or string in this compiler build'
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
        $doubleBlock = $fn->appendBasicBlock('ake_vk_double');
        $afterDouble = $fn->appendBasicBlock('ake_vk_after_double');
        $context->builder->branchIf(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeByte,
                $i8->constInt(JITVariable::TYPE_NATIVE_DOUBLE, false)
            ),
            $doubleBlock,
            $afterDouble
        );
        $context->builder->positionAtEnd($doubleBlock);
        $indexFromDouble = $context->builder->fptosi(
            $context->builder->call($context->lookupFunction('__value__readDouble'), $valPtr),
            $sizeT
        );
        $doubleResult = $context->builder->call(
            $context->lookupFunction('__hashtable__offsetIsSet'),
            $ht,
            $indexFromDouble
        );
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($afterDouble);
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
        $phi->addIncoming($doubleResult, $doubleBlock);
        $phi->addIncoming($nullResult, $nullBlock);
        $phi->addIncoming($i1->constInt(0, false), $falseBlock);

        return $phi;
    }
}
