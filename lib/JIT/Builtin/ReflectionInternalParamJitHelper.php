<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\BuiltinInternalArgInfo;
use PHPCompiler\BuiltinInternalDefaultValues;
use PHPCompiler\BuiltinParamNames;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ReflectionSupport;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * Compile-time ReflectionParameter metadata for a single recorded internal function (#25469 / #28780).
 *
 * Mirrors {@see ReflectionFunctionGetReturnType} fold — avoids broken runtime
 * {@see ReflectionTypeFromLabelLookupRuntime} boxes for multi-param internals like hash_pbkdf2.
 */
final class ReflectionInternalParamJitHelper
{
    public static function singleRecordedFuncLc(): ?string
    {
        $recorded = ReflectionInternalFunctionLowering::recordedFunctions();
        if (1 !== \count($recorded)) {
            return null;
        }

        return (string) array_key_first($recorded);
    }

    public static function paramIndexI64(Context $context, Variable $receiverArg): Value
    {
        $obj = ReflectionSetup::loadObjectFromArg($context, $receiverArg);

        return ReflectionSetup::integerPropertyAsI64(
            $context,
            $obj,
            'ReflectionParameter',
            ReflectionSupport::PROP_PARAM_INDEX
        );
    }

    public static function emitTypeFromRecordedInternal(Context $context, Variable $receiverArg): ?Value
    {
        $funcLc = self::singleRecordedFuncLc();
        if (null === $funcLc) {
            return null;
        }
        $names = BuiltinParamNames::paramNamesForInternalFunction($funcLc)
            ?? BuiltinParamNames::forFunction($funcLc);
        if (null === $names || [] === $names) {
            return null;
        }

        $index = self::paramIndexI64($context, $receiverArg);
        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__value__*'));
        $nullPtr = JitValueBox::pointer($context, JitValueBox::alloc($context));
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            $nullPtr
        );
        $context->builder->store(JitValueBox::pointer($context, $nullPtr), $resultSlot);
        $merge = BasicBlockHelper::append($context, 'refl_int_param_type_merge');
        $next = $context->builder->getInsertBlock();
        $i64 = $context->getTypeFromString('int64');

        foreach (array_keys($names) as $paramIndex) {
            $info = BuiltinInternalArgInfo::paramInfoForFunction($funcLc, (int) $paramIndex);
            $label = null !== $info ? trim((string) ($info['type'] ?? '')) : '';
            if ('' === $label) {
                continue;
            }
            $check = BasicBlockHelper::append($context, 'refl_int_param_type_check_'.$paramIndex);
            $match = BasicBlockHelper::append($context, 'refl_int_param_type_match_'.$paramIndex);
            $context->builder->positionAtEnd($next);
            $context->builder->branch($check);
            $context->builder->positionAtEnd($check);
            $indexOk = $context->builder->icmp(
                Builder::INT_EQ,
                $index,
                $i64->constInt((int) $paramIndex, false)
            );
            $fallthrough = BasicBlockHelper::append($context, 'refl_int_param_type_next_'.$paramIndex);
            $context->builder->branchIf($indexOk, $match, $fallthrough);
            $context->builder->positionAtEnd($match);
            $boxed = ReflectionTypeJitHelper::emitTypeFromLabel($context, $label);
            $context->builder->store(JitValueBox::pointer($context, $boxed), $resultSlot);
            $context->builder->branch($merge);
            $next = $fallthrough;
        }

        $context->builder->positionAtEnd($next);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);

        return $context->builder->load($resultSlot);
    }

    public static function emitHasTypeFromRecordedInternal(Context $context, Variable $receiverArg): ?Value
    {
        $funcLc = self::singleRecordedFuncLc();
        if (null === $funcLc) {
            return null;
        }
        $names = BuiltinParamNames::paramNamesForInternalFunction($funcLc)
            ?? BuiltinParamNames::forFunction($funcLc);
        if (null === $names || [] === $names) {
            return null;
        }

        $index = self::paramIndexI64($context, $receiverArg);
        $resultSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $resultSlot, $context->getTypeFromString('int1')->constInt(0, false));
        $merge = BasicBlockHelper::append($context, 'refl_int_param_hastype_merge');
        $next = $context->builder->getInsertBlock();
        $i64 = $context->getTypeFromString('int64');
        $trueVal = $context->getTypeFromString('int1')->constInt(1, false);

        foreach (array_keys($names) as $paramIndex) {
            $info = BuiltinInternalArgInfo::paramInfoForFunction($funcLc, (int) $paramIndex);
            $label = null !== $info ? trim((string) ($info['type'] ?? '')) : '';
            if ('' === $label) {
                continue;
            }
            $check = BasicBlockHelper::append($context, 'refl_int_param_hastype_check_'.$paramIndex);
            $match = BasicBlockHelper::append($context, 'refl_int_param_hastype_match_'.$paramIndex);
            $context->builder->positionAtEnd($next);
            $context->builder->branch($check);
            $context->builder->positionAtEnd($check);
            $indexOk = $context->builder->icmp(
                Builder::INT_EQ,
                $index,
                $i64->constInt((int) $paramIndex, false)
            );
            $fallthrough = BasicBlockHelper::append($context, 'refl_int_param_hastype_next_'.$paramIndex);
            $context->builder->branchIf($indexOk, $match, $fallthrough);
            $context->builder->positionAtEnd($match);
            JitValueBox::writeBool($context, $resultSlot, $trueVal);
            $context->builder->branch($merge);
            $next = $fallthrough;
        }

        $context->builder->positionAtEnd($next);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);

        return $resultSlot;
    }

    public static function emitIsOptionalFromRecordedInternal(Context $context, Variable $receiverArg): ?Value
    {
        $funcLc = self::singleRecordedFuncLc();
        if (null === $funcLc) {
            return null;
        }
        $names = BuiltinParamNames::paramNamesForInternalFunction($funcLc)
            ?? BuiltinParamNames::forFunction($funcLc);
        if (null === $names || [] === $names) {
            return null;
        }

        $index = self::paramIndexI64($context, $receiverArg);
        $resultSlot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $resultSlot, $context->getTypeFromString('int1')->constInt(0, false));
        $merge = BasicBlockHelper::append($context, 'refl_int_param_optional_merge');
        $next = $context->builder->getInsertBlock();
        $i64 = $context->getTypeFromString('int64');
        $trueVal = $context->getTypeFromString('int1')->constInt(1, false);

        foreach (array_keys($names) as $paramIndex) {
            $info = BuiltinInternalArgInfo::paramInfoForFunction($funcLc, (int) $paramIndex);
            if (null === $info || empty($info['isOptional'])) {
                continue;
            }
            $check = BasicBlockHelper::append($context, 'refl_int_param_optional_check_'.$paramIndex);
            $match = BasicBlockHelper::append($context, 'refl_int_param_optional_match_'.$paramIndex);
            $context->builder->positionAtEnd($next);
            $context->builder->branch($check);
            $context->builder->positionAtEnd($check);
            $indexOk = $context->builder->icmp(
                Builder::INT_EQ,
                $index,
                $i64->constInt((int) $paramIndex, false)
            );
            $fallthrough = BasicBlockHelper::append($context, 'refl_int_param_optional_next_'.$paramIndex);
            $context->builder->branchIf($indexOk, $match, $fallthrough);
            $context->builder->positionAtEnd($match);
            JitValueBox::writeBool($context, $resultSlot, $trueVal);
            $context->builder->branch($merge);
            $next = $fallthrough;
        }

        $context->builder->positionAtEnd($next);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);

        return $resultSlot;
    }

    public static function emitDefaultValueFromRecordedInternal(Context $context, Variable $receiverArg): ?Value
    {
        $funcLc = self::singleRecordedFuncLc();
        if (null === $funcLc) {
            return null;
        }
        $names = BuiltinParamNames::paramNamesForInternalFunction($funcLc)
            ?? BuiltinParamNames::forFunction($funcLc);
        if (null === $names || [] === $names) {
            return null;
        }

        $indexVal = self::paramIndexI64($context, $receiverArg);
        $resultSlot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $resultSlot)
        );
        $merge = BasicBlockHelper::append($context, 'refl_int_param_default_merge');
        $next = $context->builder->getInsertBlock();
        $i64 = $context->getTypeFromString('int64');
        $hasCase = false;

        foreach (array_keys($names) as $paramIndex) {
            $info = BuiltinInternalArgInfo::paramInfoForFunction($funcLc, (int) $paramIndex);
            if (!BuiltinInternalDefaultValues::isAvailable($funcLc, (int) $paramIndex, $info, false)) {
                continue;
            }
            $tmp = new \PHPCompiler\VM\Variable();
            if (!BuiltinInternalDefaultValues::materialize($tmp, $funcLc, (int) $paramIndex, $info)) {
                continue;
            }
            $hasCase = true;
            $check = BasicBlockHelper::append($context, 'refl_int_param_default_check_'.$paramIndex);
            $match = BasicBlockHelper::append($context, 'refl_int_param_default_match_'.$paramIndex);
            $context->builder->positionAtEnd($next);
            $context->builder->branch($check);
            $context->builder->positionAtEnd($check);
            $indexOk = $context->builder->icmp(
                Builder::INT_EQ,
                $indexVal,
                $i64->constInt((int) $paramIndex, false)
            );
            $fallthrough = BasicBlockHelper::append($context, 'refl_int_param_default_next_'.$paramIndex);
            $context->builder->branchIf($indexOk, $match, $fallthrough);
            $context->builder->positionAtEnd($match);
            self::emitMaterializedDefaultIntoSlot($context, $resultSlot, $tmp);
            $context->builder->branch($merge);
            $next = $fallthrough;
        }

        if (!$hasCase) {
            return null;
        }

        $context->builder->positionAtEnd($next);
        $context->builder->branch($merge);
        $context->builder->positionAtEnd($merge);

        return $resultSlot;
    }

    private static function emitMaterializedDefaultIntoSlot(
        Context $context,
        Value $resultSlot,
        \PHPCompiler\VM\Variable $value,
    ): void {
        $resolved = $value->resolveIndirect();
        switch ($resolved->type) {
            case \PHPCompiler\VM\Variable::TYPE_INTEGER:
                JitValueBox::writeLong(
                    $context,
                    $resultSlot,
                    $context->getTypeFromString('int64')->constInt($resolved->toInt(), false)
                );

                return;
            case \PHPCompiler\VM\Variable::TYPE_BOOLEAN:
                JitValueBox::writeBool(
                    $context,
                    $resultSlot,
                    $context->getTypeFromString('int1')->constInt($resolved->toBool() ? 1 : 0, false)
                );

                return;
            case \PHPCompiler\VM\Variable::TYPE_ARRAY:
                $ht = HashTableHelper::alloc($context);
                $context->builder->call(
                    $context->lookupFunction('__value__writeHashtable'),
                    JitValueBox::pointer($context, $resultSlot),
                    $ht
                );

                return;
            case \PHPCompiler\VM\Variable::TYPE_NULL:
                $context->builder->call(
                    $context->lookupFunction('__value__writeNull'),
                    JitValueBox::pointer($context, $resultSlot)
                );

                return;
            default:
                $context->builder->call(
                    $context->lookupFunction('__value__writeNull'),
                    JitValueBox::pointer($context, $resultSlot)
                );
        }
    }
}
