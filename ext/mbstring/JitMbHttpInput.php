<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\MbHttpInputRuntime;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitExplode;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_http_input() (#4636, #35271).
 *
 * Compile-time fold for omitted/null/literal `$type` (peer {@see mb_detect_order});
 * runtime type letter via NestedJIT {@see MbHttpInputJitHelper}.
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_http_input)
 */
final class JitMbHttpInput
{
    /**
     * @param list<JITVariable> $args
     */
    public static function invoke(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_http_input() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (0 === $argc
            || (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant)
        ) {
            // Cold identify unset → false (mb_parse_str AOT identify wiring is separate).
            return self::boxBoolFalse($context);
        }

        $typeLit = JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $typeLit) {
            return self::foldLiteralType($context, $typeLit);
        }

        return self::lowerRuntimeType($context, $args[0]);
    }

    private static function foldLiteralType(Context $context, string $type): Value
    {
        try {
            $result = MbstringState::httpInput($type);
        } catch (\ValueError $e) {
            return self::emitValueError($context, $e->getMessage());
        }
        if (\is_array($result)) {
            return self::boxStringList($context, $result);
        }
        if (\is_string($result)) {
            return self::boxStringConstant($context, $result);
        }

        return self::boxBoolFalse($context);
    }

    private static function lowerRuntimeType(Context $context, JITVariable $arg): Value
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbHttpInputRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_http_input_runtime');

        $typeStr = JitStringBuiltinArg::lower(
            $context,
            $arg,
            'mb_http_input',
            0,
            'type'
        );
        $kindRaw = JitNestedHelperCoerce::callHelper(
            $context,
            MbHttpInputRuntime::kindHelper($context),
            [$typeStr]
        );
        $i64 = $context->getTypeFromString('int64');
        $kind = JitNestedHelperCoerce::extractLongFromHelperResult($context, $kindRaw, $i64);

        $listRaw = JitNestedHelperCoerce::callHelper(
            $context,
            MbHttpInputRuntime::listJoinedHelper($context),
            []
        );
        $joined = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $listRaw);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $doneBb = BasicBlockHelper::append($context, 'mb_http_input_runtime_done');

        $listBb = BasicBlockHelper::append($context, 'mb_http_input_runtime_list');
        $joinedBb = BasicBlockHelper::append($context, 'mb_http_input_runtime_joined');
        $falseBb = BasicBlockHelper::append($context, 'mb_http_input_runtime_false');

        $isList = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i64->constInt(MbHttpInputJitHelper::KIND_LIST, false)
        );
        $notListBb = BasicBlockHelper::append($context, 'mb_http_input_runtime_not_list');
        $context->builder->branchIf($isList, $listBb, $notListBb);

        $context->builder->positionAtEnd($notListBb);
        $isJoined = $context->builder->icmp(
            Builder::INT_EQ,
            $kind,
            $i64->constInt(MbHttpInputJitHelper::KIND_JOINED, false)
        );
        $context->builder->branchIf($isJoined, $joinedBb, $falseBb);

        $context->builder->positionAtEnd($listBb);
        $ht = self::hashtableFromJoined($context, $joined);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($joinedBb);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $joined
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($falseBb);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $ptr;
    }

    /** @param list<string> $parts */
    private static function boxStringList(Context $context, array $parts): Value
    {
        $ht = MbstringState::hashTableFromStringList($parts);
        $cacheKey = 'mb_http_input_list_'.implode(',', $parts);
        $global = $context->constantArrayFromVmHashTable($cacheKey, $ht);
        $slot = JitValueBox::alloc($context);
        JitValueBox::copyFromPointer($context, $slot, $context->builder->load($global));

        return JitValueBox::pointer($context, $slot);
    }

    private static function boxStringConstant(Context $context, string $value): Value
    {
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $owned = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $context->builder->load($context->constantStringFromString($value))
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            $ptr,
            $owned
        );

        return $ptr;
    }

    private static function boxBoolFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool(
            $context,
            $slot,
            $context->getTypeFromString('int1')->constInt(0, false)
        );

        return JitValueBox::pointer($context, $slot);
    }

    private static function hashtableFromJoined(Context $context, Value $joined): Value
    {
        $tag = 'mhi';
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $slen = $context->builder->call($context->lookupFunction('__string__strlen'), $joined);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $slen, $zero);

        $emptyBlock = BasicBlockHelper::append($context, 'mb_http_input_empty_'.$tag);
        $explodeBlock = BasicBlockHelper::append($context, 'mb_http_input_explode_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'mb_http_input_ht_done_'.$tag);
        $context->builder->branchIf($isEmpty, $emptyBlock, $explodeBlock);

        $htTy = $context->getTypeFromString('__hashtable__*');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $htTy);

        $context->builder->positionAtEnd($emptyBlock);
        $context->builder->store(HashTableHelper::alloc($context), $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($explodeBlock);
        $delim = $context->builder->load(
            $context->constantStringFromString(MbHttpInputJitHelper::JOIN_DELIM)
        );
        $ownedJoined = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $joined
        );
        $ht = JitExplode::explode($context, $delim, $ownedJoined);
        $context->builder->store($ht, $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $context->builder->load($resultSlot);
    }

    private static function emitValueError(Context $context, string $message): Value
    {
        ExceptionBridge::ensureLinked($context);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            TypeErrorRaise::ensureStandaloneBodies($context);
        }
        if (null !== TryCatchHelper::resolveThrowHandler($context)) {
            TryCatchHelper::emitCatchableClassError($context, 'ValueError', $message);
            BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_http_input_valueerror_dead');
        } else {
            TypeErrorRaise::emitValueError($context, $message);
            if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
                $context->builder->call($context->lookupFunction('phpc_jit_abort_if_pending_type_error'));
            } else {
                $context->builder->call($context->lookupFunction('abort'));
                $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);
            }
            BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_http_input_valueerror_dead');
        }

        return self::boxBoolFalse($context);
    }
}
