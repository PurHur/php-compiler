<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\JitExplode;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbDetectOrderRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_detect_order() (#13100, #29920, #35280 runtime string).
 *
 * Compile-time string/null fold via {@see MbstringAotFoldState}; runtime string via NestedJIT
 * packed i64 module global (peer {@see JitMbInternalEncoding}).
 *
 * php-src: ext/mbstring/mbstring.c — PHP_FUNCTION(mb_detect_order)
 */
final class JitMbDetectOrder
{
    /**
     * @param list<JITVariable> $args
     */
    public static function invoke(Context $context, array $args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \ArgumentCountError(sprintf(
                'mb_detect_order() expects at most 1 argument, %d given',
                $argc
            ));
        }
        if (0 === $argc
            || (JITVariable::TYPE_NULL === $args[0]->type || $args[0]->isNullConstant)
        ) {
            return self::lowerGet($context);
        }

        $encodingLit = JitStringArg::compileTimeLiteral($args[0]);
        if (null !== $encodingLit) {
            $parsed = MbstringEncodingRegistry::parseOrderList('mb_detect_order', 0, $encodingLit);
            MbstringAotFoldState::setDetectOrder($context, $parsed);
            self::storePackedLiteral($context, $parsed);

            return $context->getTypeFromString('int1')->constInt(1, false);
        }

        // Compile-time array setter (#35278).
        $fromArray = self::compileTimeOrderFromArray($context, $args[0]);
        if (null !== $fromArray) {
            MbstringAotFoldState::setDetectOrder($context, $fromArray);
            self::storePackedLiteral($context, $fromArray);

            return $context->getTypeFromString('int1')->constInt(1, false);
        }

        if (0 !== ($args[0]->type & JITVariable::IS_NATIVE_ARRAY)
            || null !== ($args[0]->compileTimeArray ?? null)
            || JITVariable::TYPE_HASHTABLE === $args[0]->type
        ) {
            throw new \LogicException(
                'mb_detect_order() JIT setter requires a compile-time string or array of string literals in this compiler build'
            );
        }

        return self::lowerRuntimeSet($context, $args[0]);
    }

    /**
     * Compile-time packed native / compileTimeArray encoding list (#35278).
     *
     * @return list<string>|null
     */
    private static function compileTimeOrderFromArray(Context $context, JITVariable $arg): ?array
    {
        $fromNative = self::compileTimeOrderFromNativeArray($context, $arg);
        if (null !== $fromNative) {
            return $fromNative;
        }

        $arr = $arg->compileTimeArray ?? null;
        if (null === $arr) {
            return null;
        }
        $order = [];
        foreach ($arr as $elem) {
            if (\is_string($elem)) {
                $s = $elem;
            } elseif ($elem instanceof JITVariable) {
                $s = JitStringArg::compileTimeLiteral($elem);
                if (null === $s) {
                    return null;
                }
            } else {
                return null;
            }
            $canonical = MbstringEncodingRegistry::resolve($s);
            if (null === $canonical) {
                throw new \ValueError(sprintf(
                    'mb_detect_order(): Argument #1 ($encoding) contains invalid encoding "%s"',
                    $s
                ));
            }
            $order[] = $canonical;
        }
        MbstringEncodingRegistry::assertNonEmptyOrder('mb_detect_order', 0, $order);

        return $order;
    }

    /**
     * Packed native-array encoding list (`['UTF-8','ASCII']`) via dimFetch (#35278).
     *
     * @return list<string>|null
     */
    private static function compileTimeOrderFromNativeArray(Context $context, JITVariable $arg): ?array
    {
        if (0 === ($arg->type & JITVariable::IS_NATIVE_ARRAY)) {
            return null;
        }
        $n = $arg->nextFreeElement;
        if ($n <= 0) {
            return null;
        }
        $order = [];
        for ($i = 0; $i < $n; ++$i) {
            $elem = $arg->dimFetch(JITVariable::fromConstantInt($context, $i));
            $s = JitStringArg::compileTimeLiteral($elem);
            if (null === $s) {
                return null;
            }
            $canonical = MbstringEncodingRegistry::resolve($s);
            if (null === $canonical) {
                throw new \ValueError(sprintf(
                    'mb_detect_order(): Argument #1 ($encoding) contains invalid encoding "%s"',
                    $s
                ));
            }
            $order[] = $canonical;
        }
        MbstringEncodingRegistry::assertNonEmptyOrder('mb_detect_order', 0, $order);

        return $order;
    }

    private static function lowerGet(Context $context): Value
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbDetectOrderRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_detect_order_get');

        $g = MbDetectOrderRuntime::orderPackedGlobal($context);
        $packed = $context->builder->load($g);
        $i64 = $context->getTypeFromString('int64');
        $isUnset = $context->builder->icmp(
            Builder::INT_EQ,
            $packed,
            $i64->constInt(0, false)
        );
        $defaultBb = BasicBlockHelper::append($context, 'mb_detect_order_get_default');
        $unpackBb = BasicBlockHelper::append($context, 'mb_detect_order_get_unpack');
        $doneBb = BasicBlockHelper::append($context, 'mb_detect_order_get_done');
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->branchIf($isUnset, $defaultBb, $unpackBb);

        $context->builder->positionAtEnd($defaultBb);
        $order = MbstringAotFoldState::detectOrder($context) ?? MbstringState::detectOrder();
        $ht = MbstringState::hashTableFromStringList($order);
        $cacheKey = 'mb_detect_order_default_'.implode(',', $order);
        $global = $context->constantArrayFromVmHashTable($cacheKey, $ht);
        JitValueBox::copyFromPointer($context, $slot, $context->builder->load($global));
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($unpackBb);
        $joinedRaw = JitNestedHelperCoerce::callHelper(
            $context,
            MbDetectOrderRuntime::joinedFromPackedHelper($context),
            [$packed]
        );
        $joined = JitNestedHelperCoerce::extractStringPtrFromHelperResult($context, $joinedRaw);
        $runtimeHt = self::hashtableFromJoined($context, $joined);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $runtimeHt
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $slot;
    }

    private static function lowerRuntimeSet(Context $context, JITVariable $arg): Value
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbDetectOrderRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_detect_order_runtime_set');

        $list = JitStringBuiltinArg::lower(
            $context,
            $arg,
            'mb_detect_order',
            0,
            'encoding'
        );
        $raw = JitNestedHelperCoerce::callHelper(
            $context,
            MbDetectOrderRuntime::packHelper($context),
            [$list]
        );
        $i64 = $context->getTypeFromString('int64');
        $packed = JitNestedHelperCoerce::extractLongFromHelperResult($context, $raw, $i64);
        $g = MbDetectOrderRuntime::orderPackedGlobal($context);
        $context->builder->store($packed, $g);

        return $context->getTypeFromString('int1')->constInt(1, false);
    }

    /**
     * @param list<string> $order
     */
    private static function storePackedLiteral(Context $context, array $order): void
    {
        $packed = MbDetectOrderJitHelper::packOrderList($order);
        if (0 === $packed) {
            return;
        }
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbDetectOrderRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_detect_order_store_lit');
        $g = MbDetectOrderRuntime::orderPackedGlobal($context);
        $i64 = $context->getTypeFromString('int64');
        $context->builder->store($i64->constInt($packed, false), $g);
    }

    /** Empty joined → []; otherwise explode on RS (peer {@see JitMbEncodingRegistry}). */
    private static function hashtableFromJoined(Context $context, Value $joined): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $slen = $context->builder->call($context->lookupFunction('__string__strlen'), $joined);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $slen, $zero);

        $emptyBlock = BasicBlockHelper::append($context, 'mb_detect_order_joined_empty');
        $explodeBlock = BasicBlockHelper::append($context, 'mb_detect_order_joined_explode');
        $doneBlock = BasicBlockHelper::append($context, 'mb_detect_order_joined_done');
        $context->builder->branchIf($isEmpty, $emptyBlock, $explodeBlock);

        $htTy = $context->getTypeFromString('__hashtable__*');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $htTy);

        $context->builder->positionAtEnd($emptyBlock);
        $context->builder->store(HashTableHelper::alloc($context), $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($explodeBlock);
        $delim = $context->builder->load(
            $context->constantStringFromString("\x1E")
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
}
