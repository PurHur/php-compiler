<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * JIT/AOT link for range() int/char/float lowering (#13502, #27563, #27158).
 *
 * NestedJIT of {@see \PHPCompiler\ext\standard\RangeIntJitHelper} returns a PHP
 * {@see \PHPCompiler\VM\HashTable} that is not a thin-AOT `__hashtable__` — multi-element
 * ranges hang/segfault under `phpc build` (#26956). Peer: hrtime pair (#26910) builds the
 * array in the LLVM bridge via `__hashtable__alloc` / `__hashtable__setLongAt`.
 *
 * Char path (#27563) uses the same loop shape with `__hashtable__setStringAt` and
 * single-byte `__string__alloc` (php-src char bounds via ord/chr).
 *
 * Float path (#27158) uses `__hashtable__setDoubleAt` with php-src's index×step size
 * formula ({@see \PHPCompiler\ext\standard\VmRange} buildFloatRange).
 *
 * VM SSOT remains {@see \PHPCompiler\ext\standard\RangeIntJitHelper} / {@see \PHPCompiler\ext\standard\VmRange}.
 * Zero/oversized step ValueErrors are emitted in {@see \PHPCompiler\ext\standard\range}
 * before this bridge runs.
 * php-src: ext/standard/array.c — php_range()
 */
final class RangeIntRuntime
{
    private const ABI_RANGE = '__range_int__copy';

    private const ABI_RANGE_CHAR = '__range_char__copy';

    private const ABI_RANGE_FLOAT = '__range_float__copy';

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

    /** Char-code endpoints (ord of single non-numeric letters) → single-char strings (#27563). */
    public static function charRange(Context $context, Value $start, Value $end, Value $step): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_RANGE_CHAR),
            $start,
            $end,
            $step
        );
    }

    /** Float endpoints/step → packed doubles (#27158). */
    public static function floatRange(Context $context, Value $start, Value $end, Value $step): Value
    {
        self::ensureLinked($context);

        return $context->builder->call(
            $context->lookupFunction(self::ABI_RANGE_FLOAT),
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
        self::implementInt($context);
        self::implementChar($context);
        self::implementFloat($context);
    }

    private static function implementInt(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_RANGE);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context, self::ABI_RANGE);

            return;
        }

        $savedBlock = self::saveInsertBlock($context);

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

        $step = self::normalizeStepSign($context, $start, $end, $stepIn);

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
        $zero = $i64->constInt(0, false);
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

        self::registerLinkedRuntime($context, self::ABI_RANGE);
        self::restoreInsertBlock($context, $savedBlock);
    }

    private static function implementChar(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_RANGE_CHAR);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context, self::ABI_RANGE_CHAR);

            return;
        }

        $savedBlock = self::saveInsertBlock($context);

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $i8 = $context->context->int8Type();
        $ft = $context->context->functionType($htPtr, false, $i64, $i64, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_RANGE_CHAR, $ft);

        $entry = $fn->appendBasicBlock('range_char_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $start = $fn->getParam(0);
        $end = $fn->getParam(1);
        $stepIn = $fn->getParam(2);

        $step = self::normalizeStepSign($context, $start, $end, $stepIn);

        $ht = HashTableHelper::alloc($context);
        $iSlot = $context->builder->alloca($i64, 1, 'range_char_i');
        $idxSlot = $context->builder->alloca($sizeT, 1, 'range_char_idx');
        $context->builder->store($start, $iSlot);
        $context->builder->store($sizeT->constInt(0, false), $idxSlot);

        $setString = $context->lookupFunction('__hashtable__setStringAt');
        $allocFn = $context->lookupFunction('__string__alloc');
        $map = $context->structFieldMap['__string__'];
        $done = BasicBlockHelper::append($context, 'range_char_done');
        $loopHead = BasicBlockHelper::append($context, 'range_char_head');
        $loopBody = BasicBlockHelper::append($context, 'range_char_body');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $zero = $i64->constInt(0, false);
        $stepIsPos = $context->builder->icmp(Builder::INT_SGT, $step, $zero);
        $condPos = $context->builder->icmp(Builder::INT_SLE, $i, $end);
        $condNeg = $context->builder->icmp(Builder::INT_SGE, $i, $end);
        $inRange = $context->builder->select($stepIsPos, $condPos, $condNeg);
        $context->builder->branchIf($inRange, $loopBody, $done);

        $context->builder->positionAtEnd($loopBody);
        $idx = $context->builder->load($idxSlot);
        // Match ext/standard/chr.php: one-byte string from code point (#27563).
        $one = $i64->constInt(1, false);
        $str = $context->builder->call($allocFn, $one);
        $byte = $context->builder->truncOrBitCast($i, $i8);
        $valGep = $context->builder->structGep($str, $map['value']);
        $context->builder->store($byte, $valGep);
        $context->builder->call($setString, $ht, $idx, $str);
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

        self::registerLinkedRuntime($context, self::ABI_RANGE_CHAR);
        self::restoreInsertBlock($context, $savedBlock);
    }

    /**
     * php-src float path: size = round((span/|step|)+1), element = start + i*step (#27158).
     * Positive half-up via trunc(x+0.5) (size formula args are non-negative after guards).
     */
    private static function implementFloat(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::ABI_RANGE_FLOAT);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context, self::ABI_RANGE_FLOAT);

            return;
        }

        $savedBlock = self::saveInsertBlock($context);

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $double = $context->getTypeFromString('double');
        $ft = $context->context->functionType($htPtr, false, $double, $double, $double);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(self::ABI_RANGE_FLOAT, $ft);

        $entry = $fn->appendBasicBlock('range_float_bridge_entry');
        $context->builder->positionAtEnd($entry);

        $start = $fn->getParam(0);
        $end = $fn->getParam(1);
        $stepIn = $fn->getParam(2);
        $step = self::normalizeFloatStepSign($context, $start, $end, $stepIn);

        $ht = HashTableHelper::alloc($context);
        $zeroD = $double->constReal(0.0);
        $oneD = $double->constReal(1.0);
        $halfD = $double->constReal(0.5);
        $stepPos = $context->builder->fcmp(Builder::REAL_OGT, $step, $zeroD);
        $emptyAsc = $context->builder->fcmp(Builder::REAL_OLT, $end, $start);
        $emptyDesc = $context->builder->fcmp(Builder::REAL_OGT, $end, $start);
        $isEmpty = $context->builder->select($stepPos, $emptyAsc, $emptyDesc);
        $doneEmpty = BasicBlockHelper::append($context, 'range_float_empty');
        $build = BasicBlockHelper::append($context, 'range_float_build');
        $context->builder->branchIf($isEmpty, $doneEmpty, $build);

        $context->builder->positionAtEnd($doneEmpty);
        $context->builder->returnValue($ht);

        $context->builder->positionAtEnd($build);
        $spanAsc = $context->builder->fsub($end, $start);
        $spanDesc = $context->builder->fsub($start, $end);
        $span = $context->builder->select($stepPos, $spanAsc, $spanDesc);
        $stepNeg = $context->builder->fcmp(Builder::REAL_OLT, $step, $zeroD);
        $stepAbs = $context->builder->select(
            $stepNeg,
            $context->builder->fsub($zeroD, $step),
            $step
        );
        $sizeExact = $context->builder->fadd(
            $context->builder->fdiv($span, $stepAbs),
            $oneD
        );
        // PHP_ROUND_HALF_UP on non-negative sizeExact.
        $size = $context->builder->fptosi(
            $context->builder->fadd($sizeExact, $halfD),
            $i64
        );

        $iSlot = $context->builder->alloca($i64, 1, 'range_float_i');
        $idxSlot = $context->builder->alloca($sizeT, 1, 'range_float_idx');
        $context->builder->store($i64->constInt(0, false), $iSlot);
        $context->builder->store($sizeT->constInt(0, false), $idxSlot);

        $setDouble = $context->lookupFunction('__hashtable__setDoubleAt');
        $done = BasicBlockHelper::append($context, 'range_float_done');
        $loopHead = BasicBlockHelper::append($context, 'range_float_head');
        $loopBody = BasicBlockHelper::append($context, 'range_float_body');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $inSize = $context->builder->icmp(Builder::INT_SLT, $i, $size);
        $context->builder->branchIf($inSize, $loopBody, $done);

        $context->builder->positionAtEnd($loopBody);
        $iAsDouble = $context->builder->sitofp($i, $double);
        $element = $context->builder->fadd(
            $start,
            $context->builder->fmul($iAsDouble, $step)
        );
        $pastAsc = $context->builder->fcmp(Builder::REAL_OGT, $element, $end);
        $pastDesc = $context->builder->fcmp(Builder::REAL_OLT, $element, $end);
        $pastEnd = $context->builder->select($stepPos, $pastAsc, $pastDesc);
        $storeBb = BasicBlockHelper::append($context, 'range_float_store');
        $context->builder->branchIf($pastEnd, $done, $storeBb);

        $context->builder->positionAtEnd($storeBb);
        $idx = $context->builder->load($idxSlot);
        $context->builder->call($setDouble, $ht, $idx, $element);
        $context->builder->store(
            $context->builder->addNoSignedWrap($i, $i64->constInt(1, false)),
            $iSlot
        );
        $context->builder->store(
            $context->builder->addNoSignedWrap($idx, $sizeT->constInt(1, false)),
            $idxSlot
        );
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($ht);

        self::registerLinkedRuntime($context, self::ABI_RANGE_FLOAT);
        self::restoreInsertBlock($context, $savedBlock);
    }

    private static function normalizeStepSign(
        Context $context,
        Value $start,
        Value $end,
        Value $stepIn
    ): Value {
        // Match RangeIntJitHelper::normalizeIntStepSign — wrong-signed step still spans.
        $i64 = $context->getTypeFromString('int64');
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

        return $context->builder->select($asc, $stepAsc, $stepDesc);
    }

    private static function normalizeFloatStepSign(
        Context $context,
        Value $start,
        Value $end,
        Value $stepIn
    ): Value {
        $double = $context->getTypeFromString('double');
        $zero = $double->constReal(0.0);
        $asc = $context->builder->fcmp(Builder::REAL_OLE, $start, $end);
        $stepNeg = $context->builder->fcmp(Builder::REAL_OLT, $stepIn, $zero);
        $stepPos = $context->builder->fcmp(Builder::REAL_OGT, $stepIn, $zero);
        $stepAbs = $context->builder->select(
            $stepNeg,
            $context->builder->fsub($zero, $stepIn),
            $stepIn
        );
        $stepNegAbs = $context->builder->fsub($zero, $stepAbs);
        $stepAsc = $context->builder->select($stepNeg, $stepAbs, $stepIn);
        $stepDesc = $context->builder->select($stepPos, $stepNegAbs, $stepIn);

        return $context->builder->select($asc, $stepAsc, $stepDesc);
    }

    /** @return mixed|null */
    private static function saveInsertBlock(Context $context)
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    /** @param mixed|null $savedBlock */
    private static function restoreInsertBlock(Context $context, $savedBlock): void
    {
        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function registerLinkedRuntime(Context $context, string $abi): void
    {
        $fn = $context->module->getNamedFunction($abi);
        if (null === $fn || 0 === $fn->countBasicBlocks()) {
            throw new \LogicException($abi.' missing after RangeIntRuntime bridge (#13502/#26956/#27563/#27158)');
        }
        $context->registerFunction($abi, $fn);
    }
}
