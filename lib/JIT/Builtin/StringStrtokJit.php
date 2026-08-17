<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM phpc_strtok with module-global continuation state (mirrors VmString::strtok).
 *
 * NestedJIT StrtokJitHelper aborts under thin AOT (#26906); compile-time literal fold
 * then hung multi-shot continue call sites in loops (#27645). Emit LLVM state here so
 * AOT/JIT both advance correctly. VM SSOT remains {@see \PHPCompiler\ext\standard\VmString::strtok}.
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(strtok)
 */
final class StringStrtokJit
{
    private const MAX = 65536;

    private const GLOBAL_BUF = '__phpc_strtok_buf';

    private const GLOBAL_LEN = '__phpc_strtok_len';

    private const GLOBAL_LAST = '__phpc_strtok_last';

    /** @var Value|null */
    private static $bufGlobal = null;

    /** @var Value|null */
    private static $lenGlobal = null;

    /** @var Value|null */
    private static $lastGlobal = null;

    public static function implement(Context $context): void
    {
        $restore = self::captureInsertBlock($context);
        // Module-local memcpy(3) after LibcExtern always-on drop (#31885).
        \PHPCompiler\JIT\LibcExtern::ensureMemcpyDecl($context);
        self::ensureGlobals($context);
        self::implementIfMissing($context, '__phpc_strtok_reset', self::emitReset(...));
        self::implementIfMissing($context, '__phpc_strtok_init', self::emitInit(...));

        $probe = $context->module->getNamedFunction('phpc_strtok');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('phpc_strtok', $probe);
            self::restoreInsertBlock($context, $restore);

            return;
        }

        $fn = self::declareStrtokIfMissing($context);
        self::emitStrtok($context, $fn);
        $context->registerFunction('phpc_strtok', $fn);
        self::restoreInsertBlock($context, $restore);
    }

    private static function ensureGlobals(Context $context): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $bufType = $i8->arrayType(self::MAX);
        $zero = $i64->constInt(0, false);

        if (null === $context->module->getNamedGlobal(self::GLOBAL_BUF)) {
            self::$bufGlobal = $context->module->addGlobal($bufType, self::GLOBAL_BUF);
            self::$bufGlobal->setInitializer($bufType->constNull());
        } else {
            self::$bufGlobal = $context->module->getNamedGlobal(self::GLOBAL_BUF);
        }

        if (null === $context->module->getNamedGlobal(self::GLOBAL_LEN)) {
            self::$lenGlobal = $context->module->addGlobal($i64, self::GLOBAL_LEN);
            self::$lenGlobal->setInitializer($zero);
        } else {
            self::$lenGlobal = $context->module->getNamedGlobal(self::GLOBAL_LEN);
        }

        if (null === $context->module->getNamedGlobal(self::GLOBAL_LAST)) {
            self::$lastGlobal = $context->module->addGlobal($i8p, self::GLOBAL_LAST);
            self::$lastGlobal->setInitializer($i8p->constNull());
        } else {
            self::$lastGlobal = $context->module->getNamedGlobal(self::GLOBAL_LAST);
        }
    }

    private static function bufBasePtr(Context $context): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->inBoundsGEP(
            self::$bufGlobal,
            $i32->constInt(0, false),
            $i64->constInt(0, false)
        );
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        try {
            $fn = $context->lookupFunction($name);
        } catch (\Throwable) {
            $void = $context->context->voidType();
            if ('__phpc_strtok_init' === $name) {
                $strPtr = $context->getTypeFromString('__string__*');
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($void, false, $strPtr)
                );
            } else {
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($void, false)
                );
            }
            $context->registerFunction($name, $fn);
        }

        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);

            return;
        }
        $context->builder->clearInsertionPosition();
    }

    private static function declareStrtokIfMissing(Context $context): LlvmFunction
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $ft = $context->context->functionType($strPtr, false, $strPtr, $strPtr, $i8);
        try {
            return $context->lookupFunction('phpc_strtok');
        } catch (\Throwable) {
            $fn = $context->module->addFunction('phpc_strtok', $ft);
            $context->registerFunction('phpc_strtok', $fn);

            return $fn;
        }
    }

    private static function emitReset(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        $context->builder->store($i64->constInt(0, false), self::$lenGlobal);
        $context->builder->store($i8p->constNull(), self::$lastGlobal);
        $bufPtr = self::bufBasePtr($context);
        $context->builder->store($i8->constInt(0, false), $bufPtr);
        $context->builder->returnVoid();
    }

    private static function emitInit(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $strPtr = $context->getTypeFromString('__string__*');
        $map = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $nullStr = $strPtr->constNull();
        $max = $i64->constInt(self::MAX, false);
        $one = $i64->constInt(1, false);
        $zero = $i64->constInt(0, false);

        $strIn = $fn->getParam(0);
        $context->builder->call($context->lookupFunction('__phpc_strtok_reset'));

        $done = $fn->appendBasicBlock('init_done');
        $work = $fn->appendBasicBlock('init_work');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $strIn, $nullStr);
        $context->builder->branchIf($isNull, $done, $work);

        $context->builder->positionAtEnd($work);
        $data = $context->builder->structGep($strIn, $map['value']);
        $len = $context->builder->load($context->builder->structGep($strIn, $map['length']));
        $tooLong = $context->builder->icmp(Builder::INT_UGE, $len, $max);
        $clamped = $context->builder->select($tooLong, $context->builder->sub($max, $one), $len);
        $bufPtr = self::bufBasePtr($context);
        $hasLen = $context->builder->icmp(Builder::INT_UGT, $clamped, $zero);
        $copy = $fn->appendBasicBlock('init_copy');
        $afterCopy = $fn->appendBasicBlock('init_after_copy');
        $context->builder->branchIf($hasLen, $copy, $afterCopy);

        $context->builder->positionAtEnd($copy);
        $voidPtr = $context->getTypeFromString('void*');
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($bufPtr),
            $context->bytePtr($data),
            $context->builder->truncOrBitCast($clamped, $sizeT)
        );
        $context->builder->branch($afterCopy);

        $context->builder->positionAtEnd($afterCopy);
        $nulPtr = $context->builder->inBoundsGEP($bufPtr, $clamped);
        $context->builder->store($i8->constInt(0, false), $nulPtr);
        $context->builder->store($clamped, self::$lenGlobal);
        $context->builder->store($bufPtr, self::$lastGlobal);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
    }

    private static function emitStrtok(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $strPtrTy = $context->getTypeFromString('__string__*');
        $map = $context->structFieldMap['__string__'];
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $nullStr = $strPtrTy->constNull();
        $nullPtr = $i8p->constNull();
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $strIn = $fn->getParam(0);
        $tokIn = $fn->getParam(1);
        $initIn = $fn->getParam(2);

        $initBb = $fn->appendBasicBlock('st_init');
        $contBb = $fn->appendBasicBlock('st_cont');
        $isInit = $context->builder->icmp(Builder::INT_NE, $initIn, $i8->constInt(0, false));
        $context->builder->branchIf($isInit, $initBb, $contBb);

        $context->builder->positionAtEnd($initBb);
        $context->builder->call($context->lookupFunction('__phpc_strtok_init'), $strIn);
        $context->builder->branch($contBb);

        $context->builder->positionAtEnd($contBb);
        $noLast = $fn->appendBasicBlock('st_no_last');
        $tokCheck = $fn->appendBasicBlock('st_tok_check');
        $last = $context->builder->load(self::$lastGlobal);
        $hasLast = $context->builder->icmp(Builder::INT_NE, $last, $nullPtr);
        $context->builder->branchIf($hasLast, $tokCheck, $noLast);

        $context->builder->positionAtEnd($noLast);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($tokCheck);
        $nullTok = $fn->appendBasicBlock('st_null_tok');
        $range = $fn->appendBasicBlock('st_range');
        $hasTok = $context->builder->icmp(Builder::INT_NE, $tokIn, $nullStr);
        $context->builder->branchIf($hasTok, $range, $nullTok);

        $context->builder->positionAtEnd($nullTok);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($range);
        $pSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $peSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store($last, $pSlot);
        $bufPtr = self::bufBasePtr($context);
        $len = $context->builder->load(self::$lenGlobal);
        $pe = $context->builder->inBoundsGEP($bufPtr, $len);
        $context->builder->store($pe, $peSlot);

        $p = $context->builder->load($pSlot);
        $peVal = $context->builder->load($peSlot);
        $atEnd = $context->builder->icmp(
            Builder::INT_UGE,
            $context->builder->ptrToInt($p, $i64),
            $context->builder->ptrToInt($peVal, $i64)
        );
        $empty = $fn->appendBasicBlock('st_empty');
        $tableBb = $fn->appendBasicBlock('st_table');
        $context->builder->branchIf($atEnd, $empty, $tableBb);

        $context->builder->positionAtEnd($empty);
        $context->builder->call($context->lookupFunction('__phpc_strtok_reset'));
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($tableBb);
        // alloca() takes only the element type — use arrayType(256); cast before memset (#27645).
        $tableSlot = $context->builder->alloca($i8->arrayType(256));
        $tablePtr = $context->builder->pointerCast($tableSlot, $i8p);
        $context->intrinsic->memset($tablePtr, $i8->constInt(0, false), $i64->constInt(256, false), false);

        $tokLen = $context->builder->load($context->builder->structGep($tokIn, $map['length']));
        $tokData = $context->builder->structGep($tokIn, $map['value']);
        $tiSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($zero, $tiSlot);
        $fillHead = $fn->appendBasicBlock('st_fill_head');
        $fillBody = $fn->appendBasicBlock('st_fill_body');
        $fillDone = $fn->appendBasicBlock('st_fill_done');
        $context->builder->branch($fillHead);

        $context->builder->positionAtEnd($fillHead);
        $ti = $context->builder->load($tiSlot);
        $fillEnd = $context->builder->icmp(Builder::INT_SGE, $ti, $tokLen);
        $context->builder->branchIf($fillEnd, $fillDone, $fillBody);

        $context->builder->positionAtEnd($fillBody);
        $ch = $context->builder->load($context->builder->gep($tokData, $context->builder->trunc($ti, $context->getTypeFromString('int32'))));
        $idx = $context->builder->zExt($ch, $i64);
        $context->builder->store($i8->constInt(1, false), $context->builder->inBoundsGEP($tablePtr, $idx));
        $context->builder->store($context->builder->addNoSignedWrap($ti, $one), $tiSlot);
        $context->builder->branch($fillHead);

        $context->builder->positionAtEnd($fillDone);
        $skippedSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($zero, $skippedSlot);

        $skipHead = $fn->appendBasicBlock('st_skip_head');
        $skipBody = $fn->appendBasicBlock('st_skip_body');
        $skipDone = $fn->appendBasicBlock('st_skip_done');
        $context->builder->branch($skipHead);

        $context->builder->positionAtEnd($skipHead);
        $p = $context->builder->load($pSlot);
        $peVal = $context->builder->load($peSlot);
        $skipPast = $context->builder->icmp(
            Builder::INT_UGE,
            $context->builder->ptrToInt($p, $i64),
            $context->builder->ptrToInt($peVal, $i64)
        );
        $context->builder->branchIf($skipPast, $skipDone, $skipBody);

        $context->builder->positionAtEnd($skipBody);
        $p = $context->builder->load($pSlot);
        $ch = $context->builder->load($p);
        $idx = $context->builder->zExt($ch, $i64);
        $isDelim = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->load($context->builder->inBoundsGEP($tablePtr, $idx)),
            $i8->constInt(0, false)
        );
        $skipExit = $fn->appendBasicBlock('st_skip_exit');
        $skipNext = $fn->appendBasicBlock('st_skip_next');
        $context->builder->branchIf($isDelim, $skipNext, $skipExit);

        $context->builder->positionAtEnd($skipNext);
        $p = $context->builder->load($pSlot);
        $peVal = $context->builder->load($peSlot);
        $nextP = $context->builder->inBoundsGEP($p, $one);
        $context->builder->store($nextP, $pSlot);
        $skipped = $context->builder->load($skippedSlot);
        $context->builder->store($context->builder->addNoSignedWrap($skipped, $one), $skippedSlot);
        $pastEnd = $context->builder->icmp(
            Builder::INT_UGE,
            $context->builder->ptrToInt($nextP, $i64),
            $context->builder->ptrToInt($peVal, $i64)
        );
        $skipFail = $fn->appendBasicBlock('st_skip_fail');
        $context->builder->branchIf($pastEnd, $skipFail, $skipHead);

        $context->builder->positionAtEnd($skipFail);
        $context->builder->call($context->lookupFunction('__phpc_strtok_reset'));
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($skipExit);
        $context->builder->branch($skipDone);

        $context->builder->positionAtEnd($skipDone);
        $scanHead = $fn->appendBasicBlock('st_scan_head');
        $scanBody = $fn->appendBasicBlock('st_scan_body');
        $scanDone = $fn->appendBasicBlock('st_scan_done');
        $context->builder->branch($scanHead);

        $context->builder->positionAtEnd($scanHead);
        $p = $context->builder->load($pSlot);
        $peVal = $context->builder->load($peSlot);
        $scanPast = $context->builder->icmp(
            Builder::INT_UGE,
            $context->builder->ptrToInt($p, $i64),
            $context->builder->ptrToInt($peVal, $i64)
        );
        $context->builder->branchIf($scanPast, $scanDone, $scanBody);

        $context->builder->positionAtEnd($scanBody);
        $p = $context->builder->load($pSlot);
        $nextP = $context->builder->inBoundsGEP($p, $one);
        $context->builder->store($nextP, $pSlot);
        $ch = $context->builder->load($nextP);
        $idx = $context->builder->zExt($ch, $i64);
        $isDelim = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->load($context->builder->inBoundsGEP($tablePtr, $idx)),
            $i8->constInt(0, false)
        );
        $found = $fn->appendBasicBlock('st_found');
        $context->builder->branchIf($isDelim, $found, $scanHead);

        $context->builder->positionAtEnd($found);
        $p = $context->builder->load($pSlot);
        $last = $context->builder->load(self::$lastGlobal);
        $skipped = $context->builder->load($skippedSlot);
        $start = $context->builder->inBoundsGEP($last, $skipped);
        $tokenLen = $context->builder->sub(
            $context->builder->ptrToInt($p, $i64),
            $context->builder->ptrToInt($last, $i64)
        );
        $tokenLen = $context->builder->sub($tokenLen, $skipped);
        $context->builder->store($context->builder->inBoundsGEP($p, $one), self::$lastGlobal);
        $ret = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $tokenLen,
            $start
        );
        $context->builder->returnValue($ret);

        $context->builder->positionAtEnd($scanDone);
        $p = $context->builder->load($pSlot);
        $last = $context->builder->load(self::$lastGlobal);
        $pastLast = $context->builder->icmp(
            Builder::INT_UGT,
            $context->builder->ptrToInt($p, $i64),
            $context->builder->ptrToInt($last, $i64)
        );
        $tail = $fn->appendBasicBlock('st_tail');
        $fail = $fn->appendBasicBlock('st_fail');
        $context->builder->branchIf($pastLast, $tail, $fail);

        $context->builder->positionAtEnd($tail);
        $skipped = $context->builder->load($skippedSlot);
        $start = $context->builder->inBoundsGEP($last, $skipped);
        $tokenLen = $context->builder->sub(
            $context->builder->ptrToInt($p, $i64),
            $context->builder->ptrToInt($last, $i64)
        );
        $tokenLen = $context->builder->sub($tokenLen, $skipped);
        $context->builder->call($context->lookupFunction('__phpc_strtok_reset'));
        $ret = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $tokenLen,
            $start
        );
        $context->builder->returnValue($ret);

        $context->builder->positionAtEnd($fail);
        $context->builder->call($context->lookupFunction('__phpc_strtok_reset'));
        $context->builder->returnValue($nullStr);
    }
}
