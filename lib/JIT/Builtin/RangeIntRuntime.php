<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for range() int lowering (#13502).
 *
 * NestedJIT of {@see \PHPCompiler\ext\standard\RangeIntJitHelper} returns a PHP
 * {@see \PHPCompiler\VM\HashTable} that is not a thin-AOT `__hashtable__` — multi-element
 * ranges hang/segfault under `phpc build` (#26956). Peer: hrtime pair (#26910) builds the
 * array in the LLVM bridge via `__hashtable__alloc` / `__hashtable__setLongAt`.
 *
 * VM SSOT remains {@see \PHPCompiler\ext\standard\RangeIntJitHelper} (VmRange delegates).
 * Zero/oversized step ValueErrors are emitted in {@see \PHPCompiler\ext\standard\range}
 * before this bridge runs.
 * php-src: ext/standard/array.c — php_range()
 */
final class RangeIntRuntime
{
    private const ABI_RANGE = '__range_int__copy';

    public static function intRange(Context $context, Value $start, Value $end, Value $step): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_RANGE),
            $start,
            $end,
            $step
        );
    }

    public static function ensureLinked(Context $context): void
    {
        self::implement($context);
    }

    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_RANGE);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $ft = $context->context->functionType($htPtr, false, $i64, $i64, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_RANGE, $ft);

        $entry = $fn->appendBasicBlock('range_int_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $start = $fn->getParam(0);
        $end = $fn->getParam(1);
        $stepIn = $fn->getParam(2);

        // Match RangeIntJitHelper::normalizeIntStepSign — wrong-signed step still spans.
        $zero = $i64->constInt(0, false);
        $asc = $context->builder->icmp(Builder::INT_SLE, $start, $end);
        $stepNeg = $context->builder->icmp(Builder::INT_SLT, $stepIn, $zero);
        $stepPos = $context->builder->icmp(Builder::INT_SGT, $stepIn, $zero);
        $stepAbs = $context->builder->select(
            $stepNeg,
            $context->builder->sub($zero, $stepIn),
            $stepIn
        );
        $stepNegAbs = $context->builder->sub($zero, $stepAbs);
        $stepAsc = $context->builder->select($stepNeg, $stepAbs, $stepIn);
        $stepDesc = $context->builder->select($stepPos, $stepNegAbs, $stepIn);
        $step = $context->builder->select($asc, $stepAsc, $stepDesc);

        $ht = HashTableHelper::alloc($context);
        $iSlot = $context->builder->alloca($i64, 1, 'range_i');
        $idxSlot = $context->builder->alloca($sizeT, 1, 'range_idx');
        $context->builder->store($start, $iSlot);
        $context->builder->store($sizeT->constInt(0, false), $idxSlot);

        $setLong = $context->lookupFunction('__hashtable__setLongAt');
        $done = BasicBlockHelper::append($context, 'range_done');
        $loopHead = BasicBlockHelper::append($context, 'range_head');
        $loopBody = BasicBlockHelper::append($context, 'range_body');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $stepIsPos = $context->builder->icmp(Builder::INT_SGT, $step, $zero);
        $condPos = $context->builder->icmp(Builder::INT_SLE, $i, $end);
        $condNeg = $context->builder->icmp(Builder::INT_SGE, $i, $end);
        $inRange = $context->builder->select($stepIsPos, $condPos, $condNeg);
        $context->builder->branchIf($inRange, $loopBody, $done);

        $context->builder->positionAtEnd($loopBody);
        $idx = $context->builder->load($idxSlot);
        $context->builder->call($setLong, $ht, $idx, $i);
        $context->builder->store(
            $context->builder->addNoSignedWrap($i, $step),
            $iSlot
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $sizeT->constInt(1, false)),
            $idxSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($ht);

        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        $fn = $context->module->getNamedFunction(self::ABI_RANGE);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException(self::ABI_RANGE.' missing after RangeIntRuntime bridge (#13502/#26956)');
        }
        $context->registerFunction(self::ABI_RANGE, $fn);
    }
}
