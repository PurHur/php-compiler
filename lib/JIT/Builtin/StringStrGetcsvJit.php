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
 * LLVM __compiler_str_getcsv (mirrors VmCsv::parseLine / former phpc_str_getcsv.c, #5288).
 *
 * php-src: ext/standard/file.c — PHP_FUNCTION(str_getcsv)
 */
final class StringStrGetcsvJit
{
    public static function implement(Context $context): void
    {
        $restore = self::captureInsertBlock($context);
        self::ensureLibc($context);
        self::ensureHashtableHelpers($context);

        $probe = $context->module->getNamedFunction('__compiler_str_getcsv');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_str_getcsv', $probe);
            self::restoreInsertBlock($context, $restore);

            return;
        }

        self::implementIfMissing($context, '__phpc_csv_first_char', self::emitFirstChar(...));
        self::implementIfMissing($context, '__phpc_csv_append_byte', self::emitAppendByte(...));
        self::implementIfMissing($context, '__phpc_csv_flush_field', self::emitFlushField(...));
        self::implementIfMissing($context, '__phpc_csv_parse_line', self::emitParseLine(...));
        self::implementIfMissing($context, '__compiler_str_getcsv', self::emitCompilerStrGetcsv(...));

        self::restoreInsertBlock($context, $restore);
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
            $fn = self::declareFunction($context, $name);
            $context->registerFunction($name, $fn);
        }

        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $void = $context->getTypeFromString('void');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $context->getTypeFromString('int8**');
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');

        return match ($name) {
            '__phpc_csv_first_char' => $context->module->addFunction(
                $name,
                $context->context->functionType($i8, false, $strPtr, $i8)
            ),
            '__phpc_csv_append_byte' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $i8pp, $sizeT, $sizeT, $i8)
            ),
            '__phpc_csv_flush_field' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $htPtr, $sizeT, $i8pp, $sizeT, $sizeT)
            ),
            '__phpc_csv_parse_line' => $context->module->addFunction(
                $name,
                $context->context->functionType($htPtr, false, $i8p, $sizeT, $i8, $i8, $i8)
            ),
            '__compiler_str_getcsv' => $context->module->addFunction(
                $name,
                $context->context->functionType($htPtr, false, $strPtr, $strPtr, $strPtr, $strPtr)
            ),
            default => throw new \LogicException('Unknown str_getcsv JIT helper: '.$name),
        };
    }

    private static function ensureLibc(Context $context): void
    {
        $voidPtr = $context->getTypeFromString('void*');
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');

        foreach ([
            ['malloc', $voidPtr, [$sizeT]],
            ['free', $voidTy, [$i8p]],
            ['realloc', $voidPtr, [$voidPtr, $sizeT]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureHashtableHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $void = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');

        foreach (
            [
                ['__hashtable__alloc', $htPtr, []],
                ['__hashtable__setStringAt', $void, [$htPtr, $sizeT, $strPtr]],
                ['__hashtable__setNullAt', $void, [$htPtr, $sizeT]],
                ['__string__init', $strPtr, [$i64, $i8p]],
            ] as [$name, $ret, $params]
        ) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureExternal(Context $context, string $name, $fnType): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $fnType);
            $context->registerFunction($name, $fn);
        }
    }

    private static function emitFirstChar(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);

        $str = $fn->getParam(0);
        $fallback = $fn->getParam(1);

        $nullBb = $fn->appendBasicBlock('fc_null');
        $checkBb = $fn->appendBasicBlock('fc_check');
        $useBb = $fn->appendBasicBlock('fc_use');
        $doneBb = $fn->appendBasicBlock('fc_done');

        $isNull = $context->builder->icmp(Builder::INT_EQ, $str, $strPtr->constNull());
        $context->builder->branchIf($isNull, $nullBb, $checkBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($checkBb);
        $map = $context->structFieldMap['__string__'];
        $len = $context->builder->load($context->builder->structGep($str, $map['length']));
        $empty = $context->builder->icmp(Builder::INT_EQ, $len, $zero);
        $context->builder->branchIf($empty, $doneBb, $useBb);

        $context->builder->positionAtEnd($useBb);
        $first = $context->builder->load(
            $context->builder->pointerCast(
                $context->builder->structGep($str, $map['value']),
                $context->getTypeFromString('int8*')
            )
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnValue($context->builder->phi($i8, [
            [$fallback, $nullBb],
            [$fallback, $checkBb],
            [$first, $useBb],
        ]));
    }

    private static function emitAppendByte(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $sizeT = $context->getTypeFromString('size_t');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $voidPtr = $context->getTypeFromString('void*');
        $thirtyTwo = $sizeT->constInt(32, false);
        $one = $sizeT->constInt(1, false);
        $two = $sizeT->constInt(2, false);

        $fieldSlot = $fn->getParam(0);
        $lenSlot = $fn->getParam(1);
        $capSlot = $fn->getParam(2);
        $byte = $fn->getParam(3);

        $fieldLen = $context->builder->load($lenSlot);
        $fieldCap = $context->builder->load($capSlot);
        $needGrow = $context->builder->icmp(
            Builder::INT_UGE,
            $context->builder->add($fieldLen, $one),
            $fieldCap
        );
        $growBb = $fn->appendBasicBlock('csv_grow');
        $appendBb = $fn->appendBasicBlock('csv_append');
        $context->builder->branchIf($needGrow, $growBb, $appendBb);

        $context->builder->positionAtEnd($growBb);
        $fieldCap = $context->builder->load($capSlot);
        $newCap = $context->builder->select(
            $context->builder->icmp(Builder::INT_ULT, $fieldCap, $thirtyTwo),
            $thirtyTwo,
            $context->builder->mul($fieldCap, $two)
        );
        $field = $context->builder->load($fieldSlot);
        $grown = $context->builder->call(
            $context->lookupFunction('realloc'),
            $context->bytePtr($field),
            $newCap
        );
        $context->builder->store($context->builder->pointerCast($grown, $i8p), $fieldSlot);
        $context->builder->store($newCap, $capSlot);
        $context->builder->branch($appendBb);

        $context->builder->positionAtEnd($appendBb);
        $field = $context->builder->load($fieldSlot);
        $fieldLen = $context->builder->load($lenSlot);
        $context->builder->store($byte, $context->builder->gep($field, $fieldLen));
        $context->builder->store($context->builder->add($fieldLen, $one), $lenSlot);
        $context->builder->returnVoid();
    }

    private static function emitFlushField(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $strPtr = $context->getTypeFromString('__string__*');
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);

        $ht = $fn->getParam(0);
        $idxSlot = $fn->getParam(1);
        $fieldSlot = $fn->getParam(2);
        $lenSlot = $fn->getParam(3);
        $capSlot = $fn->getParam(4);

        $field = $context->builder->load($fieldSlot);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $field, $i8p->constNull());
        $emptyBb = $fn->appendBasicBlock('csv_flush_empty');
        $copyBb = $fn->appendBasicBlock('csv_flush_copy');
        $doneBb = $fn->appendBasicBlock('csv_flush_done');
        $context->builder->branchIf($isNull, $emptyBb, $copyBb);

        $context->builder->positionAtEnd($emptyBb);
        $emptyStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(0, false),
            $context->builder->pointerCast($context->constantFromString(''), $i8p)
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($copyBb);
        $field = $context->builder->load($fieldSlot);
        $fieldLen = $context->builder->load($lenSlot);
        $copiedStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($fieldLen, $i64),
            $field
        );
        $context->builder->call($context->lookupFunction('free'), $field);
        $context->builder->store($i8p->constNull(), $fieldSlot);
        $context->builder->store($zero, $capSlot);
        $context->builder->store($zero, $lenSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $strPhi = $context->builder->phi($strPtr, [
            [$emptyStr, $emptyBb],
            [$copiedStr, $copyBb],
        ]);
        $idx = $context->builder->load($idxSlot);
        $context->builder->call($context->lookupFunction('__hashtable__setStringAt'), $ht, $idx, $strPhi);
        $context->builder->store($context->builder->add($idx, $one), $idxSlot);
        $context->builder->returnVoid();
    }

    private static function emitParseLine(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $sizeT = $context->getTypeFromString('size_t');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $context->getTypeFromString('int8**');
        $i1 = $context->getTypeFromString('int1');
        $zero = $sizeT->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $two = $sizeT->constInt(2, false);
        $nullChar = $i8->constInt(0, false);

        $line = $fn->getParam(0);
        $lineLen = $fn->getParam(1);
        $delim = $fn->getParam(2);
        $enclosure = $fn->getParam(3);
        $escape = $fn->getParam(4);

        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));

        $emptyLineBb = $fn->appendBasicBlock('csv_empty_line');
        $checkEolOnlyBb = $fn->appendBasicBlock('csv_check_eol_only');
        $bodyBb = $fn->appendBasicBlock('csv_body');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $lineLen, $zero),
            $emptyLineBb,
            $checkEolOnlyBb
        );

        $context->builder->positionAtEnd($checkEolOnlyBb);
        $scanSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($zero, $scanSlot);
        $scanHead = $fn->appendBasicBlock('csv_eol_scan_head');
        $scanBody = $fn->appendBasicBlock('csv_eol_scan_body');
        $scanAllEol = $fn->appendBasicBlock('csv_eol_scan_all');
        $scanNotEol = $fn->appendBasicBlock('csv_eol_scan_not');
        $context->builder->branch($scanHead);

        $lf = $i8->constInt(ord("\n"), false);
        $cr = $i8->constInt(ord("\r"), false);

        $context->builder->positionAtEnd($scanHead);
        $scanI = $context->builder->load($scanSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_ULT, $scanI, $lineLen),
            $scanBody,
            $scanAllEol
        );

        $context->builder->positionAtEnd($scanBody);
        $scanI = $context->builder->load($scanSlot);
        $scanC = $context->builder->load($context->builder->gep($line, $scanI));
        $isEol = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $scanC, $lf),
            $context->builder->icmp(Builder::INT_EQ, $scanC, $cr)
        );
        $scanAdvance = $fn->appendBasicBlock('csv_eol_scan_advance');
        $context->builder->branchIf($isEol, $scanAdvance, $scanNotEol);

        $context->builder->positionAtEnd($scanAdvance);
        $context->builder->store($context->builder->add($context->builder->load($scanSlot), $one), $scanSlot);
        $context->builder->branch($scanHead);

        $context->builder->positionAtEnd($scanAllEol);
        $context->builder->branch($emptyLineBb);

        $context->builder->positionAtEnd($scanNotEol);
        $context->builder->branch($bodyBb);

        $context->builder->positionAtEnd($emptyLineBb);
        $context->builder->call($context->lookupFunction('__hashtable__setNullAt'), $ht, $zero);
        $context->builder->returnValue($ht);

        $context->builder->positionAtEnd($bodyBb);

        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $fieldSlot = BasicBlockHelper::entryAlloca($context, $i8pp);
        $fieldLenSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $fieldCapSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $inQuotesSlot = BasicBlockHelper::entryAlloca($context, $i1);
        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);

        $context->builder->store($zero, $iSlot);
        $context->builder->store($i8p->constNull(), $fieldSlot);
        $context->builder->store($zero, $fieldLenSlot);
        $context->builder->store($zero, $fieldCapSlot);
        $context->builder->store($i1->constInt(0, false), $inQuotesSlot);
        $context->builder->store($zero, $idxSlot);

        $loopHead = $fn->appendBasicBlock('csv_loop_head');
        $loadChar = $fn->appendBasicBlock('csv_load_char');
        $inQuotesBb = $fn->appendBasicBlock('csv_in_quotes');
        $notQuotesBb = $fn->appendBasicBlock('csv_not_quotes');
        $doneBb = $fn->appendBasicBlock('csv_done');

        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_ULE, $i, $lineLen),
            $loadChar,
            $doneBb
        );

        $context->builder->positionAtEnd($loadChar);
        $i = $context->builder->load($iSlot);
        $c = $context->builder->select(
            $context->builder->icmp(Builder::INT_ULT, $i, $lineLen),
            $context->builder->load($context->builder->gep($line, $i)),
            $nullChar
        );
        $context->builder->branchIf(
            $context->builder->load($inQuotesSlot),
            $inQuotesBb,
            $notQuotesBb
        );

        // --- in quotes ---
        $context->builder->positionAtEnd($inQuotesBb);
        $iqBreak = $fn->appendBasicBlock('csv_iq_break');
        $iqCheckEsc = $fn->appendBasicBlock('csv_iq_check_esc');
        $context->builder->branchIf($context->builder->icmp(Builder::INT_EQ, $c, $nullChar), $iqBreak, $iqCheckEsc);

        $context->builder->positionAtEnd($iqCheckEsc);
        $i = $context->builder->load($iSlot);
        $doEsc = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $c, $escape),
            $context->builder->icmp(Builder::INT_ULT, $context->builder->add($i, $one), $lineLen)
        );
        $iqEsc = $fn->appendBasicBlock('csv_iq_esc');
        $iqCheckEnc = $fn->appendBasicBlock('csv_iq_check_enc');
        $context->builder->branchIf($doEsc, $iqEsc, $iqCheckEnc);

        $context->builder->positionAtEnd($iqEsc);
        $i = $context->builder->load($iSlot);
        $nextI = $context->builder->add($i, $one);
        $appendEsc = $context->lookupFunction('__phpc_csv_append_byte');
        $context->builder->call($appendEsc, $fieldSlot, $fieldLenSlot, $fieldCapSlot, $escape);
        $context->builder->call(
            $appendEsc,
            $fieldSlot,
            $fieldLenSlot,
            $fieldCapSlot,
            $context->builder->load($context->builder->gep($line, $nextI))
        );
        $context->builder->store($context->builder->add($nextI, $one), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($iqCheckEnc);
        $iqEnc = $fn->appendBasicBlock('csv_iq_enc');
        $iqAppend = $fn->appendBasicBlock('csv_iq_append');
        $context->builder->branchIf($context->builder->icmp(Builder::INT_EQ, $c, $enclosure), $iqEnc, $iqAppend);

        $context->builder->positionAtEnd($iqEnc);
        $i = $context->builder->load($iSlot);
        $nextI = $context->builder->add($i, $one);
        $isDouble = $context->builder->and(
            $context->builder->icmp(Builder::INT_ULT, $nextI, $lineLen),
            $context->builder->icmp(
                Builder::INT_EQ,
                $context->builder->load($context->builder->gep($line, $nextI)),
                $enclosure
            )
        );
        $iqDouble = $fn->appendBasicBlock('csv_iq_double');
        $iqClose = $fn->appendBasicBlock('csv_iq_close');
        $context->builder->branchIf($isDouble, $iqDouble, $iqClose);

        $context->builder->positionAtEnd($iqDouble);
        $context->builder->call(
            $context->lookupFunction('__phpc_csv_append_byte'),
            $fieldSlot,
            $fieldLenSlot,
            $fieldCapSlot,
            $enclosure
        );
        $context->builder->store($context->builder->add($context->builder->load($iSlot), $two), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($iqClose);
        $context->builder->store($i1->constInt(0, false), $inQuotesSlot);
        $context->builder->store($context->builder->add($context->builder->load($iSlot), $one), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($iqAppend);
        $context->builder->call(
            $context->lookupFunction('__phpc_csv_append_byte'),
            $fieldSlot,
            $fieldLenSlot,
            $fieldCapSlot,
            $c
        );
        $context->builder->store($context->builder->add($context->builder->load($iSlot), $one), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($iqBreak);
        $context->builder->branch($doneBb);

        // --- not in quotes ---
        $context->builder->positionAtEnd($notQuotesBb);
        $isDelim = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $c, $nullChar),
            $context->builder->icmp(Builder::INT_EQ, $c, $delim)
        );
        $nqFlush = $fn->appendBasicBlock('csv_nq_flush');
        $nqCheckEnc = $fn->appendBasicBlock('csv_nq_check_enc');
        $context->builder->branchIf($isDelim, $nqFlush, $nqCheckEnc);

        $context->builder->positionAtEnd($nqFlush);
        $context->builder->call(
            $context->lookupFunction('__phpc_csv_flush_field'),
            $ht,
            $idxSlot,
            $fieldSlot,
            $fieldLenSlot,
            $fieldCapSlot
        );
        $nqEnd = $fn->appendBasicBlock('csv_nq_end');
        $nqAdvance = $fn->appendBasicBlock('csv_nq_advance');
        $context->builder->branchIf($context->builder->icmp(Builder::INT_EQ, $c, $nullChar), $nqEnd, $nqAdvance);
        $context->builder->positionAtEnd($nqAdvance);
        $context->builder->store($context->builder->add($context->builder->load($iSlot), $one), $iSlot);
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($nqEnd);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($nqCheckEnc);
        $nqOpen = $fn->appendBasicBlock('csv_nq_open');
        $nqAppend = $fn->appendBasicBlock('csv_nq_append');
        $context->builder->branchIf($context->builder->icmp(Builder::INT_EQ, $c, $enclosure), $nqOpen, $nqAppend);

        $context->builder->positionAtEnd($nqOpen);
        $context->builder->store($i1->constInt(1, false), $inQuotesSlot);
        $context->builder->store($context->builder->add($context->builder->load($iSlot), $one), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($nqAppend);
        $context->builder->call(
            $context->lookupFunction('__phpc_csv_append_byte'),
            $fieldSlot,
            $fieldLenSlot,
            $fieldCapSlot,
            $c
        );
        $context->builder->store($context->builder->add($context->builder->load($iSlot), $one), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($doneBb);
        $field = $context->builder->load($fieldSlot);
        $freeBb = $fn->appendBasicBlock('csv_free_tail');
        $retBb = $fn->appendBasicBlock('csv_ret');
        $context->builder->branchIf($context->builder->icmp(Builder::INT_EQ, $field, $i8p->constNull()), $retBb, $freeBb);
        $context->builder->positionAtEnd($freeBb);
        $context->builder->call($context->lookupFunction('free'), $field);
        $context->builder->branch($retBb);
        $context->builder->positionAtEnd($retBb);
        $context->builder->returnValue($ht);
    }

    private static function emitCompilerStrGetcsv(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        $input = $fn->getParam(0);
        $separator = $fn->getParam(1);
        $enclosure = $fn->getParam(2);
        $escape = $fn->getParam(3);

        $nullBb = $fn->appendBasicBlock('str_getcsv_null');
        $bodyBb = $fn->appendBasicBlock('str_getcsv_body');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $input, $strPtr->constNull()),
            $nullBb,
            $bodyBb
        );

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnValue($htPtr->constNull());

        $context->builder->positionAtEnd($bodyBb);
        $map = $context->structFieldMap['__string__'];
        $lineData = $context->builder->structGep($input, $map['value']);
        $lineLen = $context->builder->truncOrBitCast(
            $context->builder->load($context->builder->structGep($input, $map['length'])),
            $sizeT
        );

        $delim = $context->builder->call(
            $context->lookupFunction('__phpc_csv_first_char'),
            $separator,
            $i8->constInt(ord(','), false)
        );
        $enc = $context->builder->call(
            $context->lookupFunction('__phpc_csv_first_char'),
            $enclosure,
            $i8->constInt(ord('"'), false)
        );
        $esc = $context->builder->call(
            $context->lookupFunction('__phpc_csv_first_char'),
            $escape,
            $i8->constInt(ord('\\'), false)
        );

        $ht = $context->builder->call(
            $context->lookupFunction('__phpc_csv_parse_line'),
            $context->builder->pointerCast($lineData, $i8p),
            $lineLen,
            $delim,
            $enc,
            $esc
        );
        $context->builder->returnValue($ht);
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
}
