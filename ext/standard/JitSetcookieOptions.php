<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for setcookie()/setrawcookie() options array (#3507). */
final class JitSetcookieOptions
{
    public static function isOptionsArrayArg(JITVariable $arg): bool
    {
        if (JITVariable::TYPE_NATIVE_LONG === $arg->type) {
            return false;
        }
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return true;
        }
        if (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            return true;
        }

        return JITVariable::TYPE_VALUE === $arg->type;
    }

    public static function invoke(Context $context, string $function, JITVariable ...$args): Value
    {
        if (3 !== \count($args)) {
            throw new \LogicException($function.'() options form requires exactly three arguments');
        }

        $namePtr = JitStringBuiltinArg::lowerTrimFamilyString($context, $args[0], $function, 0, 'name');
        JitStringBuiltinArg::rejectEmpty(
            $context,
            $args[0],
            $namePtr,
            $function.'(): Argument #1 ($name) must not be empty'
        );
        $valuePtr = JitStringBuiltinArg::lower($context, $args[1], $function, 1, 'value');
        $optionsHt = self::loadOptionsArray($context, $args[2], $function);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');

        $expiresI64 = self::peekLongOption($context, $optionsHt, 'expires');
        $pathPtr = self::peekStringOption($context, $optionsHt, 'path');
        $domainPtr = self::peekStringOption($context, $optionsHt, 'domain');
        $secureI32 = self::peekBoolOption($context, $optionsHt, 'secure');
        $httponlyI32 = self::peekBoolOption($context, $optionsHt, 'httponly');
        $samesitePtr = self::peekStringOption($context, $optionsHt, 'samesite');
        $partitionedI32 = self::peekBoolOption($context, $optionsHt, 'partitioned');

        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            JitSetcookie::emitPending(
                $context,
                $namePtr,
                $valuePtr,
                $expiresI64,
                $pathPtr,
                $domainPtr,
                $secureI32,
                $httponlyI32,
                $samesitePtr,
                $partitionedI32
            );

            return $context->constantFromBool(true);
        }

        throw new \LogicException(
            $function.'() options array JIT requires AOT standalone load in this compiler build'
        );
    }

    private static function loadOptionsArray(Context $context, JITVariable $arg, string $function): Value
    {
        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            return $context->helper->loadValue($arg);
        }
        if (0 !== ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            return HashTableHelper::materializeNativeArrayForCall($context, $arg);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                JitValueBox::pointer($context, $arg->value)
            );
        }

        throw new \LogicException(
            $function.'(): Argument #3 ($options) must be of type array in this compiler build'
        );
    }

    private static function peekStringOption(Context $context, Value $ht, string $key): Value
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $keyPtr = $context->builder->load($context->constantStringFromString($key));
        $entry = $context->builder->call(
            $context->lookupFunction('__hashtable__peekStringKeyValue'),
            $ht,
            $keyPtr
        );
        $valPtrTy = $context->getTypeFromString('__value__*');
        $isMissing = $context->builder->icmp(Builder::INT_EQ, $entry, $valPtrTy->constNull());
        $tag = 'sc_opt_str_'.spl_object_id($context).'_'.$key;
        $miss = BasicBlockHelper::append($context, $tag.'_miss');
        $hit = BasicBlockHelper::append($context, $tag.'_hit');
        $done = BasicBlockHelper::append($context, $tag.'_done');
        $context->builder->branchIf($isMissing, $miss, $hit);

        $context->builder->positionAtEnd($miss);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($hit);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $entry
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($strPtr, $tag.'_phi');
        $phi->addIncoming($strPtr->constNull(), $miss);
        $phi->addIncoming($str, $hit);

        return $phi;
    }

    private static function peekLongOption(Context $context, Value $ht, string $key): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $keyPtr = $context->builder->load($context->constantStringFromString($key));
        $entry = $context->builder->call(
            $context->lookupFunction('__hashtable__peekStringKeyValue'),
            $ht,
            $keyPtr
        );
        $valPtrTy = $context->getTypeFromString('__value__*');
        $isMissing = $context->builder->icmp(Builder::INT_EQ, $entry, $valPtrTy->constNull());
        $tag = 'sc_opt_long_'.spl_object_id($context).'_'.$key;
        $miss = BasicBlockHelper::append($context, $tag.'_miss');
        $hit = BasicBlockHelper::append($context, $tag.'_hit');
        $done = BasicBlockHelper::append($context, $tag.'_done');
        $context->builder->branchIf($isMissing, $miss, $hit);

        $context->builder->positionAtEnd($miss);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($hit);
        $long = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $entry
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($i64, $tag.'_phi');
        $phi->addIncoming($i64->constInt(0, false), $miss);
        $phi->addIncoming($long, $hit);

        return $phi;
    }

    private static function peekBoolOption(Context $context, Value $ht, string $key): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $keyPtr = $context->builder->load($context->constantStringFromString($key));
        $entry = $context->builder->call(
            $context->lookupFunction('__hashtable__peekStringKeyValue'),
            $ht,
            $keyPtr
        );
        $valPtrTy = $context->getTypeFromString('__value__*');
        $isMissing = $context->builder->icmp(Builder::INT_EQ, $entry, $valPtrTy->constNull());
        $tag = 'sc_opt_bool_'.spl_object_id($context).'_'.$key;
        $miss = BasicBlockHelper::append($context, $tag.'_miss');
        $hit = BasicBlockHelper::append($context, $tag.'_hit');
        $done = BasicBlockHelper::append($context, $tag.'_done');
        $context->builder->branchIf($isMissing, $miss, $hit);

        $context->builder->positionAtEnd($miss);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($hit);
        $truthy = self::readTruthyI32($context, $entry);
        $truthyBlock = $context->builder->getInsertBlock();
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($i32, $tag.'_phi');
        $phi->addIncoming($i32->constInt(0, false), $miss);
        $phi->addIncoming($truthy, $truthyBlock);

        return $phi;
    }

    private static function readTruthyI32(Context $context, Value $entry): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $valueMap = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $tag = 'sc_opt_truthy_'.spl_object_id($context);
        $boolBlock = BasicBlockHelper::append($context, $tag.'_bool');
        $longBlock = BasicBlockHelper::append($context, $tag.'_long');
        $falseBlock = BasicBlockHelper::append($context, $tag.'_false');
        $done = BasicBlockHelper::append($context, $tag.'_done');

        $isBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NATIVE_BOOL, false)
        );
        $afterNativeBool = BasicBlockHelper::append($context, $tag.'_after_native_bool');
        $context->builder->branchIf($isBool, $boolBlock, $afterNativeBool);

        $context->builder->positionAtEnd($afterNativeBool);
        $isVmBool = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_BOOLEAN, false)
        );
        $afterBool = BasicBlockHelper::append($context, $tag.'_after_bool');
        $context->builder->branchIf($isVmBool, $boolBlock, $afterBool);

        $context->builder->positionAtEnd($boolBlock);
        $valueField = $context->builder->structGep($entry, $valueMap['value']);
        $firstBytePtr = $context->builder->inBoundsGEP(
            $valueField,
            $context->getTypeFromString('int32')->constInt(0, false),
            $i64->constInt(0, false)
        );
        $firstByte = $context->builder->load($firstBytePtr);
        $boolTruthy = $context->builder->zExt(
            $context->builder->icmp(Builder::INT_NE, $firstByte, $i8->constInt(0, false)),
            $i32
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($afterBool);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(JITVariable::TYPE_NATIVE_LONG, false)
        );
        $context->builder->branchIf($isLong, $longBlock, $falseBlock);

        $context->builder->positionAtEnd($longBlock);
        $longVal = $context->builder->call(
            $context->lookupFunction('__value__readLong'),
            $entry
        );
        $longTruthy = $context->builder->zExt(
            $context->builder->icmp(Builder::INT_NE, $longVal, $i64->constInt(0, false)),
            $i32
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($falseBlock);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $phi = $context->builder->phi($i32, $tag.'_phi');
        $phi->addIncoming($boolTruthy, $boolBlock);
        $phi->addIncoming($longTruthy, $longBlock);
        $phi->addIncoming($i32->constInt(0, false), $falseBlock);
        $context->builder->positionAtEnd($done);

        return $phi;
    }
}
