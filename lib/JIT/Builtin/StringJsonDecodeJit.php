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
 * LLVM __compiler_json_decode / __compiler_json_validate (mirrors phpc_json_decode.c, #6202).
 */
final class StringJsonDecodeJit
{
    private const MAX_DEPTH = 32;

    private const MAX_LEN = 8 * 1024 * 1024;

    private const KEY_CAP = 256;

    private const VAL_CAP = 4096;

    private const ERROR_NONE = 0;

    private const ERROR_DEPTH = 1;

    private const ERROR_SYNTAX = 4;

    private const GLOBAL_LAST_ERROR = 'phpc_json_last_error';

    /** @var Value|null */
    private static $lastErrorGlobal = null;

    /** Standalone AOT: JSON POST helper for superglobals_refresh.c (#7389). */
    public static function ensureStandaloneBodies(Context $context): void
    {
        self::implement($context);
    }

    public static function implement(Context $context): void
    {
        $restore = self::captureInsertBlock($context);
        self::ensureGlobals($context);
        self::ensureLibc($context);
        self::ensureRuntimeHelpers($context);

        $probe = $context->module->getNamedFunction('__compiler_json_decode');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_json_decode', $probe);
        }

        foreach (
            [
                '__phpc_json_cstr_to_string',
                '__phpc_json_skip_ws',
                '__phpc_json_expect',
                '__phpc_json_parse_string',
                '__phpc_json_has_fraction',
                '__phpc_json_parse_number',
                '__phpc_json_parse_literal',
                '__phpc_json_ensure_child',
                '__phpc_json_store_string',
                '__phpc_json_store_long',
                '__phpc_json_store_bool',
                '__phpc_json_parse_object',
                '__phpc_json_parse_array',
                '__phpc_json_parse_value',
                '__phpc_json_parse_top',
                '__phpc_json_parse_post_body',
                '__compiler_json_decode',
                '__compiler_json_validate',
                '__compiler_json_last_error',
                '__compiler_json_last_error_msg',
            ] as $name
        ) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = self::declareFunction($context, $name);
                $context->registerFunction($name, $fn);
            }
        }

        self::implementIfMissing($context, '__phpc_json_cstr_to_string', self::emitCstrToString(...));
        self::implementIfMissing($context, '__phpc_json_skip_ws', self::emitSkipWs(...));
        self::implementIfMissing($context, '__phpc_json_expect', self::emitExpect(...));
        self::implementIfMissing($context, '__phpc_json_parse_string', self::emitParseString(...));
        self::implementIfMissing($context, '__phpc_json_has_fraction', self::emitHasFraction(...));
        self::implementIfMissing($context, '__phpc_json_parse_number', self::emitParseNumber(...));
        self::implementIfMissing($context, '__phpc_json_parse_literal', self::emitParseLiteral(...));
        self::implementIfMissing($context, '__phpc_json_ensure_child', self::emitEnsureChild(...));
        self::implementIfMissing($context, '__phpc_json_store_string', self::emitStoreString(...));
        self::implementIfMissing($context, '__phpc_json_store_long', self::emitStoreLong(...));
        self::implementIfMissing($context, '__phpc_json_store_bool', self::emitStoreBool(...));
        self::implementIfMissing($context, '__phpc_json_parse_object', self::emitParseObject(...));
        self::implementIfMissing($context, '__phpc_json_parse_array', self::emitParseArray(...));
        self::implementIfMissing($context, '__phpc_json_parse_value', self::emitParseValue(...));
        self::implementIfMissing($context, '__phpc_json_parse_top', self::emitParseTop(...));
        self::implementIfMissing($context, '__phpc_json_parse_post_body', self::emitParsePostBody(...));
        self::implementIfMissing($context, '__compiler_json_decode', self::emitCompilerJsonDecode(...));
        self::implementIfMissing($context, '__compiler_json_validate', self::emitCompilerJsonValidate(...));
        self::implementIfMissing($context, '__compiler_json_last_error', self::emitCompilerJsonLastError(...));
        self::implementIfMissing($context, '__compiler_json_last_error_msg', self::emitCompilerJsonLastErrorMsg(...));

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
        $valuePtr = $context->getTypeFromString('__value__*');
        $void = $context->getTypeFromString('void');
        $dbl = $context->getTypeFromString('double');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $i8p->pointerType(0);
        $i32p = $i32->pointerType(0);
        $sizeT = $context->getTypeFromString('size_t');

        return match ($name) {
            '__phpc_json_cstr_to_string' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $i8p)
            ),
            '__phpc_json_skip_ws' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $i8pp, $i8p)
            ),
            '__phpc_json_expect' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8pp, $i8p, $i8)
            ),
            '__phpc_json_parse_string' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8pp, $i8p, $i8p, $sizeT)
            ),
            '__phpc_json_has_fraction' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8p)
            ),
            '__phpc_json_parse_number' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8pp, $i8p, $i8p, $sizeT)
            ),
            '__phpc_json_parse_literal' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8pp, $i8p, $i8p, $i8p, $sizeT)
            ),
            '__phpc_json_ensure_child' => $context->module->addFunction(
                $name,
                $context->context->functionType($htPtr, false, $htPtr, $i8p)
            ),
            '__phpc_json_store_string' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $htPtr, $i8p, $i32, $sizeT, $i8p)
            ),
            '__phpc_json_store_long' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $htPtr, $i8p, $i32, $sizeT, $i64)
            ),
            '__phpc_json_store_bool' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $htPtr, $i8p, $i32, $sizeT, $i32)
            ),
            '__phpc_json_parse_object' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8pp, $i8p, $i32p, $i32, $htPtr)
            ),
            '__phpc_json_parse_array' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8pp, $i8p, $i32p, $i32, $htPtr, $i8p)
            ),
            '__phpc_json_parse_value' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8pp, $i8p, $i32p, $i32, $htPtr, $i8p, $i32, $sizeT)
            ),
            '__phpc_json_parse_top' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8pp, $i8p, $i32p, $i32, $valuePtr)
            ),
            '__phpc_json_parse_post_body' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $htPtr, $i8p)
            ),
            '__compiler_json_decode' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $strPtr, $valuePtr)
            ),
            '__compiler_json_validate' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $strPtr, $i64)
            ),
            '__compiler_json_last_error' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false)
            ),
            '__compiler_json_last_error_msg' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false)
            ),
            default => throw new \LogicException('Unknown json_decode JIT helper: '.$name),
        };
    }

    private static function ensureGlobals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        if (null === $context->module->getNamedGlobal(self::GLOBAL_LAST_ERROR)) {
            self::$lastErrorGlobal = $context->module->addGlobal($i32, self::GLOBAL_LAST_ERROR);
            self::$lastErrorGlobal->setInitializer($i32->constInt(self::ERROR_NONE, false));
        } else {
            self::$lastErrorGlobal = $context->module->getNamedGlobal(self::GLOBAL_LAST_ERROR);
        }
    }

    private static function ensureLibc(Context $context): void
    {
        $voidPtr = $context->getTypeFromString('void*');
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $dbl = $context->getTypeFromString('double');
        $i8pp = $i8p->pointerType(0);

        foreach (
            [
                ['memcpy', $voidPtr, [$voidPtr, $voidPtr, $sizeT]],
                ['strlen', $sizeT, [$i8p]],
                ['memset', $voidPtr, [$voidPtr, $i32, $sizeT]],
                ['strchr', $i8p, [$i8p, $i32]],
                ['strncmp', $i32, [$i8p, $i8p, $sizeT]],
                ['strncpy', $i8p, [$i8p, $i8p, $sizeT]],
                ['strtod', $dbl, [$i8p, $i8pp]],
                ['strtoll', $i64, [$i8p, $i8pp, $i32]],
            ] as [$name, $ret, $params]
        ) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $void = $context->getTypeFromString('void');
        $dbl = $context->getTypeFromString('double');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        foreach (
            [
                ['__hashtable__alloc', $htPtr, []],
                ['__hashtable__setStringKeyString', $void, [$htPtr, $strPtr, $strPtr]],
                ['__hashtable__setStringKeyLong', $void, [$htPtr, $strPtr, $i64]],
                ['__hashtable__setStringKeyBool', $void, [$htPtr, $strPtr, $context->getTypeFromString('int1')]],
                ['__hashtable__setStringKeyHashtable', $void, [$htPtr, $strPtr, $htPtr]],
                ['__hashtable__setStringAt', $void, [$htPtr, $sizeT, $strPtr]],
                ['__hashtable__setLongAt', $void, [$htPtr, $sizeT, $i64]],
                ['__hashtable__setBoolAt', $void, [$htPtr, $sizeT, $context->getTypeFromString('int1')]],
                ['__hashtable__setDoubleAt', $void, [$htPtr, $sizeT, $dbl]],
                ['__hashtable__readStringKeyHashtable', $htPtr, [$htPtr, $strPtr]],
                ['__value__writeNull', $void, [$valuePtr]],
                ['__value__writeLong', $void, [$valuePtr, $i64]],
                ['__value__writeDouble', $void, [$valuePtr, $dbl]],
                ['__value__writeString', $void, [$valuePtr, $strPtr]],
                ['__value__writeHashtable', $void, [$valuePtr, $htPtr]],
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

    private static function literalCstr(Context $context, string $text): Value
    {
        return $context->pointerFromStringConstant($text);
    }

    private static function emitCstrToString(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i64 = $context->getTypeFromString('int64');
        $cstr = $fn->getParam(0);
        $len = $context->builder->call($context->lookupFunction('strlen'), $cstr);
        $ret = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->zExt($len, $i64),
            $cstr
        );
        $context->builder->returnValue($ret);
    }

    private static function emitSkipWs(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $posPtr = $fn->getParam(0);
        $end = $fn->getParam(1);

        $head = $fn->appendBasicBlock('head');
        $body = $fn->appendBasicBlock('body');
        $done = $fn->appendBasicBlock('done');

        $context->builder->branch($head);
        $context->builder->positionAtEnd($head);
        $pos = $context->builder->load($posPtr);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $pos, $end);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($pos);
        $sp = $i8->constInt(ord(' '), false);
        $tab = $i8->constInt(ord("\t"), false);
        $nl = $i8->constInt(ord("\n"), false);
        $cr = $i8->constInt(ord("\r"), false);
        $isWs = $context->builder->or(
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $ch, $sp),
                $context->builder->icmp(Builder::INT_EQ, $ch, $tab)
            ),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $ch, $nl),
                $context->builder->icmp(Builder::INT_EQ, $ch, $cr)
            )
        );
        $cont = $fn->appendBasicBlock('cont');
        $context->builder->branchIf($isWs, $cont, $done);
        $context->builder->positionAtEnd($cont);
        $context->builder->store($context->builder->inBoundsGEP($pos, $one), $posPtr);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
    }

    private static function emitExpect(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);

        $posPtr = $fn->getParam(0);
        $end = $fn->getParam(1);
        $expected = $fn->getParam(2);

        $context->builder->call(
            $context->lookupFunction('__phpc_json_skip_ws'),
            $posPtr,
            $end
        );

        $fail = $fn->appendBasicBlock('fail');
        $ok = $fn->appendBasicBlock('ok');
        $done = $fn->appendBasicBlock('done');

        $pos = $context->builder->load($posPtr);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $pos, $end);
        $context->builder->branchIf($atEnd, $fail, $ok);

        $context->builder->positionAtEnd($ok);
        $ch = $context->builder->load($pos);
        $matches = $context->builder->icmp(Builder::INT_EQ, $ch, $expected);
        $context->builder->branchIf($matches, $done, $fail);

        $context->builder->positionAtEnd($done);
        $context->builder->store($context->builder->inBoundsGEP($pos, $one), $posPtr);
        $context->builder->returnValue($oneI32);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zeroI32);
    }

    private static function emitParseString(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $oneI32 = $i32->constInt(1, false);
        $zeroI32 = $i32->constInt(0, false);
        $one = $sizeT->constInt(1, false);
        $oneSize = $sizeT->constInt(1, false);
        $four = $i64->constInt(4, false);
        $quote = $i8->constInt(ord('"'), false);
        $backslash = $i8->constInt(ord('\\'), false);
        $uCh = $i8->constInt(ord('u'), false);

        $posPtr = $fn->getParam(0);
        $end = $fn->getParam(1);
        $outBuf = $fn->getParam(2);
        $outLen = $fn->getParam(3);

        $oSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $charSlot = BasicBlockHelper::entryAlloca($context, $i8);
        $context->builder->store($sizeT->constInt(0, false), $oSlot);

        $fail = $fn->appendBasicBlock('fail');
        $loopHead = $fn->appendBasicBlock('loop_head');
        $loopBody = $fn->appendBasicBlock('loop_body');
        $closeOk = $fn->appendBasicBlock('close_ok');
        $plain = $fn->appendBasicBlock('plain');
        $escRead = $fn->appendBasicBlock('esc_read');
        $skipU = $fn->appendBasicBlock('skip_u');
        $doAppend = $fn->appendBasicBlock('do_append');

        $okQuote = $context->builder->call(
            $context->lookupFunction('__phpc_json_expect'),
            $posPtr,
            $end,
            $quote
        );
        $context->builder->branchIf($context->i32Success($okQuote), $loopHead, $fail);

        $context->builder->positionAtEnd($loopHead);
        $pos = $context->builder->load($posPtr);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $pos, $end);
        $context->builder->branchIf($atEnd, $fail, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $pos = $context->builder->load($posPtr);
        $c = $context->builder->load($pos);
        $context->builder->store($context->builder->inBoundsGEP($pos, $one), $posPtr);
        $isClose = $context->builder->icmp(Builder::INT_EQ, $c, $quote);
        $isEsc = $context->builder->icmp(Builder::INT_EQ, $c, $backslash);
        $afterClose = $fn->appendBasicBlock('after_close');
        $context->builder->branchIf($isClose, $closeOk, $afterClose);
        $context->builder->positionAtEnd($afterClose);
        $context->builder->branchIf($isEsc, $escRead, $plain);

        $context->builder->positionAtEnd($closeOk);
        $o = $context->builder->load($oSlot);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($outBuf, $o));
        $context->builder->returnValue($oneI32);

        $context->builder->positionAtEnd($plain);
        $context->builder->store($c, $charSlot);
        $context->builder->branch($doAppend);

        $context->builder->positionAtEnd($escRead);
        $pos = $context->builder->load($posPtr);
        $atEnd2 = $context->builder->icmp(Builder::INT_UGE, $pos, $end);
        $bb_esc_load = $fn->appendBasicBlock('esc_load');

        $context->builder->branchIf($atEnd2, $fail, $bb_esc_load);
        $context->builder->positionAtEnd($escRead);
        $context->builder->positionAtEnd($bb_esc_load);
        $esc = $context->builder->load($pos);
        $context->builder->store($context->builder->inBoundsGEP($pos, $one), $posPtr);
        $isU = $context->builder->icmp(Builder::INT_EQ, $esc, $uCh);
        $bb_u_check = $fn->appendBasicBlock('u_check');

        $bb_esc_map_head = $fn->appendBasicBlock('esc_map_head');

        $context->builder->branchIf($isU, $bb_u_check, $bb_esc_map_head);
        $context->builder->positionAtEnd($escRead);
        $context->builder->positionAtEnd($bb_u_check);
        $pos = $context->builder->load($posPtr);
        $remain = $context->builder->sub(
            $context->builder->ptrToInt($end, $i64),
            $context->builder->ptrToInt($pos, $i64)
        );
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_ULT, $remain, $four),
            $fail,
            $skipU
        );
        $context->builder->positionAtEnd($skipU);
        $context->builder->store($context->builder->inBoundsGEP($pos, $four), $posPtr);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($bb_esc_map_head);
        $mapFail = $fn->appendBasicBlock('map_fail');
        $mapChecks = [
            ['"', ord('"')],
            ['\\', ord('\\')],
            ['/', ord('/')],
            ['b', ord("\b")],
            ['f', ord("\f")],
            ['n', ord("\n")],
            ['r', ord("\r")],
            ['t', ord("\t")],
        ];
        $nextCheck = $bb_esc_map_head;
        foreach ($mapChecks as $idx => [$lit, $code]) {
            $checkBb = $nextCheck;
            $mappedBb = $fn->appendBasicBlock('esc_map_'.$idx);
            $nextCheck = ($idx + 1 < \count($mapChecks))
                ? $fn->appendBasicBlock('esc_chk_'.$idx)
                : $mapFail;
            $context->builder->positionAtEnd($checkBb);
            $isLit = $context->builder->icmp(Builder::INT_EQ, $esc, $i8->constInt(ord($lit), false));
            $context->builder->branchIf($isLit, $mappedBb, $nextCheck);
            $context->builder->positionAtEnd($mappedBb);
            $context->builder->store($i8->constInt($code, false), $charSlot);
            $context->builder->branch($doAppend);
        }
        $context->builder->positionAtEnd($mapFail);
        $context->builder->branch($fail);

        $context->builder->positionAtEnd($doAppend);
        $o = $context->builder->load($oSlot);
        $need = $context->builder->add($o, $oneSize);
        $tooBig = $context->builder->icmp(Builder::INT_UGE, $need, $outLen);
        $bb_store_char = $fn->appendBasicBlock('store_char');

        $context->builder->branchIf($tooBig, $fail, $bb_store_char);
        $context->builder->positionAtEnd($doAppend);
        $context->builder->positionAtEnd($bb_store_char);
        $context->builder->store($context->builder->load($charSlot), $context->builder->inBoundsGEP($outBuf, $o));
        $context->builder->store($need, $oSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zeroI32);
    }

    private static function emitHasFraction(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $s = $fn->getParam(0);
        $null = $i8p->constNull();

        $hasDot = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('strchr'), $s, $i32->constInt(ord('.'), false)),
            $null
        );
        $hasE = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('strchr'), $s, $i32->constInt(ord('e'), false)),
            $null
        );
        $hasBigE = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('strchr'), $s, $i32->constInt(ord('E'), false)),
            $null
        );
        $any = $context->builder->or($hasDot, $context->builder->or($hasE, $hasBigE));
        $context->builder->returnValue($context->builder->select($any, $oneI32, $zeroI32));
    }

    private static function emitParseNumber(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $oneI32 = $i32->constInt(1, false);
        $zeroI32 = $i32->constInt(0, false);
        $oneSize = $sizeT->constInt(1, false);
        $digit0 = $i8->constInt(ord('0'), false);
        $digit9 = $i8->constInt(ord('9'), false);

        $posPtr = $fn->getParam(0);
        $end = $fn->getParam(1);
        $outBuf = $fn->getParam(2);
        $outLen = $fn->getParam(3);
        $startSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store($context->builder->load($posPtr), $startSlot);

        $fail = $fn->appendBasicBlock('fail');
        $copy = $fn->appendBasicBlock('copy');
        $afterStart = $fn->appendBasicBlock('after_start');
        $takeSign = $fn->appendBasicBlock('take_sign');
        $afterSign = $fn->appendBasicBlock('after_sign');
        $checkDigit = $fn->appendBasicBlock('check_digit');
        $intHead = $fn->appendBasicBlock('int_head');
        $intBody = $fn->appendBasicBlock('int_body');
        $intStep = $fn->appendBasicBlock('int_step');
        $afterInt = $fn->appendBasicBlock('after_int');
        $maybeDot = $fn->appendBasicBlock('maybe_dot');
        $takeDot = $fn->appendBasicBlock('take_dot');
        $fracHead = $fn->appendBasicBlock('frac_head');
        $fracBody = $fn->appendBasicBlock('frac_body');
        $fracStep = $fn->appendBasicBlock('frac_step');
        $afterFrac = $fn->appendBasicBlock('after_frac');
        $maybeExp = $fn->appendBasicBlock('maybe_exp');
        $takeExp = $fn->appendBasicBlock('take_exp');
        $maybeExpSign = $fn->appendBasicBlock('maybe_exp_sign');
        $takeExpSign = $fn->appendBasicBlock('take_exp_sign');
        $expHead = $fn->appendBasicBlock('exp_head');
        $expBody = $fn->appendBasicBlock('exp_body');
        $expStep = $fn->appendBasicBlock('exp_step');
        $memcpyOk = $fn->appendBasicBlock('memcpy_ok');

        $pos = $context->builder->load($posPtr);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $pos, $end);
        $context->builder->branchIf($atEnd, $fail, $afterStart);

        $context->builder->positionAtEnd($afterStart);
        $pos = $context->builder->load($posPtr);
        $ch = $context->builder->load($pos);
        $isMinus = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('-'), false));
        $context->builder->branchIf($isMinus, $takeSign, $afterSign);
        $context->builder->positionAtEnd($takeSign);
        $context->builder->store($context->builder->inBoundsGEP($pos, $one), $posPtr);
        $context->builder->branch($afterSign);

        $context->builder->positionAtEnd($afterSign);
        $pos = $context->builder->load($posPtr);
        $atEnd2 = $context->builder->icmp(Builder::INT_UGE, $pos, $end);
        $context->builder->branchIf($atEnd2, $fail, $checkDigit);
        $context->builder->positionAtEnd($checkDigit);
        $ch = $context->builder->load($pos);
        $isDigit = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ch, $digit0),
            $context->builder->icmp(Builder::INT_SLE, $ch, $digit9)
        );
        $context->builder->branchIf($isDigit, $intHead, $fail);

        $context->builder->positionAtEnd($intHead);
        $pos = $context->builder->load($posPtr);
        $atEnd3 = $context->builder->icmp(Builder::INT_UGE, $pos, $end);
        $context->builder->branchIf($atEnd3, $afterInt, $intBody);
        $context->builder->positionAtEnd($intBody);
        $ch = $context->builder->load($pos);
        $isDigit = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ch, $digit0),
            $context->builder->icmp(Builder::INT_SLE, $ch, $digit9)
        );
        $context->builder->branchIf($isDigit, $intStep, $afterInt);
        $context->builder->positionAtEnd($intStep);
        $context->builder->store($context->builder->inBoundsGEP($context->builder->load($posPtr), $one), $posPtr);
        $context->builder->branch($intHead);

        $context->builder->positionAtEnd($afterInt);
        $pos = $context->builder->load($posPtr);
        $atEnd4 = $context->builder->icmp(Builder::INT_UGE, $pos, $end);
        $context->builder->branchIf($atEnd4, $afterFrac, $maybeDot);
        $context->builder->positionAtEnd($maybeDot);
        $ch = $context->builder->load($pos);
        $isDot = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('.'), false));
        $context->builder->branchIf($isDot, $takeDot, $afterFrac);
        $context->builder->positionAtEnd($takeDot);
        $context->builder->store($context->builder->inBoundsGEP($pos, $one), $posPtr);
        $context->builder->branch($fracHead);

        $context->builder->positionAtEnd($fracHead);
        $pos = $context->builder->load($posPtr);
        $atEnd5 = $context->builder->icmp(Builder::INT_UGE, $pos, $end);
        $context->builder->branchIf($atEnd5, $afterFrac, $fracBody);
        $context->builder->positionAtEnd($fracBody);
        $ch = $context->builder->load($pos);
        $isDigit = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ch, $digit0),
            $context->builder->icmp(Builder::INT_SLE, $ch, $digit9)
        );
        $context->builder->branchIf($isDigit, $fracStep, $afterFrac);
        $context->builder->positionAtEnd($fracStep);
        $context->builder->store($context->builder->inBoundsGEP($context->builder->load($posPtr), $one), $posPtr);
        $context->builder->branch($fracHead);

        $context->builder->positionAtEnd($afterFrac);
        $pos = $context->builder->load($posPtr);
        $atEnd6 = $context->builder->icmp(Builder::INT_UGE, $pos, $end);
        $context->builder->branchIf($atEnd6, $copy, $maybeExp);
        $context->builder->positionAtEnd($maybeExp);
        $ch = $context->builder->load($pos);
        $isExp = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('e'), false)),
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('E'), false))
        );
        $context->builder->branchIf($isExp, $takeExp, $copy);
        $context->builder->positionAtEnd($takeExp);
        $context->builder->store($context->builder->inBoundsGEP($pos, $one), $posPtr);
        $pos = $context->builder->load($posPtr);
        $atEnd7 = $context->builder->icmp(Builder::INT_UGE, $pos, $end);
        $context->builder->branchIf($atEnd7, $expHead, $maybeExpSign);
        $context->builder->positionAtEnd($maybeExpSign);
        $ch = $context->builder->load($pos);
        $isSign = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('+'), false)),
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('-'), false))
        );
        $context->builder->branchIf($isSign, $takeExpSign, $expHead);
        $context->builder->positionAtEnd($takeExpSign);
        $context->builder->store($context->builder->inBoundsGEP($pos, $one), $posPtr);
        $context->builder->branch($expHead);

        $context->builder->positionAtEnd($expHead);
        $pos = $context->builder->load($posPtr);
        $atEnd8 = $context->builder->icmp(Builder::INT_UGE, $pos, $end);
        $context->builder->branchIf($atEnd8, $copy, $expBody);
        $context->builder->positionAtEnd($expBody);
        $ch = $context->builder->load($pos);
        $isDigit = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ch, $digit0),
            $context->builder->icmp(Builder::INT_SLE, $ch, $digit9)
        );
        $context->builder->branchIf($isDigit, $expStep, $copy);
        $context->builder->positionAtEnd($expStep);
        $context->builder->store($context->builder->inBoundsGEP($context->builder->load($posPtr), $one), $posPtr);
        $context->builder->branch($expHead);

        $context->builder->positionAtEnd($copy);
        $i64 = $context->getTypeFromString('int64');
        $start = $context->builder->load($startSlot);
        $cur = $context->builder->load($posPtr);
        $len = $context->builder->sub(
            $context->builder->ptrToInt($cur, $i64),
            $context->builder->ptrToInt($start, $i64)
        );
        $lenSize = $context->builder->truncOrBitCast($len, $sizeT);
        $need = $context->builder->add($lenSize, $oneSize);
        $tooBig = $context->builder->icmp(Builder::INT_UGT, $need, $outLen);
        $context->builder->branchIf($tooBig, $fail, $memcpyOk);
        $context->builder->positionAtEnd($memcpyOk);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($outBuf),
            $context->bytePtr($start),
            $lenSize
        );
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($outBuf, $lenSize));
        $context->builder->returnValue($oneI32);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zeroI32);
    }

    private static function emitParseLiteral(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $oneI32 = $i32->constInt(1, false);
        $zeroI32 = $i32->constInt(0, false);
        $oneSize = $sizeT->constInt(1, false);

        $posPtr = $fn->getParam(0);
        $end = $fn->getParam(1);
        $lit = $fn->getParam(2);
        $outBuf = $fn->getParam(3);
        $outLen = $fn->getParam(4);

        $fail = $fn->appendBasicBlock('fail');
        $ok = $fn->appendBasicBlock('ok');
        $len = $context->builder->call($context->lookupFunction('strlen'), $lit);
        $pos = $context->builder->load($posPtr);
        $remain = $context->builder->sub(
            $context->builder->ptrToInt($end, $i64),
            $context->builder->ptrToInt($pos, $i64)
        );
        $notEnough = $context->builder->icmp(Builder::INT_ULT, $remain, $context->builder->zExt($len, $i64));
        $bb_cmp = $fn->appendBasicBlock('cmp');

        $context->builder->branchIf($notEnough, $fail, $bb_cmp);
        $context->builder->positionAtEnd($bb_cmp);
        $eq = $context->builder->call($context->lookupFunction('strncmp'), $pos, $lit, $len);
        $matches = $context->builder->icmp(Builder::INT_EQ, $eq, $zeroI32);
        $context->builder->branchIf($matches, $ok, $fail);
        $context->builder->positionAtEnd($ok);
        $context->builder->store($context->builder->inBoundsGEP($pos, $len), $posPtr);
        $copyLen = $context->builder->sub($outLen, $oneSize);
        $context->builder->call(
            $context->lookupFunction('strncpy'),
            $outBuf,
            $lit,
            $copyLen
        );
        $last = $context->builder->inBoundsGEP($outBuf, $copyLen);
        $context->builder->store($context->getTypeFromString('int8')->constInt(0, false), $last);
        $context->builder->returnValue($oneI32);
        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zeroI32);
    }

    private static function emitEnsureChild(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $htPtr = $context->getTypeFromString('__hashtable__*');
        $ht = $fn->getParam(0);
        $key = $fn->getParam(1);
        $found = $fn->appendBasicBlock('found');
        $create = $fn->appendBasicBlock('create');
        $keyStr = $context->builder->call($context->lookupFunction('__phpc_json_cstr_to_string'), $key);
        $child = $context->builder->call(
            $context->lookupFunction('__hashtable__readStringKeyHashtable'),
            $ht,
            $keyStr
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $child, $htPtr->constNull());
        $context->builder->branchIf($isNull, $create, $found);
        $context->builder->positionAtEnd($found);
        $context->builder->returnValue($child);
        $context->builder->positionAtEnd($create);
        $newHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyHashtable'),
            $ht,
            $keyStr,
            $newHt
        );
        $context->builder->returnValue($newHt);
    }

    private static function emitStoreString(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $oneI32 = $i32->constInt(1, false);
        $zeroI32 = $i32->constInt(0, false);
        $ht = $fn->getParam(0);
        $key = $fn->getParam(1);
        $useIndex = $fn->getParam(2);
        $index = $fn->getParam(3);
        $value = $fn->getParam(4);
        $valStr = $context->builder->call($context->lookupFunction('__phpc_json_cstr_to_string'), $value);
        $isIndex = $context->builder->icmp(Builder::INT_NE, $useIndex, $zeroI32);
        $idxBb = $fn->appendBasicBlock('idx');
        $keyBb = $fn->appendBasicBlock('key');
        $done = $fn->appendBasicBlock('done');
        $context->builder->branchIf($isIndex, $idxBb, $keyBb);
        $context->builder->positionAtEnd($idxBb);
        $context->builder->call($context->lookupFunction('__hashtable__setStringAt'), $ht, $index, $valStr);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($keyBb);
        $keyStr = $context->builder->call($context->lookupFunction('__phpc_json_cstr_to_string'), $key);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $keyStr,
            $valStr
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($oneI32);
    }

    private static function emitStoreLong(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $oneI32 = $i32->constInt(1, false);
        $zeroI32 = $i32->constInt(0, false);
        $ht = $fn->getParam(0);
        $key = $fn->getParam(1);
        $useIndex = $fn->getParam(2);
        $index = $fn->getParam(3);
        $value = $fn->getParam(4);
        $isIndex = $context->builder->icmp(Builder::INT_NE, $useIndex, $zeroI32);
        $idxBb = $fn->appendBasicBlock('idx');
        $keyBb = $fn->appendBasicBlock('key');
        $done = $fn->appendBasicBlock('done');
        $context->builder->branchIf($isIndex, $idxBb, $keyBb);
        $context->builder->positionAtEnd($idxBb);
        $context->builder->call($context->lookupFunction('__hashtable__setLongAt'), $ht, $index, $value);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($keyBb);
        $keyStr = $context->builder->call($context->lookupFunction('__phpc_json_cstr_to_string'), $key);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $keyStr,
            $value
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($oneI32);
    }

    private static function emitStoreBool(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $oneI32 = $i32->constInt(1, false);
        $zeroI32 = $i32->constInt(0, false);
        $ht = $fn->getParam(0);
        $key = $fn->getParam(1);
        $useIndex = $fn->getParam(2);
        $index = $fn->getParam(3);
        $value = $fn->getParam(4);
        $boolI1 = $context->builder->icmp(Builder::INT_NE, $value, $zeroI32);
        $isIndex = $context->builder->icmp(Builder::INT_NE, $useIndex, $zeroI32);
        $idxBb = $fn->appendBasicBlock('idx');
        $keyBb = $fn->appendBasicBlock('key');
        $done = $fn->appendBasicBlock('done');
        $context->builder->branchIf($isIndex, $idxBb, $keyBb);
        $context->builder->positionAtEnd($idxBb);
        $context->builder->call($context->lookupFunction('__hashtable__setBoolAt'), $ht, $index, $boolI1);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($keyBb);
        $keyStr = $context->builder->call($context->lookupFunction('__phpc_json_cstr_to_string'), $key);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyBool'),
            $ht,
            $keyStr,
            $boolI1
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($oneI32);
    }

    private static function emitParseObject(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $oneI32 = $i32->constInt(1, false);
        $zeroI32 = $i32->constInt(0, false);
        $zeroSize = $sizeT->constInt(0, false);

        $posPtr = $fn->getParam(0);
        $end = $fn->getParam(1);
        $depthPtr = $fn->getParam(2);
        $maxDepth = $fn->getParam(3);
        $ht = $fn->getParam(4);

        $keyBuf = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::KEY_CAP));
        $keyBufPtr = $context->builder->pointerCast($keyBuf, $i8p);
        $keyCap = $sizeT->constInt(self::KEY_CAP, false);

        $fail = $fn->appendBasicBlock('fail');
        $ok = $fn->appendBasicBlock('ok');
        $loop = $fn->appendBasicBlock('loop');
        $afterKey = $fn->appendBasicBlock('after_key');
        $comma = $fn->appendBasicBlock('comma');

        $okBrace = $context->builder->call(
            $context->lookupFunction('__phpc_json_expect'),
            $posPtr,
            $end,
            $i8->constInt(ord('{'), false)
        );
        $bb_after_open = $fn->appendBasicBlock('after_open');

        $context->builder->branchIf($context->i32Success($okBrace), $bb_after_open, $fail);
        $context->builder->positionAtEnd($bb_after_open);
        $context->builder->call($context->lookupFunction('__phpc_json_skip_ws'), $posPtr, $end);
        $empty = $context->builder->call(
            $context->lookupFunction('__phpc_json_expect'),
            $posPtr,
            $end,
            $i8->constInt(ord('}'), false)
        );
        $context->builder->branchIf($context->i32Success($empty), $ok, $loop);

        $context->builder->positionAtEnd($loop);
        $okKey = $context->builder->call(
            $context->lookupFunction('__phpc_json_parse_string'),
            $posPtr,
            $end,
            $keyBufPtr,
            $keyCap
        );
        $context->builder->branchIf($context->i32Success($okKey), $afterKey, $fail);
        $context->builder->positionAtEnd($afterKey);
        $emptyKey = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($keyBufPtr),
            $i8->constInt(0, false)
        );
        $bb_after_colon = $fn->appendBasicBlock('after_colon');

        $context->builder->branchIf($emptyKey, $fail, $bb_after_colon);
        $context->builder->positionAtEnd($bb_after_colon);
        $okColon = $context->builder->call(
            $context->lookupFunction('__phpc_json_expect'),
            $posPtr,
            $end,
            $i8->constInt(ord(':'), false)
        );
        $bb_parse_val = $fn->appendBasicBlock('parse_val');

        $context->builder->branchIf($context->i32Success($okColon), $bb_parse_val, $fail);
        $context->builder->positionAtEnd($bb_parse_val);
        $okVal = $context->builder->call(
            $context->lookupFunction('__phpc_json_parse_value'),
            $posPtr,
            $end,
            $depthPtr,
            $maxDepth,
            $ht,
            $keyBufPtr,
            $zeroI32,
            $zeroSize
        );
        $context->builder->branchIf($context->i32Success($okVal), $comma, $fail);
        $context->builder->positionAtEnd($comma);
        $context->builder->call($context->lookupFunction('__phpc_json_skip_ws'), $posPtr, $end);
        $pos = $context->builder->load($posPtr);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $pos, $end);
        $bb_close_chk = $fn->appendBasicBlock('close_chk');

        $context->builder->branchIf($atEnd, $fail, $bb_close_chk);
        $context->builder->positionAtEnd($bb_close_chk);
        $ch = $context->builder->load($pos);
        $isClose = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('}'), false));
        $isComma = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord(','), false));
        $closeBb = $fn->appendBasicBlock('close');
        $nextComma = $fn->appendBasicBlock('next_comma');
        $bb_comma_or_fail = $fn->appendBasicBlock('comma_or_fail');

        $context->builder->branchIf($isClose, $closeBb, $bb_comma_or_fail);
        $context->builder->positionAtEnd($bb_comma_or_fail);
        $context->builder->branchIf($isComma, $nextComma, $fail);
        $context->builder->positionAtEnd($closeBb);
        $context->builder->store(
            $context->builder->inBoundsGEP($pos, $sizeT->constInt(1, false)),
            $posPtr
        );
        $context->builder->branch($ok);
        $context->builder->positionAtEnd($nextComma);
        $context->builder->store(
            $context->builder->inBoundsGEP($pos, $sizeT->constInt(1, false)),
            $posPtr
        );
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($ok);
        $context->builder->returnValue($oneI32);
        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zeroI32);
    }

    private static function emitParseArray(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $oneI32 = $i32->constInt(1, false);
        $zeroI32 = $i32->constInt(0, false);
        $oneI32Index = $i32->constInt(1, false);

        $posPtr = $fn->getParam(0);
        $end = $fn->getParam(1);
        $depthPtr = $fn->getParam(2);
        $maxDepth = $fn->getParam(3);
        $ht = $fn->getParam(4);
        $key = $fn->getParam(5);

        $idxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $listHtSlot = BasicBlockHelper::entryAlloca($context, $htPtr);
        $context->builder->store($sizeT->constInt(0, false), $idxSlot);

        $fail = $fn->appendBasicBlock('fail');
        $ok = $fn->appendBasicBlock('ok');
        $loop = $fn->appendBasicBlock('loop');
        $comma = $fn->appendBasicBlock('comma');

        $okBracket = $context->builder->call(
            $context->lookupFunction('__phpc_json_expect'),
            $posPtr,
            $end,
            $i8->constInt(ord('['), false)
        );
        $bb_after_open = $fn->appendBasicBlock('after_open');

        $context->builder->branchIf($context->i32Success($okBracket), $bb_after_open, $fail);
        $context->builder->positionAtEnd($bb_after_open);
        $context->builder->call($context->lookupFunction('__phpc_json_skip_ws'), $posPtr, $end);
        $empty = $context->builder->call(
            $context->lookupFunction('__phpc_json_expect'),
            $posPtr,
            $end,
            $i8->constInt(ord(']'), false)
        );
        $emptyBb = $fn->appendBasicBlock('empty_arr');
        $nonEmpty = $fn->appendBasicBlock('non_empty');
        $context->builder->branchIf($context->i32Success($empty), $emptyBb, $nonEmpty);
        $context->builder->positionAtEnd($emptyBb);
        $hasKey = $context->builder->icmp(Builder::INT_NE, $key, $i8p->constNull());
        $ensureBb = $fn->appendBasicBlock('ensure_empty');
        $emptyOk = $fn->appendBasicBlock('empty_ok');
        $context->builder->branchIf($hasKey, $ensureBb, $emptyOk);
        $context->builder->positionAtEnd($ensureBb);
        $context->builder->call($context->lookupFunction('__phpc_json_ensure_child'), $ht, $key);
        $context->builder->branch($emptyOk);
        $context->builder->positionAtEnd($emptyOk);
        $context->builder->branch($ok);

        $context->builder->positionAtEnd($nonEmpty);
        $hasKey2 = $context->builder->icmp(Builder::INT_NE, $key, $i8p->constNull());
        $withKey = $fn->appendBasicBlock('with_key');
        $noKey = $fn->appendBasicBlock('no_key');
        $context->builder->branchIf($hasKey2, $withKey, $noKey);
        $context->builder->positionAtEnd($withKey);
        $listHt = $context->builder->call($context->lookupFunction('__phpc_json_ensure_child'), $ht, $key);
        $context->builder->store($listHt, $listHtSlot);
        $context->builder->branch($loop);
        $context->builder->positionAtEnd($noKey);
        $context->builder->store($ht, $listHtSlot);
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $idx = $context->builder->load($idxSlot);
        $listHt = $context->builder->load($listHtSlot);
        $nullKey = $i8p->constNull();
        $okVal = $context->builder->call(
            $context->lookupFunction('__phpc_json_parse_value'),
            $posPtr,
            $end,
            $depthPtr,
            $maxDepth,
            $listHt,
            $nullKey,
            $oneI32Index,
            $idx
        );
        $bb_inc_idx = $fn->appendBasicBlock('inc_idx');

        $context->builder->branchIf($context->i32Success($okVal), $bb_inc_idx, $fail);
        $context->builder->positionAtEnd($bb_inc_idx);
        $context->builder->store($context->builder->add($idx, $sizeT->constInt(1, false)), $idxSlot);
        $context->builder->branch($comma);
        $context->builder->positionAtEnd($comma);
        $context->builder->call($context->lookupFunction('__phpc_json_skip_ws'), $posPtr, $end);
        $pos = $context->builder->load($posPtr);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $pos, $end);
        $bb_arr_close_chk = $fn->appendBasicBlock('arr_close_chk');

        $context->builder->branchIf($atEnd, $fail, $bb_arr_close_chk);
        $context->builder->positionAtEnd($bb_arr_close_chk);
        $ch = $context->builder->load($pos);
        $isClose = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord(']'), false));
        $isComma = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord(','), false));
        $closeBb = $fn->appendBasicBlock('arr_close');
        $nextComma = $fn->appendBasicBlock('arr_next');
        $commaOrFail = $fn->appendBasicBlock('arr_comma_or_fail');
        $context->builder->branchIf($isClose, $closeBb, $commaOrFail);
        $context->builder->positionAtEnd($commaOrFail);
        $context->builder->branchIf($isComma, $nextComma, $fail);
        $context->builder->positionAtEnd($closeBb);
        $context->builder->store($context->builder->inBoundsGEP($pos, $sizeT->constInt(1, false)), $posPtr);
        $context->builder->branch($ok);
        $context->builder->positionAtEnd($nextComma);
        $context->builder->store($context->builder->inBoundsGEP($pos, $sizeT->constInt(1, false)), $posPtr);
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($ok);
        $context->builder->returnValue($oneI32);
        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zeroI32);
    }

    private static function emitParseValue(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $dbl = $context->getTypeFromString('double');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $i8p->pointerType(0);
        $sizeT = $context->getTypeFromString('size_t');
        $oneI32 = $i32->constInt(1, false);
        $zeroI32 = $i32->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $zeroI64 = $i64->constInt(0, false);
        $valCap = $sizeT->constInt(self::VAL_CAP, false);
        $nullEnd = $i8pp->constNull();

        $posPtr = $fn->getParam(0);
        $end = $fn->getParam(1);
        $depthPtr = $fn->getParam(2);
        $maxDepth = $fn->getParam(3);
        $ht = $fn->getParam(4);
        $key = $fn->getParam(5);
        $useIndex = $fn->getParam(6);
        $index = $fn->getParam(7);

        $valBuf = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::VAL_CAP));
        $valBufPtr = $context->builder->pointerCast($valBuf, $i8p);

        $fail = $fn->appendBasicBlock('fail');
        $depthFail = $fn->appendBasicBlock('depth_fail');
        $work = $fn->appendBasicBlock('work');

        $depth = $context->builder->load($depthPtr);
        $tooDeep = $context->builder->icmp(Builder::INT_SGT, $depth, $maxDepth);
        $context->builder->branchIf($tooDeep, $depthFail, $work);
        $context->builder->positionAtEnd($depthFail);
        $context->builder->store($i32->constInt(self::ERROR_DEPTH, false), self::$lastErrorGlobal);
        $context->builder->branch($fail);

        $context->builder->positionAtEnd($work);
        $context->builder->call($context->lookupFunction('__phpc_json_skip_ws'), $posPtr, $end);
        $pos = $context->builder->load($posPtr);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $pos, $end);
        $dispatch = $fn->appendBasicBlock('dispatch');
        $context->builder->branchIf($atEnd, $fail, $dispatch);
        $context->builder->positionAtEnd($dispatch);
        $ch = $context->builder->load($pos);
        $noKey = $context->builder->icmp(Builder::INT_EQ, $key, $i8p->constNull());
        $isIndexed = $context->builder->icmp(Builder::INT_NE, $useIndex, $zeroI32);

        $strBb = $fn->appendBasicBlock('str');
        $objBb = $fn->appendBasicBlock('obj');
        $arrBb = $fn->appendBasicBlock('arr');
        $numBb = $fn->appendBasicBlock('num');
        $trueBb = $fn->appendBasicBlock('true');
        $falseBb = $fn->appendBasicBlock('false');
        $nullBb = $fn->appendBasicBlock('null');
        $done = $fn->appendBasicBlock('done');

        $isQuote = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('"'), false));
        $bb_chk_obj = $fn->appendBasicBlock('chk_obj');

        $context->builder->branchIf($isQuote, $strBb, $bb_chk_obj);
        $context->builder->positionAtEnd($bb_chk_obj);
        $isObj = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('{'), false));
        $bb_chk_arr = $fn->appendBasicBlock('chk_arr');

        $context->builder->branchIf($isObj, $objBb, $bb_chk_arr);
        $context->builder->positionAtEnd($bb_chk_arr);
        $isArr = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('['), false));
        $bb_chk_num = $fn->appendBasicBlock('chk_num');

        $context->builder->branchIf($isArr, $arrBb, $bb_chk_num);
        $context->builder->positionAtEnd($bb_chk_num);
        $isMinus = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('-'), false));
        $isDigit = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(ord('0'), false)),
            $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(ord('9'), false))
        );
        $isNum = $context->builder->or($isMinus, $isDigit);
        $bb_chk_true = $fn->appendBasicBlock('chk_true');

        $context->builder->branchIf($isNum, $numBb, $bb_chk_true);
        $context->builder->positionAtEnd($bb_chk_true);
        $isTrue = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('t'), false));
        $bb_chk_false = $fn->appendBasicBlock('chk_false');

        $context->builder->branchIf($isTrue, $trueBb, $bb_chk_false);
        $context->builder->positionAtEnd($bb_chk_false);
        $isFalse = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('f'), false));
        $bb_chk_null = $fn->appendBasicBlock('chk_null');

        $context->builder->branchIf($isFalse, $falseBb, $bb_chk_null);
        $context->builder->positionAtEnd($bb_chk_null);
        $isNull = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('n'), false));
        $context->builder->branchIf($isNull, $nullBb, $fail);

        $context->builder->positionAtEnd($strBb);
        $okStr = $context->builder->call(
            $context->lookupFunction('__phpc_json_parse_string'),
            $posPtr,
            $end,
            $valBufPtr,
            $valCap
        );
        $bb_store_str = $fn->appendBasicBlock('store_str');

        $context->builder->branchIf($context->i32Success($okStr), $bb_store_str, $fail);
        $context->builder->positionAtEnd($bb_store_str);
        $context->builder->call(
            $context->lookupFunction('__phpc_json_store_string'),
            $ht,
            $key,
            $useIndex,
            $index,
            $valBufPtr
        );
        $context->builder->branch($done);

        $badNest = $fn->appendBasicBlock('bad_nest');

        $context->builder->positionAtEnd($objBb);
        $badObj = $context->builder->or($isIndexed, $noKey);
        $bb_parse_obj = $fn->appendBasicBlock('parse_obj');

        $context->builder->branchIf($badObj, $badNest, $bb_parse_obj);
        $context->builder->positionAtEnd($bb_parse_obj);
        $depth = $context->builder->load($depthPtr);
        $context->builder->store($context->builder->add($depth, $oneI32), $depthPtr);
        $child = $context->builder->call($context->lookupFunction('__phpc_json_ensure_child'), $ht, $key);
        $okObj = $context->builder->call(
            $context->lookupFunction('__phpc_json_parse_object'),
            $posPtr,
            $end,
            $depthPtr,
            $maxDepth,
            $child
        );
        $restoreObj = $fn->appendBasicBlock('restore_obj');
        $bb_restore_obj_fail = $fn->appendBasicBlock('restore_obj_fail');

        $context->builder->branchIf($context->i32Success($okObj), $restoreObj, $bb_restore_obj_fail);
        $context->builder->positionAtEnd($bb_restore_obj_fail);
        $depth = $context->builder->load($depthPtr);
        $context->builder->store($context->builder->sub($depth, $oneI32), $depthPtr);
        $context->builder->branch($fail);
        $context->builder->positionAtEnd($restoreObj);
        $depth = $context->builder->load($depthPtr);
        $context->builder->store($context->builder->sub($depth, $oneI32), $depthPtr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($arrBb);
        $badArr = $context->builder->or($isIndexed, $noKey);
        $bb_parse_arr = $fn->appendBasicBlock('parse_arr');

        $context->builder->branchIf($badArr, $badNest, $bb_parse_arr);
        $context->builder->positionAtEnd($bb_parse_arr);
        $depth = $context->builder->load($depthPtr);
        $context->builder->store($context->builder->add($depth, $oneI32), $depthPtr);
        $okArr = $context->builder->call(
            $context->lookupFunction('__phpc_json_parse_array'),
            $posPtr,
            $end,
            $depthPtr,
            $maxDepth,
            $ht,
            $key
        );
        $restoreArr = $fn->appendBasicBlock('restore_arr');
        $restoreArrFail = $fn->appendBasicBlock('restore_arr_fail');
        $context->builder->branchIf($context->i32Success($okArr), $restoreArr, $restoreArrFail);
        $context->builder->positionAtEnd($restoreArrFail);
        $depth = $context->builder->load($depthPtr);
        $context->builder->store($context->builder->sub($depth, $oneI32), $depthPtr);
        $context->builder->branch($fail);
        $context->builder->positionAtEnd($restoreArr);
        $depth = $context->builder->load($depthPtr);
        $context->builder->store($context->builder->sub($depth, $oneI32), $depthPtr);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($badNest);
        $context->builder->branch($fail);

        $context->builder->positionAtEnd($numBb);
        $okNum = $context->builder->call(
            $context->lookupFunction('__phpc_json_parse_number'),
            $posPtr,
            $end,
            $valBufPtr,
            $valCap
        );
        $bb_num_store = $fn->appendBasicBlock('num_store');

        $context->builder->branchIf($context->i32Success($okNum), $bb_num_store, $fail);
        $context->builder->positionAtEnd($bb_num_store);
        $hasFrac = $context->builder->call(
            $context->lookupFunction('__phpc_json_has_fraction'),
            $valBufPtr
        );
        $fracBb = $fn->appendBasicBlock('frac');
        $intBb = $fn->appendBasicBlock('int_num');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $hasFrac, $zeroI32),
            $fracBb,
            $intBb
        );
        $context->builder->positionAtEnd($intBb);
        $longVal = $context->builder->call(
            $context->lookupFunction('strtoll'),
            $valBufPtr,
            $nullEnd,
            $i32->constInt(10, false)
        );
        $context->builder->call(
            $context->lookupFunction('__phpc_json_store_long'),
            $ht,
            $key,
            $useIndex,
            $index,
            $longVal
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($fracBb);
        $isIdx = $context->builder->icmp(Builder::INT_NE, $useIndex, $zeroI32);
        $fracIdx = $fn->appendBasicBlock('frac_idx');
        $fracKey = $fn->appendBasicBlock('frac_key');
        $context->builder->branchIf($isIdx, $fracIdx, $fracKey);
        $context->builder->positionAtEnd($fracIdx);
        $dblVal = $context->builder->call(
            $context->lookupFunction('strtod'),
            $valBufPtr,
            $nullEnd
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setDoubleAt'),
            $ht,
            $index,
            $dblVal
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($fracKey);
        $context->builder->call(
            $context->lookupFunction('__phpc_json_store_string'),
            $ht,
            $key,
            $useIndex,
            $index,
            $valBufPtr
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($trueBb);
        $okTrue = $context->builder->call(
            $context->lookupFunction('__phpc_json_parse_literal'),
            $posPtr,
            $end,
            self::literalCstr($context, 'true'),
            $valBufPtr,
            $valCap
        );
        $bb_store_true = $fn->appendBasicBlock('store_true');

        $context->builder->branchIf($context->i32Success($okTrue), $bb_store_true, $fail);
        $context->builder->positionAtEnd($bb_store_true);
        $context->builder->call(
            $context->lookupFunction('__phpc_json_store_bool'),
            $ht,
            $key,
            $useIndex,
            $index,
            $oneI32
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($falseBb);
        $okFalse = $context->builder->call(
            $context->lookupFunction('__phpc_json_parse_literal'),
            $posPtr,
            $end,
            self::literalCstr($context, 'false'),
            $valBufPtr,
            $valCap
        );
        $bb_store_false = $fn->appendBasicBlock('store_false');

        $context->builder->branchIf($context->i32Success($okFalse), $bb_store_false, $fail);
        $context->builder->positionAtEnd($bb_store_false);
        $context->builder->call(
            $context->lookupFunction('__phpc_json_store_bool'),
            $ht,
            $key,
            $useIndex,
            $index,
            $zeroI32
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($nullBb);
        $okNull = $context->builder->call(
            $context->lookupFunction('__phpc_json_parse_literal'),
            $posPtr,
            $end,
            self::literalCstr($context, 'null'),
            $valBufPtr,
            $valCap
        );
        $bb_store_null = $fn->appendBasicBlock('store_null');

        $context->builder->branchIf($context->i32Success($okNull), $bb_store_null, $fail);
        $context->builder->positionAtEnd($bb_store_null);
        $emptySlot = $context->builder->alloca($i8, 1);
        $context->builder->store($i8->constInt(0, false), $emptySlot);
        $emptyCstr = $context->builder->pointerCast($emptySlot, $i8p);
        $context->builder->call(
            $context->lookupFunction('__phpc_json_store_string'),
            $ht,
            $key,
            $useIndex,
            $index,
            $emptyCstr
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($oneI32);
        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zeroI32);
    }

    /** CGI application/json $_POST refresh — superglobals_refresh.c (#7389). */
    private static function emitParsePostBody(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $maxLen = $sizeT->constInt(self::MAX_LEN, false);
        $zeroI8 = $i8->constInt(0, false);
        $maxDepth = $i32->constInt(self::MAX_DEPTH, false);

        $ht = $fn->getParam(0);
        $body = $fn->getParam(1);

        $ret = $fn->appendBasicBlock('ret');
        $work = $fn->appendBasicBlock('work');
        $lenCheck = $fn->appendBasicBlock('len_check');
        $parse = $fn->appendBasicBlock('parse');
        $doParse = $fn->appendBasicBlock('do_parse');

        $isNull = $context->builder->icmp(Builder::INT_EQ, $body, $i8p->constNull());
        $context->builder->branchIf($isNull, $ret, $work);

        $context->builder->positionAtEnd($work);
        $first = $context->builder->load($body);
        $empty = $context->builder->icmp(Builder::INT_EQ, $first, $zeroI8);
        $context->builder->branchIf($empty, $ret, $lenCheck);

        $context->builder->positionAtEnd($lenCheck);
        $len = $context->builder->call($context->lookupFunction('strlen'), $body);
        $tooLong = $context->builder->icmp(Builder::INT_UGT, $len, $maxLen);
        $context->builder->branchIf($tooLong, $ret, $parse);

        $context->builder->positionAtEnd($parse);
        $posSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $depthSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $end = $context->builder->inBoundsGEP($body, $len);
        $context->builder->store($body, $posSlot);
        $context->builder->store($i32->constInt(0, false), $depthSlot);
        $context->builder->call($context->lookupFunction('__phpc_json_skip_ws'), $posSlot, $end);
        $pos = $context->builder->load($posSlot);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $pos, $end);
        $notObj = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->load($pos),
            $i8->constInt(ord('{'), false)
        );
        $context->builder->branchIf($context->builder->or($atEnd, $notObj), $ret, $doParse);

        $context->builder->positionAtEnd($doParse);
        $context->builder->call(
            $context->lookupFunction('__phpc_json_parse_object'),
            $posSlot,
            $end,
            $depthSlot,
            $maxDepth,
            $ht
        );
        $context->builder->branch($ret);

        $context->builder->positionAtEnd($ret);
        $context->builder->returnVoid();
    }

    private static function emitParseTop(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $i8p->pointerType(0);
        $sizeT = $context->getTypeFromString('size_t');
        $oneI32 = $i32->constInt(1, false);
        $zeroI32 = $i32->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $zeroI64 = $i64->constInt(0, false);
        $valCap = $sizeT->constInt(self::VAL_CAP, false);
        $nullEnd = $i8pp->constNull();

        $posPtr = $fn->getParam(0);
        $end = $fn->getParam(1);
        $depthPtr = $fn->getParam(2);
        $maxDepth = $fn->getParam(3);
        $out = $fn->getParam(4);

        $valBuf = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::VAL_CAP));
        $valBufPtr = $context->builder->pointerCast($valBuf, $i8p);

        $fail = $fn->appendBasicBlock('fail');
        $work = $fn->appendBasicBlock('work');
        $context->builder->call($context->lookupFunction('__phpc_json_skip_ws'), $posPtr, $end);
        $pos = $context->builder->load($posPtr);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $pos, $end);
        $context->builder->branchIf($atEnd, $fail, $work);
        $context->builder->positionAtEnd($work);
        $ch = $context->builder->load($pos);

        $objBb = $fn->appendBasicBlock('top_obj');
        $arrBb = $fn->appendBasicBlock('top_arr');
        $strBb = $fn->appendBasicBlock('top_str');
        $numBb = $fn->appendBasicBlock('top_num');
        $trueBb = $fn->appendBasicBlock('top_true');
        $falseBb = $fn->appendBasicBlock('top_false');
        $nullBb = $fn->appendBasicBlock('top_null');
        $ok = $fn->appendBasicBlock('ok');

        $isObj = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('{'), false));
        $bb_top_chk_arr = $fn->appendBasicBlock('top_chk_arr');

        $context->builder->branchIf($isObj, $objBb, $bb_top_chk_arr);
        $context->builder->positionAtEnd($bb_top_chk_arr);
        $isArr = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('['), false));
        $bb_top_chk_str = $fn->appendBasicBlock('top_chk_str');

        $context->builder->branchIf($isArr, $arrBb, $bb_top_chk_str);
        $context->builder->positionAtEnd($bb_top_chk_str);
        $isStr = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('"'), false));
        $bb_top_chk_num = $fn->appendBasicBlock('top_chk_num');

        $context->builder->branchIf($isStr, $strBb, $bb_top_chk_num);
        $context->builder->positionAtEnd($bb_top_chk_num);
        $isMinus = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('-'), false));
        $isDigit = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(ord('0'), false)),
            $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(ord('9'), false))
        );
        $bb_top_chk_true = $fn->appendBasicBlock('top_chk_true');

        $context->builder->branchIf($context->builder->or($isMinus, $isDigit), $numBb, $bb_top_chk_true);
        $context->builder->positionAtEnd($bb_top_chk_true);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('t'), false)),
            $trueBb,
            $fn->appendBasicBlock('top_chk_false')
        );
        $topChkFalse = $fn->appendBasicBlock('top_chk_false');
        $context->builder->positionAtEnd($topChkFalse);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('f'), false)),
            $falseBb,
            $fn->appendBasicBlock('top_chk_null')
        );
        $topChkNull = $fn->appendBasicBlock('top_chk_null');
        $context->builder->positionAtEnd($topChkNull);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('n'), false)),
            $nullBb,
            $fail
        );

        $context->builder->positionAtEnd($objBb);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $okObj = $context->builder->call(
            $context->lookupFunction('__phpc_json_parse_object'),
            $posPtr,
            $end,
            $depthPtr,
            $maxDepth,
            $ht
        );
        $bb_write_ht = $fn->appendBasicBlock('write_ht');

        $context->builder->branchIf($context->i32Success($okObj), $bb_write_ht, $fail);
        $context->builder->positionAtEnd($bb_write_ht);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $out, $ht);
        $context->builder->branch($ok);

        $context->builder->positionAtEnd($arrBb);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $okArr = $context->builder->call(
            $context->lookupFunction('__phpc_json_parse_array'),
            $posPtr,
            $end,
            $depthPtr,
            $maxDepth,
            $ht,
            $i8p->constNull()
        );
        $bb_write_ht_arr = $fn->appendBasicBlock('write_ht_arr');

        $context->builder->branchIf($context->i32Success($okArr), $bb_write_ht_arr, $fail);
        $context->builder->positionAtEnd($bb_write_ht_arr);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $out, $ht);
        $context->builder->branch($ok);

        $context->builder->positionAtEnd($strBb);
        $okStr = $context->builder->call(
            $context->lookupFunction('__phpc_json_parse_string'),
            $posPtr,
            $end,
            $valBufPtr,
            $valCap
        );
        $bb_write_str = $fn->appendBasicBlock('write_str');

        $context->builder->branchIf($context->i32Success($okStr), $bb_write_str, $fail);
        $context->builder->positionAtEnd($bb_write_str);
        $strVal = $context->builder->call($context->lookupFunction('__phpc_json_cstr_to_string'), $valBufPtr);
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $strVal);
        $context->builder->branch($ok);

        $context->builder->positionAtEnd($numBb);
        $okNum = $context->builder->call(
            $context->lookupFunction('__phpc_json_parse_number'),
            $posPtr,
            $end,
            $valBufPtr,
            $valCap
        );
        $bb_top_num_store = $fn->appendBasicBlock('top_num_store');

        $context->builder->branchIf($context->i32Success($okNum), $bb_top_num_store, $fail);
        $context->builder->positionAtEnd($bb_top_num_store);
        $hasFrac = $context->builder->call(
            $context->lookupFunction('__phpc_json_has_fraction'),
            $valBufPtr
        );
        $topFrac = $fn->appendBasicBlock('top_frac');
        $topInt = $fn->appendBasicBlock('top_int');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $hasFrac, $zeroI32),
            $topFrac,
            $topInt
        );
        $context->builder->positionAtEnd($topInt);
        $longVal = $context->builder->call(
            $context->lookupFunction('strtoll'),
            $valBufPtr,
            $nullEnd,
            $i32->constInt(10, false)
        );
        $context->builder->call($context->lookupFunction('__value__writeLong'), $out, $longVal);
        $context->builder->branch($ok);
        $context->builder->positionAtEnd($topFrac);
        $dblVal = $context->builder->call(
            $context->lookupFunction('strtod'),
            $valBufPtr,
            $nullEnd
        );
        $context->builder->call($context->lookupFunction('__value__writeDouble'), $out, $dblVal);
        $context->builder->branch($ok);

        $context->builder->positionAtEnd($trueBb);
        $okTrue = $context->builder->call(
            $context->lookupFunction('__phpc_json_parse_literal'),
            $posPtr,
            $end,
            self::literalCstr($context, 'true'),
            $valBufPtr,
            $valCap
        );
        $bb_write_true = $fn->appendBasicBlock('write_true');

        $context->builder->branchIf($context->i32Success($okTrue), $bb_write_true, $fail);
        $context->builder->positionAtEnd($bb_write_true);
        $context->builder->call($context->lookupFunction('__value__writeLong'), $out, $oneI64);
        $context->builder->branch($ok);

        $context->builder->positionAtEnd($falseBb);
        $okFalse = $context->builder->call(
            $context->lookupFunction('__phpc_json_parse_literal'),
            $posPtr,
            $end,
            self::literalCstr($context, 'false'),
            $valBufPtr,
            $valCap
        );
        $bb_write_false = $fn->appendBasicBlock('write_false');

        $context->builder->branchIf($context->i32Success($okFalse), $bb_write_false, $fail);
        $context->builder->positionAtEnd($bb_write_false);
        $context->builder->call($context->lookupFunction('__value__writeLong'), $out, $zeroI64);
        $context->builder->branch($ok);

        $context->builder->positionAtEnd($nullBb);
        $okNull = $context->builder->call(
            $context->lookupFunction('__phpc_json_parse_literal'),
            $posPtr,
            $end,
            self::literalCstr($context, 'null'),
            $valBufPtr,
            $valCap
        );
        $bb_write_null = $fn->appendBasicBlock('write_null');

        $context->builder->branchIf($context->i32Success($okNull), $bb_write_null, $fail);
        $context->builder->positionAtEnd($bb_write_null);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $out);
        $context->builder->branch($ok);

        $context->builder->positionAtEnd($ok);
        $context->builder->returnValue($oneI32);
        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zeroI32);
    }

    private static function emitCompilerJsonDecode(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $map = $context->structFieldMap['__string__'];
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $maxLen = $context->getTypeFromString('size_t')->constInt(self::MAX_LEN, false);

        $json = $fn->getParam(0);
        $out = $fn->getParam(1);

        $depthSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $posSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store($i32->constInt(0, false), $depthSlot);
        $context->builder->store($i32->constInt(self::ERROR_NONE, false), self::$lastErrorGlobal);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $out);

        $ret = $fn->appendBasicBlock('ret');
        $fail = $fn->appendBasicBlock('fail');
        $work = $fn->appendBasicBlock('work');
        $parse = $fn->appendBasicBlock('parse');

        $isNull = $context->builder->icmp(Builder::INT_EQ, $json, $strPtr->constNull());
        $bb_null_json = $fn->appendBasicBlock('null_json');

        $context->builder->branchIf($isNull, $bb_null_json, $work);
        $context->builder->positionAtEnd($bb_null_json);
        $context->builder->store($i32->constInt(self::ERROR_SYNTAX, false), self::$lastErrorGlobal);
        $context->builder->branch($ret);

        $context->builder->positionAtEnd($work);
        $data = $context->builder->structGep($json, $map['value']);
        $len = $context->builder->load($context->builder->structGep($json, $map['length']));
        $lenZ = $context->builder->zExt($len, $context->getTypeFromString('size_t'));
        $zeroLen = $context->builder->icmp(Builder::INT_EQ, $lenZ, $context->getTypeFromString('size_t')->constInt(0, false));
        $tooLong = $context->builder->icmp(Builder::INT_UGT, $lenZ, $maxLen);
        $context->builder->branchIf($context->builder->or($zeroLen, $tooLong), $fail, $parse);

        $context->builder->positionAtEnd($parse);
        $body = $context->builder->pointerCast($data, $i8p);
        $end = $context->builder->inBoundsGEP($body, $lenZ);
        $context->builder->store($body, $posSlot);
        $ok = $context->builder->call(
            $context->lookupFunction('__phpc_json_parse_top'),
            $posSlot,
            $end,
            $depthSlot,
            $i32->constInt(self::MAX_DEPTH, false),
            $out
        );
        $context->builder->branchIf($context->i32Success($ok), $ret, $fail);

        $context->builder->positionAtEnd($fail);
        $context->builder->store($i32->constInt(self::ERROR_SYNTAX, false), self::$lastErrorGlobal);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $out);
        $context->builder->branch($ret);

        $context->builder->positionAtEnd($ret);
        $context->builder->returnVoid();
    }

    private static function emitCompilerJsonValidate(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $map = $context->structFieldMap['__string__'];
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $maxLen = $context->getTypeFromString('size_t')->constInt(self::MAX_LEN, false);
        $oneI64 = $i64->constInt(1, false);
        $zeroI64 = $i64->constInt(0, false);
        $negOneI64 = $i64->constInt(-1, true);

        $json = $fn->getParam(0);
        $maxDepthArg = $fn->getParam(1);

        $depthSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $posSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $outStorage = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('int8')->arrayType(128));
        $outPtr = $context->builder->pointerCast($outStorage, $valuePtr);
        $savedErrSlot = BasicBlockHelper::entryAlloca($context, $i32);

        $context->builder->store($i32->constInt(self::ERROR_NONE, false), self::$lastErrorGlobal);

        $fail0 = $fn->appendBasicBlock('fail0');
        $work = $fn->appendBasicBlock('work');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $json, $strPtr->constNull());
        $bb_chk_depth = $fn->appendBasicBlock('chk_depth');

        $context->builder->branchIf($isNull, $fail0, $bb_chk_depth);
        $context->builder->positionAtEnd($bb_chk_depth);
        $badDepth = $context->builder->icmp(Builder::INT_SLT, $maxDepthArg, $oneI64);
        $context->builder->branchIf($badDepth, $fail0, $work);

        $context->builder->positionAtEnd($work);
        $data = $context->builder->structGep($json, $map['value']);
        $len = $context->builder->load($context->builder->structGep($json, $map['length']));
        $lenZ = $context->builder->zExt($len, $context->getTypeFromString('size_t'));
        $zeroLen = $context->builder->icmp(Builder::INT_EQ, $lenZ, $context->getTypeFromString('size_t')->constInt(0, false));
        $tooLong = $context->builder->icmp(Builder::INT_UGT, $lenZ, $maxLen);
        $parse = $fn->appendBasicBlock('parse');
        $context->builder->branchIf($context->builder->or($zeroLen, $tooLong), $fail0, $parse);

        $context->builder->positionAtEnd($parse);
        $context->builder->call(
            $context->lookupFunction('memset'),
            $context->bytePtr($outStorage),
            $i32->constInt(0, false),
            $context->getTypeFromString('size_t')->constInt(128, false)
        );
        $context->builder->call($context->lookupFunction('__value__writeNull'), $outPtr);
        $context->builder->store($i32->constInt(0, false), $depthSlot);
        $body = $context->builder->pointerCast($data, $i8p);
        $end = $context->builder->inBoundsGEP($body, $lenZ);
        $context->builder->store($body, $posSlot);
        $context->builder->store($context->builder->load(self::$lastErrorGlobal), $savedErrSlot);
        $maxDepthI32 = $context->builder->truncOrBitCast($maxDepthArg, $i32);
        $ok = $context->builder->call(
            $context->lookupFunction('__phpc_json_parse_top'),
            $posSlot,
            $end,
            $depthSlot,
            $maxDepthI32,
            $outPtr
        );
        $parseFail = $fn->appendBasicBlock('parse_fail');
        $tail = $fn->appendBasicBlock('tail');
        $context->builder->branchIf($context->i32Success($ok), $tail, $parseFail);

        $context->builder->positionAtEnd($parseFail);
        $depthErr = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load(self::$lastErrorGlobal),
            $i32->constInt(self::ERROR_DEPTH, false)
        );
        $depthRet = $fn->appendBasicBlock('depth_ret');
        $context->builder->branchIf($depthErr, $depthRet, $fail0);
        $context->builder->positionAtEnd($depthRet);
        $context->builder->returnValue($negOneI64);

        $context->builder->positionAtEnd($tail);
        $context->builder->call($context->lookupFunction('__phpc_json_skip_ws'), $posSlot, $end);
        $pos = $context->builder->load($posSlot);
        $atEnd = $context->builder->icmp(Builder::INT_EQ, $pos, $end);
        $failTail = $fn->appendBasicBlock('fail_tail');
        $success = $fn->appendBasicBlock('success');
        $context->builder->branchIf($atEnd, $success, $failTail);
        $context->builder->positionAtEnd($failTail);
        $context->builder->returnValue($zeroI64);
        $context->builder->positionAtEnd($success);
        $context->builder->store($context->builder->load($savedErrSlot), self::$lastErrorGlobal);
        $context->builder->returnValue($oneI64);

        $context->builder->positionAtEnd($fail0);
        $context->builder->returnValue($zeroI64);
    }

    private static function emitCompilerJsonLastError(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $i64 = $context->getTypeFromString('int64');
        $code = $context->builder->load(self::$lastErrorGlobal);
        $context->builder->returnValue($context->builder->zExt($code, $i64));
    }

    private static function emitCompilerJsonLastErrorMsg(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $code = $context->builder->load(self::$lastErrorGlobal);

        $noneBb = $fn->appendBasicBlock('none');
        $depthBb = $fn->appendBasicBlock('depth');
        $syntaxBb = $fn->appendBasicBlock('syntax');
        $unknownBb = $fn->appendBasicBlock('unknown');
        $done = $fn->appendBasicBlock('done');
        $resultSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('__string__*'));

        $switch = $context->builder->branchSwitch($code, $unknownBb, 3);
        $switch->addCase($i32->constInt(self::ERROR_NONE, false), $noneBb);
        $switch->addCase($i32->constInt(self::ERROR_DEPTH, false), $depthBb);
        $switch->addCase($i32->constInt(self::ERROR_SYNTAX, false), $syntaxBb);

        $context->builder->positionAtEnd($noneBb);
        $context->builder->store(self::msgString($context, 'No error'), $resultSlot);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($depthBb);
        $context->builder->store(self::msgString($context, 'Maximum stack depth exceeded'), $resultSlot);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($syntaxBb);
        $context->builder->store(self::msgString($context, 'Syntax error'), $resultSlot);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($unknownBb);
        $context->builder->store(self::msgString($context, 'Unknown error'), $resultSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($context->builder->load($resultSlot));
    }

    private static function msgString(Context $context, string $text): Value
    {
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(\strlen($text), false),
            $context->builder->pointerCast($context->constantFromString($text), $i8p)
        );
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
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
