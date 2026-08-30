<?php

declare(strict_types=1);

namespace PHPCompiler\ext\mbstring;

use PHPCompiler\ext\standard\JitExplode;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\MbDetectOrderRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT for mb_detect_order() (#13100, #29920, #35278, #35280 runtime string setter).
 *
 * Compile-time fold updates {@see MbstringAotFoldState} for peer mb_* folds; mutable order
 * CSV in module global via {@see MbDetectOrderRuntime} (peer {@see JitMbInternalEncoding}).
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

        $parsed = self::compileTimeOrder($context, $args[0]);
        if (null !== $parsed) {
            return self::lowerSetKnownOrder($context, $parsed);
        }

        return self::lowerSetRuntime($context, $args[0]);
    }

    private static function lowerGet(Context $context): Value
    {
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbDetectOrderRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_detect_order_get');

        $strPtr = $context->getTypeFromString('__string__*');
        $g = MbDetectOrderRuntime::orderCsvGlobal($context);
        $stored = $context->builder->load($g);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $stored,
            $strPtr->constNull()
        );

        $defaultBb = BasicBlockHelper::append($context, 'mb_detect_order_get_default');
        $storedBb = BasicBlockHelper::append($context, 'mb_detect_order_get_stored');
        $joinBb = BasicBlockHelper::append($context, 'mb_detect_order_get_join');
        $csvSlot = BasicBlockHelper::entryAlloca($context, $strPtr);

        $context->builder->branchIf($isNull, $defaultBb, $storedBb);

        $context->builder->positionAtEnd($defaultBb);
        $context->builder->store(
            $context->builder->load(
                $context->constantStringFromString(MbDetectOrderRuntime::DEFAULT_ORDER_CSV)
            ),
            $csvSlot
        );
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($storedBb);
        $context->builder->store($stored, $csvSlot);
        $context->builder->branch($joinBb);

        $context->builder->positionAtEnd($joinBb);
        $csv = $context->builder->load($csvSlot);
        $ht = self::hashtableFromCsv($context, $csv);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }

    /**
     * @param list<string> $order
     */
    private static function lowerSetKnownOrder(Context $context, array $order): Value
    {
        MbstringAotFoldState::setDetectOrder($context, $order);
        self::storeOrderCsv($context, implode(MbDetectOrderJitHelper::JOIN_DELIM, $order));

        return $context->getTypeFromString('int1')->constInt(1, false);
    }

    private static function lowerSetRuntime(Context $context, JITVariable $arg): Value
    {
        // Link NestedJIT helpers before lowering args — NestedJIT can invalidate prior IR (#34270 / #35856).
        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        MbDetectOrderRuntime::ensureLinked($context);
        if (null !== $savedInsert) {
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
        BasicBlockHelper::ensureOpenInsertBlock($context, 'mb_detect_order_set_runtime');

        $csvArg = JitStringBuiltinArg::lower(
            $context,
            $arg,
            'mb_detect_order',
            0,
            'encoding'
        );
        $csv = $context->builder->call(
            MbDetectOrderRuntime::parseHelper($context),
            $csvArg
        );
        self::storeOrderCsv($context, $csv);

        return $context->constantFromBool(true);
    }

    private static function storeOrderCsv(Context $context, string|Value $csv): void
    {
        if (\is_string($csv)) {
            $ptr = $context->builder->load($context->constantStringFromString($csv));
        } else {
            $ptr = $context->builder->call(
                $context->lookupFunction('__string__separate'),
                $csv
            );
        }
        $g = MbDetectOrderRuntime::orderCsvGlobal($context);
        $context->builder->store($ptr, $g);
    }

    private static function hashtableFromCsv(Context $context, Value $csv): Value
    {
        $tag = 'mdo';
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $slen = $context->builder->call($context->lookupFunction('__string__strlen'), $csv);
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $slen, $zero);

        $emptyBlock = BasicBlockHelper::append($context, 'mb_detect_order_empty_'.$tag);
        $explodeBlock = BasicBlockHelper::append($context, 'mb_detect_order_explode_'.$tag);
        $doneBlock = BasicBlockHelper::append($context, 'mb_detect_order_ht_done_'.$tag);
        $context->builder->branchIf($isEmpty, $emptyBlock, $explodeBlock);

        $htTy = $context->getTypeFromString('__hashtable__*');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $htTy);

        $context->builder->positionAtEnd($emptyBlock);
        $context->builder->store(HashTableHelper::alloc($context), $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($explodeBlock);
        $delim = $context->builder->load(
            $context->constantStringFromString(MbDetectOrderJitHelper::JOIN_DELIM)
        );
        $ownedCsv = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $csv
        );
        $ht = JitExplode::explode($context, $delim, $ownedCsv);
        $context->builder->store($ht, $resultSlot);
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $context->builder->load($resultSlot);
    }

    /**
     * Compile-time string CSV or packed native / compileTimeArray encoding list (#35278).
     *
     * @return list<string>|null
     */
    private static function compileTimeOrder(Context $context, JITVariable $arg): ?array
    {
        $encodingLit = JitStringArg::compileTimeLiteral($arg);
        if (null !== $encodingLit) {
            return MbstringEncodingRegistry::parseOrderList('mb_detect_order', 0, $encodingLit);
        }

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
}
