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
 * LLVM __compiler_unserialize / phpc_session_decode_payload (mirrors phpc_unserialize.c, #5991).
 *
 * php-src: ext/standard/var_unserializer.c — scalar/array subset (#1175).
 */
final class StringUnserializeJit
{
    private const MAX_LEN = 8 * 1024 * 1024;

    private const STR_CAP = 4096;

    private const KIND_NULL = 0;

    private const KIND_BOOL = 1;

    private const KIND_LONG = 2;

    private const KIND_STRING = 3;

    private const KIND_ARRAY = 4;

    public static function implement(Context $context): void
    {
        $restore = self::captureInsertBlock($context);
        self::ensureLibc($context);
        self::ensureRuntimeHelpers($context);

        $probe = $context->module->getNamedFunction('__compiler_unserialize');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_unserialize', $probe);
            self::restoreInsertBlock($context, $restore);

            return;
        }

        foreach (
            [
                '__phpc_unser_cstr_to_string',
                '__phpc_unser_expect',
                '__phpc_unser_parse_digits',
                '__phpc_unser_parse_signed_long',
                '__phpc_unser_parse_string_body',
                '__phpc_unser_ht_set',
                '__phpc_unser_write_value',
                '__phpc_unser_parse_string_item',
                '__phpc_unser_parse_array_item',
                '__phpc_unser_parse_item',
                '__compiler_unserialize',
                'phpc_session_decode_payload',
            ] as $name
        ) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = self::declareFunction($context, $name);
                $context->registerFunction($name, $fn);
            }
        }

        self::implementIfMissing($context, '__phpc_unser_cstr_to_string', self::emitCstrToString(...));
        self::implementIfMissing($context, '__phpc_unser_expect', self::emitExpect(...));
        self::implementIfMissing($context, '__phpc_unser_parse_digits', self::emitParseDigits(...));
        self::implementIfMissing($context, '__phpc_unser_parse_signed_long', self::emitParseSignedLong(...));
        self::implementIfMissing($context, '__phpc_unser_parse_string_body', self::emitParseStringBody(...));
        self::implementIfMissing($context, '__phpc_unser_ht_set', self::emitHtSet(...));
        self::implementIfMissing($context, '__phpc_unser_write_value', self::emitWriteValue(...));
        self::implementIfMissing($context, '__phpc_unser_parse_string_item', self::emitParseStringItem(...));
        self::implementIfMissing($context, '__phpc_unser_parse_item', self::emitParseItem(...));
        self::implementIfMissing($context, '__phpc_unser_parse_array_item', self::emitParseArrayItem(...));
        self::implementIfMissing($context, '__compiler_unserialize', self::emitCompilerUnserialize(...));
        self::implementIfMissing($context, 'phpc_session_decode_payload', self::emitSessionDecodePayload(...));

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
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $i8p->pointerType(0);
        $sizeT = $context->getTypeFromString('size_t');

        return match ($name) {
            '__phpc_unser_cstr_to_string' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $i8p)
            ),
            '__phpc_unser_expect' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8pp, $i8p, $i8)
            ),
            '__phpc_unser_parse_digits' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8pp, $i8p, $i64->pointerType(0))
            ),
            '__phpc_unser_parse_signed_long' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8pp, $i8p, $i64->pointerType(0))
            ),
            '__phpc_unser_parse_string_body' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8pp, $i8p, $sizeT, $i8p, $sizeT)
            ),
            '__phpc_unser_ht_set' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $htPtr, $strPtr, $i32, $i32, $i64, $i8p, $htPtr)
            ),
            '__phpc_unser_write_value' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $valuePtr, $i32, $i32, $i64, $i8p, $htPtr)
            ),
            '__phpc_unser_parse_string_item' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8pp, $i8p, $i32->pointerType(0), $i32->pointerType(0), $i64->pointerType(0), $i8p, $htPtr->pointerType(0))
            ),
            '__phpc_unser_parse_array_item' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8pp, $i8p, $i32->pointerType(0), $i32->pointerType(0), $i64->pointerType(0), $i8p, $htPtr->pointerType(0))
            ),
            '__phpc_unser_parse_item' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8pp, $i8p, $i32->pointerType(0), $i32->pointerType(0), $i64->pointerType(0), $i8p, $htPtr->pointerType(0))
            ),
            '__compiler_unserialize' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $strPtr, $valuePtr)
            ),
            'phpc_session_decode_payload' => $context->module->addFunction(
                $name,
                $context->context->functionType($htPtr, false, $i8p, $sizeT)
            ),
            default => throw new \LogicException('Unknown unserialize JIT helper: '.$name),
        };
    }

    private static function ensureLibc(Context $context): void
    {
        $voidPtr = $context->getTypeFromString('void*');
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');

        $i32 = $context->getTypeFromString('int32');
        self::ensureExternal($context, 'memcpy', $context->context->functionType($voidPtr, false, $voidPtr, $voidPtr, $sizeT));
        self::ensureExternal($context, 'strlen', $context->context->functionType($sizeT, false, $i8p));
        self::ensureExternal($context, 'memset', $context->context->functionType($voidPtr, false, $voidPtr, $i32, $sizeT));
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $void = $context->getTypeFromString('void');
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
                ['__value__writeNull', $void, [$valuePtr]],
                ['__value__writeLong', $void, [$valuePtr, $i64]],
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

    private static function emitCstrToString(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
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

    private static function emitExpect(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);

        $posPtr = $fn->getParam(0);
        $end = $fn->getParam(1);
        $expected = $fn->getParam(2);

        $pos = $context->builder->load($posPtr);
        $fail = $fn->appendBasicBlock('fail');
        $ok = $fn->appendBasicBlock('ok');
        $done = $fn->appendBasicBlock('done');

        $atEnd = $context->builder->icmp(Builder::INT_UGE, $pos, $end);
        $context->builder->branchIf($atEnd, $fail, $ok);

        $context->builder->positionAtEnd($ok);
        $ch = $context->builder->load($pos);
        $matches = $context->builder->icmp(Builder::INT_EQ, $ch, $expected);
        $context->builder->branchIf($matches, $done, $fail);

        $context->builder->positionAtEnd($done);
        $next = $context->builder->inBoundsGEP($pos, $one);
        $context->builder->store($next, $posPtr);
        $context->builder->returnValue($oneI32);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zeroI32);
    }

    private static function emitParseDigits(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $zeroI64 = $i64->constInt(0, false);
        $ten = $i64->constInt(10, false);
        $digit0 = $i8->constInt(ord('0'), false);
        $digit9 = $i8->constInt(ord('9'), false);
        $onePtr = $sizeT->constInt(1, false);

        $posPtr = $fn->getParam(0);
        $end = $fn->getParam(1);
        $out = $fn->getParam(2);

        $nSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($zeroI64, $nSlot);

        $head = $fn->appendBasicBlock('head');
        $body = $fn->appendBasicBlock('body');
        $fail = $fn->appendBasicBlock('fail');
        $success = $fn->appendBasicBlock('success');

        $pos = $context->builder->load($posPtr);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $pos, $end);
        $context->builder->branchIf($atEnd, $fail, $head);

        $context->builder->positionAtEnd($head);
        $pos = $context->builder->load($posPtr);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $pos, $end);
        $context->builder->branchIf($atEnd, $success, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($pos);
        $isDigit = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ch, $digit0),
            $context->builder->icmp(Builder::INT_SLE, $ch, $digit9)
        );
        $work = $fn->appendBasicBlock('digits_work');
        $notDigit = $fn->appendBasicBlock('not_digit');
        $context->builder->branchIf($isDigit, $work, $notDigit);

        $context->builder->positionAtEnd($work);
        $n = $context->builder->load($nSlot);
        $digit = $context->builder->sub($ch, $digit0);
        $n = $context->builder->mulNoSignedWrap($n, $ten);
        $n = $context->builder->addNoSignedWrap($n, $context->builder->zExt($digit, $i64));
        $context->builder->store($n, $nSlot);
        $next = $context->builder->inBoundsGEP($pos, $onePtr);
        $context->builder->store($next, $posPtr);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($notDigit);
        $n = $context->builder->load($nSlot);
        $hadDigits = $context->builder->icmp(Builder::INT_NE, $n, $zeroI64);
        $context->builder->branchIf($hadDigits, $success, $fail);

        $context->builder->positionAtEnd($success);
        $context->builder->store($context->builder->load($nSlot), $out);
        $context->builder->returnValue($oneI32);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zeroI32);
    }

    private static function emitParseSignedLong(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $zeroI64 = $i64->constInt(0, false);
        $ten = $i64->constInt(10, false);
        $negOne = $i64->constInt(-1, true);
        $digit0 = $i8->constInt(ord('0'), false);
        $digit9 = $i8->constInt(ord('9'), false);
        $minus = $i8->constInt(ord('-'), false);
        $onePtr = $sizeT->constInt(1, false);

        $posPtr = $fn->getParam(0);
        $end = $fn->getParam(1);
        $out = $fn->getParam(2);

        $negSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $nSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($zeroI32, $negSlot);
        $context->builder->store($zeroI64, $nSlot);

        $fail = $fn->appendBasicBlock('fail');
        $afterSign = $fn->appendBasicBlock('after_sign');

        $pos = $context->builder->load($posPtr);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $pos, $end);
        $context->builder->branchIf($atEnd, $fail, $afterSign);

        $context->builder->positionAtEnd($afterSign);
        $pos = $context->builder->load($posPtr);
        $ch = $context->builder->load($pos);
        $isMinus = $context->builder->icmp(Builder::INT_EQ, $ch, $minus);
        $signBody = $fn->appendBasicBlock('sign_body');
        $digits = $fn->appendBasicBlock('digits');
        $context->builder->branchIf($isMinus, $signBody, $digits);

        $context->builder->positionAtEnd($signBody);
        $context->builder->store($oneI32, $negSlot);
        $next = $context->builder->inBoundsGEP($pos, $onePtr);
        $context->builder->store($next, $posPtr);
        $context->builder->branch($digits);

        $head = $fn->appendBasicBlock('head');
        $body = $fn->appendBasicBlock('body');
        $success = $fn->appendBasicBlock('success');

        $context->builder->positionAtEnd($digits);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $pos = $context->builder->load($posPtr);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $pos, $end);
        $context->builder->branchIf($atEnd, $success, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($pos);
        $isDigit = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ch, $digit0),
            $context->builder->icmp(Builder::INT_SLE, $ch, $digit9)
        );
        $work = $fn->appendBasicBlock('digits_work');
        $notDigit = $fn->appendBasicBlock('not_digit');
        $context->builder->branchIf($isDigit, $work, $notDigit);

        $context->builder->positionAtEnd($work);
        $n = $context->builder->load($nSlot);
        $digit = $context->builder->sub($ch, $digit0);
        $n = $context->builder->mulNoSignedWrap($n, $ten);
        $n = $context->builder->addNoSignedWrap($n, $context->builder->zExt($digit, $i64));
        $context->builder->store($n, $nSlot);
        $next = $context->builder->inBoundsGEP($pos, $onePtr);
        $context->builder->store($next, $posPtr);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($notDigit);
        $n = $context->builder->load($nSlot);
        $hadDigits = $context->builder->icmp(Builder::INT_NE, $n, $zeroI64);
        $context->builder->branchIf($hadDigits, $success, $fail);

        $context->builder->positionAtEnd($success);
        $n = $context->builder->load($nSlot);
        $neg = $context->builder->load($negSlot);
        $isNeg = $context->builder->icmp(Builder::INT_NE, $neg, $zeroI32);
        $final = $context->builder->select($isNeg, $context->builder->mulNoSignedWrap($n, $negOne), $n);
        $context->builder->store($final, $out);
        $context->builder->returnValue($oneI32);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zeroI32);
    }

    private static function emitParseStringBody(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');
        $oneI32 = $i32->constInt(1, false);
        $zeroI32 = $i32->constInt(0, false);
        $oneSize = $sizeT->constInt(1, false);

        $posPtr = $fn->getParam(0);
        $end = $fn->getParam(1);
        $len = $fn->getParam(2);
        $outBuf = $fn->getParam(3);
        $cap = $fn->getParam(4);

        $fail = $fn->appendBasicBlock('fail');
        $ok = $fn->appendBasicBlock('ok');

        $need = $context->builder->add($len, $oneSize);
        $tooBig = $context->builder->icmp(Builder::INT_UGT, $need, $cap);
        $pos = $context->builder->load($posPtr);
        $i64 = $context->getTypeFromString('int64');
        $remain = $context->builder->sub(
            $context->builder->ptrToInt($end, $i64),
            $context->builder->ptrToInt($pos, $i64)
        );
        $notEnough = $context->builder->icmp(Builder::INT_ULT, $remain, $context->builder->zExt($len, $i64));
        $bad = $context->builder->or($tooBig, $notEnough);
        $context->builder->branchIf($bad, $fail, $ok);

        $context->builder->positionAtEnd($ok);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($outBuf),
            $context->bytePtr($pos),
            $len
        );
        $nul = $context->builder->inBoundsGEP($outBuf, $len);
        $context->builder->store($context->getTypeFromString('int8')->constInt(0, false), $nul);
        $next = $context->builder->inBoundsGEP($pos, $len);
        $context->builder->store($next, $posPtr);
        $context->builder->returnValue($oneI32);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zeroI32);
    }

    private static function emitHtSet(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $emptySlot = $context->builder->alloca($context->getTypeFromString('int8'), 1);
        $context->builder->store($context->getTypeFromString('int8')->constInt(0, false), $emptySlot);
        $emptyCstr = $context->builder->pointerCast($emptySlot, $i8p);
        $empty = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(0, false),
            $emptyCstr
        );

        $ht = $fn->getParam(0);
        $key = $fn->getParam(1);
        $kind = $fn->getParam(2);
        $boolVal = $fn->getParam(3);
        $boolI1 = $context->builder->icmp(Builder::INT_NE, $boolVal, $zeroI32);
        $longVal = $fn->getParam(4);
        $strBuf = $fn->getParam(5);
        $childHt = $fn->getParam(6);

        $nullBb = $fn->appendBasicBlock('null');
        $boolBb = $fn->appendBasicBlock('bool');
        $longBb = $fn->appendBasicBlock('long');
        $strBb = $fn->appendBasicBlock('str');
        $arrBb = $fn->appendBasicBlock('arr');
        $failBb = $fn->appendBasicBlock('fail');
        $doneBb = $fn->appendBasicBlock('done');

        $switch = $context->builder->branchSwitch($kind, $failBb, 5);
        $switch->addCase($i32->constInt(self::KIND_NULL, false), $nullBb);
        $switch->addCase($i32->constInt(self::KIND_BOOL, false), $boolBb);
        $switch->addCase($i32->constInt(self::KIND_LONG, false), $longBb);
        $switch->addCase($i32->constInt(self::KIND_STRING, false), $strBb);
        $switch->addCase($i32->constInt(self::KIND_ARRAY, false), $arrBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $key,
            $empty
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($boolBb);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyBool'),
            $ht,
            $key,
            $boolI1
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($longBb);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $key,
            $longVal
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($strBb);
        $valStr = $context->builder->call(
            $context->lookupFunction('__phpc_unser_cstr_to_string'),
            $strBuf
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $key,
            $valStr
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($arrBb);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyHashtable'),
            $ht,
            $key,
            $childHt
        );
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnValue($oneI32);

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($zeroI32);
    }

    private static function emitWriteValue(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $oneI64 = $i64->constInt(1, false);
        $zeroI32 = $i32->constInt(0, false);

        $out = $fn->getParam(0);
        $kind = $fn->getParam(1);
        $boolVal = $fn->getParam(2);
        $longVal = $fn->getParam(3);
        $strBuf = $fn->getParam(4);
        $childHt = $fn->getParam(5);

        $nullBb = $fn->appendBasicBlock('null');
        $boolBb = $fn->appendBasicBlock('bool');
        $longBb = $fn->appendBasicBlock('long');
        $strBb = $fn->appendBasicBlock('str');
        $arrBb = $fn->appendBasicBlock('arr');
        $defBb = $fn->appendBasicBlock('def');
        $doneBb = $fn->appendBasicBlock('done');

        $switch = $context->builder->branchSwitch($kind, $defBb, 5);
        $switch->addCase($i32->constInt(self::KIND_NULL, false), $nullBb);
        $switch->addCase($i32->constInt(self::KIND_BOOL, false), $boolBb);
        $switch->addCase($i32->constInt(self::KIND_LONG, false), $longBb);
        $switch->addCase($i32->constInt(self::KIND_STRING, false), $strBb);
        $switch->addCase($i32->constInt(self::KIND_ARRAY, false), $arrBb);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $out);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($boolBb);
        $asLong = $context->builder->select(
            $context->builder->icmp(Builder::INT_NE, $boolVal, $zeroI32),
            $oneI64,
            $i64->constInt(0, false)
        );
        $context->builder->call($context->lookupFunction('__value__writeLong'), $out, $asLong);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($longBb);
        $context->builder->call($context->lookupFunction('__value__writeLong'), $out, $longVal);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($strBb);
        $valStr = $context->builder->call($context->lookupFunction('__phpc_unser_cstr_to_string'), $strBuf);
        $context->builder->call($context->lookupFunction('__value__writeString'), $out, $valStr);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($arrBb);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $out, $childHt);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($defBb);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $out);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static function emitParseStringItem(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $sizeT = $context->getTypeFromString('size_t');
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $strCap = $sizeT->constInt(self::STR_CAP, false);

        $posPtr = $fn->getParam(0);
        $end = $fn->getParam(1);
        $kindOut = $fn->getParam(2);
        $boolOut = $fn->getParam(3);
        $longOut = $fn->getParam(4);
        $strBuf = $fn->getParam(5);
        $htOut = $fn->getParam(6);

        $lenSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('int64'));
        $fail = $fn->appendBasicBlock('fail');
        $ok = $fn->appendBasicBlock('ok');
        $digitsBb = $fn->appendBasicBlock('digits');
        $quoteBb = $fn->appendBasicBlock('quote');
        $bodyBb = $fn->appendBasicBlock('body');
        $closeBb = $fn->appendBasicBlock('close');

        $sCh = $i8->constInt(ord('s'), false);
        $colon = $i8->constInt(ord(':'), false);
        $quote = $i8->constInt(ord('"'), false);
        $semi = $i8->constInt(ord(';'), false);

        $ok1 = $context->builder->call($context->lookupFunction('__phpc_unser_expect'), $posPtr, $end, $sCh);
        $ok1b = $context->builder->call($context->lookupFunction('__phpc_unser_expect'), $posPtr, $end, $colon);
        $step1 = $context->builder->and($context->i32Success($ok1), $context->i32Success($ok1b));
        $context->builder->branchIf($step1, $digitsBb, $fail);

        $context->builder->positionAtEnd($digitsBb);
        $ok2 = $context->builder->call($context->lookupFunction('__phpc_unser_parse_digits'), $posPtr, $end, $lenSlot);
        $ok2b = $context->builder->call($context->lookupFunction('__phpc_unser_expect'), $posPtr, $end, $colon);
        $step2 = $context->builder->and($context->i32Success($ok2), $context->i32Success($ok2b));
        $context->builder->branchIf($step2, $quoteBb, $fail);

        $context->builder->positionAtEnd($quoteBb);
        $ok3 = $context->builder->call($context->lookupFunction('__phpc_unser_expect'), $posPtr, $end, $quote);
        $context->builder->branchIf($context->i32Success($ok3), $bodyBb, $fail);

        $context->builder->positionAtEnd($bodyBb);
        $len = $context->builder->truncOrBitCast($context->builder->load($lenSlot), $sizeT);
        $ok4 = $context->builder->call(
            $context->lookupFunction('__phpc_unser_parse_string_body'),
            $posPtr,
            $end,
            $len,
            $strBuf,
            $strCap
        );
        $context->builder->branchIf($context->i32Success($ok4), $closeBb, $fail);

        $context->builder->positionAtEnd($closeBb);
        $ok5 = $context->builder->call($context->lookupFunction('__phpc_unser_expect'), $posPtr, $end, $quote);
        $ok5b = $context->builder->call($context->lookupFunction('__phpc_unser_expect'), $posPtr, $end, $semi);
        $step5 = $context->builder->and($context->i32Success($ok5), $context->i32Success($ok5b));
        $context->builder->branchIf($step5, $ok, $fail);

        $context->builder->positionAtEnd($ok);
        $context->builder->store($i32->constInt(self::KIND_STRING, false), $kindOut);
        $context->builder->returnValue($oneI32);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zeroI32);
    }

    private static function emitParseArrayItem(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $zeroSize = $sizeT->constInt(0, false);
        $oneSize = $sizeT->constInt(1, false);
        $strCap = $sizeT->constInt(self::STR_CAP, false);

        $posPtr = $fn->getParam(0);
        $end = $fn->getParam(1);
        $kindOut = $fn->getParam(2);
        $boolOut = $fn->getParam(3);
        $longOut = $fn->getParam(4);
        $strBuf = $fn->getParam(5);
        $htOut = $fn->getParam(6);

        $countSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('int64'));
        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $keyKind = BasicBlockHelper::entryAlloca($context, $i32);
        $keyBool = BasicBlockHelper::entryAlloca($context, $i32);
        $keyLong = BasicBlockHelper::entryAlloca($context, $i64);
        $keyBuf = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('int8')->arrayType(self::STR_CAP));
        $keyBufPtr = $context->builder->pointerCast($keyBuf, $context->getTypeFromString('int8*'));
        $valKind = BasicBlockHelper::entryAlloca($context, $i32);
        $valBool = BasicBlockHelper::entryAlloca($context, $i32);
        $valLong = BasicBlockHelper::entryAlloca($context, $i64);
        $valBuf = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('int8')->arrayType(self::STR_CAP));
        $valBufPtr = $context->builder->pointerCast($valBuf, $context->getTypeFromString('int8*'));
        $valHtSlot = BasicBlockHelper::entryAlloca($context, $htPtr);

        $fail = $fn->appendBasicBlock('fail');
        $ok = $fn->appendBasicBlock('ok');
        $countBb = $fn->appendBasicBlock('count');
        $openBb = $fn->appendBasicBlock('open');
        $allocBb = $fn->appendBasicBlock('alloc');
        $loopHead = $fn->appendBasicBlock('loop_head');
        $loopBody = $fn->appendBasicBlock('loop_body');
        $loopDone = $fn->appendBasicBlock('loop_done');
        $valBb = $fn->appendBasicBlock('val');
        $setBb = $fn->appendBasicBlock('set');
        $incBb = $fn->appendBasicBlock('inc');

        $aCh = $i8->constInt(ord('a'), false);
        $colon = $i8->constInt(ord(':'), false);
        $brace = $i8->constInt(ord('{'), false);

        $ok1 = $context->builder->call($context->lookupFunction('__phpc_unser_expect'), $posPtr, $end, $aCh);
        $ok1b = $context->builder->call($context->lookupFunction('__phpc_unser_expect'), $posPtr, $end, $colon);
        $step1 = $context->builder->and($context->i32Success($ok1), $context->i32Success($ok1b));
        $context->builder->branchIf($step1, $countBb, $fail);

        $context->builder->positionAtEnd($countBb);
        $ok2 = $context->builder->call($context->lookupFunction('__phpc_unser_parse_digits'), $posPtr, $end, $countSlot);
        $ok2b = $context->builder->call($context->lookupFunction('__phpc_unser_expect'), $posPtr, $end, $colon);
        $step2 = $context->builder->and($context->i32Success($ok2), $context->i32Success($ok2b));
        $context->builder->branchIf($step2, $openBb, $fail);

        $context->builder->positionAtEnd($openBb);
        $ok3 = $context->builder->call($context->lookupFunction('__phpc_unser_expect'), $posPtr, $end, $brace);
        $context->builder->branchIf($context->i32Success($ok3), $allocBb, $fail);

        $context->builder->positionAtEnd($allocBb);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->store($zeroSize, $iSlot);
        $context->builder->branch($loopHead);
        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $count = $context->builder->truncOrBitCast($context->builder->load($countSlot), $sizeT);
        $doneLoop = $context->builder->icmp(Builder::INT_UGE, $i, $count);
        $context->builder->branchIf($doneLoop, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $okKey = $context->builder->call(
            $context->lookupFunction('__phpc_unser_parse_string_item'),
            $posPtr,
            $end,
            $keyKind,
            $keyBool,
            $keyLong,
            $keyBufPtr,
            $valHtSlot
        );
        $context->builder->branchIf($context->i32Success($okKey), $valBb, $fail);

        $context->builder->positionAtEnd($valBb);
        $okVal = $context->builder->call(
            $context->lookupFunction('__phpc_unser_parse_item'),
            $posPtr,
            $end,
            $valKind,
            $valBool,
            $valLong,
            $valBufPtr,
            $valHtSlot
        );
        $context->builder->branchIf($context->i32Success($okVal), $setBb, $fail);

        $context->builder->positionAtEnd($setBb);
        $keyStr = $context->builder->call($context->lookupFunction('__phpc_unser_cstr_to_string'), $keyBufPtr);
        $okSet = $context->builder->call(
            $context->lookupFunction('__phpc_unser_ht_set'),
            $ht,
            $keyStr,
            $context->builder->load($valKind),
            $context->builder->load($valBool),
            $context->builder->load($valLong),
            $valBufPtr,
            $context->builder->load($valHtSlot)
        );
        $context->builder->branchIf($context->i32Success($okSet), $incBb, $fail);

        $context->builder->positionAtEnd($incBb);
        $context->builder->store($context->builder->add($context->builder->load($iSlot), $oneSize), $iSlot);
        $context->builder->branch($loopHead);

        $closeBb = $fn->appendBasicBlock('close');
        $context->builder->positionAtEnd($loopDone);
        $closeBrace = $i8->constInt(ord('}'), false);
        $okClose = $context->builder->call($context->lookupFunction('__phpc_unser_expect'), $posPtr, $end, $closeBrace);
        $context->builder->branchIf($context->i32Success($okClose), $ok, $fail);

        $context->builder->positionAtEnd($ok);
        $context->builder->store($i32->constInt(self::KIND_ARRAY, false), $kindOut);
        $context->builder->store($ht, $htOut);
        $context->builder->returnValue($oneI32);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zeroI32);
    }

    private static function emitParseItem(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);

        $posPtr = $fn->getParam(0);
        $end = $fn->getParam(1);
        $kindOut = $fn->getParam(2);
        $boolOut = $fn->getParam(3);
        $longOut = $fn->getParam(4);
        $strBuf = $fn->getParam(5);
        $htOut = $fn->getParam(6);

        $fail = $fn->appendBasicBlock('fail');
        $dispatch = $fn->appendBasicBlock('dispatch');
        $nBb = $fn->appendBasicBlock('null');
        $bBb = $fn->appendBasicBlock('bool');
        $iBb = $fn->appendBasicBlock('int');
        $sBb = $fn->appendBasicBlock('str');
        $aBb = $fn->appendBasicBlock('arr');
        $chkB = $fn->appendBasicBlock('chk_b');
        $chkI = $fn->appendBasicBlock('chk_i');
        $chkS = $fn->appendBasicBlock('chk_s');
        $chkA = $fn->appendBasicBlock('chk_a');
        $nullOk = $fn->appendBasicBlock('null_ok');
        $boolValBb = $fn->appendBasicBlock('bool_val');
        $boolRead = $fn->appendBasicBlock('bool_read');
        $boolStore = $fn->appendBasicBlock('bool_store');
        $boolOk = $fn->appendBasicBlock('bool_ok');
        $intVal = $fn->appendBasicBlock('int_val');
        $intOk = $fn->appendBasicBlock('int_ok');

        $pos = $context->builder->load($posPtr);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $pos, $end);
        $context->builder->branchIf($atEnd, $fail, $dispatch);

        $context->builder->positionAtEnd($dispatch);
        $ch = $context->builder->load($pos);

        $nCh = $i8->constInt(ord('N'), false);
        $bCh = $i8->constInt(ord('b'), false);
        $iCh = $i8->constInt(ord('i'), false);
        $sCh = $i8->constInt(ord('s'), false);
        $aCh = $i8->constInt(ord('a'), false);
        $semi = $i8->constInt(ord(';'), false);
        $colon = $i8->constInt(ord(':'), false);
        $one = $context->getTypeFromString('int8*')->constInt(1, false);

        $isN = $context->builder->icmp(Builder::INT_EQ, $ch, $nCh);
        $isB = $context->builder->icmp(Builder::INT_EQ, $ch, $bCh);
        $isI = $context->builder->icmp(Builder::INT_EQ, $ch, $iCh);
        $isS = $context->builder->icmp(Builder::INT_EQ, $ch, $sCh);
        $isA = $context->builder->icmp(Builder::INT_EQ, $ch, $aCh);

        $context->builder->branchIf($isN, $nBb, $chkB);
        $context->builder->positionAtEnd($chkB);
        $context->builder->branchIf($isB, $bBb, $chkI);
        $context->builder->positionAtEnd($chkI);
        $context->builder->branchIf($isI, $iBb, $chkS);
        $context->builder->positionAtEnd($chkS);
        $context->builder->branchIf($isS, $sBb, $chkA);
        $context->builder->positionAtEnd($chkA);
        $context->builder->branchIf($isA, $aBb, $fail);

        $context->builder->positionAtEnd($nBb);
        $okN = $context->builder->call($context->lookupFunction('__phpc_unser_expect'), $posPtr, $end, $nCh);
        $okN2 = $context->builder->call($context->lookupFunction('__phpc_unser_expect'), $posPtr, $end, $semi);
        $okNull = $context->builder->and($context->i32Success($okN), $context->i32Success($okN2));
        $context->builder->branchIf($okNull, $nullOk, $fail);
        $context->builder->positionAtEnd($nullOk);
        $context->builder->store($i32->constInt(self::KIND_NULL, false), $kindOut);
        $context->builder->returnValue($oneI32);

        $context->builder->positionAtEnd($bBb);
        $okB1 = $context->builder->call($context->lookupFunction('__phpc_unser_expect'), $posPtr, $end, $bCh);
        $okB2 = $context->builder->call($context->lookupFunction('__phpc_unser_expect'), $posPtr, $end, $colon);
        $context->builder->branchIf(
            $context->builder->and($context->i32Success($okB1), $context->i32Success($okB2)),
            $boolValBb,
            $fail
        );
        $context->builder->positionAtEnd($boolValBb);
        $pos = $context->builder->load($posPtr);
        $atEnd = $context->builder->icmp(Builder::INT_UGE, $pos, $end);
        $context->builder->branchIf($atEnd, $fail, $boolRead);
        $context->builder->positionAtEnd($boolRead);
        $digit = $context->builder->load($pos);
        $is0 = $context->builder->icmp(Builder::INT_EQ, $digit, $i8->constInt(ord('0'), false));
        $is1 = $context->builder->icmp(Builder::INT_EQ, $digit, $i8->constInt(ord('1'), false));
        $valid = $context->builder->or($is0, $is1);
        $context->builder->branchIf($valid, $boolStore, $fail);
        $context->builder->positionAtEnd($boolStore);
        $storedBool = $context->builder->select($is1, $oneI32, $zeroI32);
        $context->builder->store($storedBool, $boolOut);
        $next = $context->builder->inBoundsGEP($pos, $one);
        $context->builder->store($next, $posPtr);
        $okB3 = $context->builder->call($context->lookupFunction('__phpc_unser_expect'), $posPtr, $end, $semi);
        $context->builder->branchIf($context->i32Success($okB3), $boolOk, $fail);
        $context->builder->positionAtEnd($boolOk);
        $context->builder->store($i32->constInt(self::KIND_BOOL, false), $kindOut);
        $context->builder->returnValue($oneI32);

        $context->builder->positionAtEnd($iBb);
        $okI1 = $context->builder->call($context->lookupFunction('__phpc_unser_expect'), $posPtr, $end, $iCh);
        $okI2 = $context->builder->call($context->lookupFunction('__phpc_unser_expect'), $posPtr, $end, $colon);
        $context->builder->branchIf(
            $context->builder->and($context->i32Success($okI1), $context->i32Success($okI2)),
            $intVal,
            $fail
        );
        $context->builder->positionAtEnd($intVal);
        $okI3 = $context->builder->call($context->lookupFunction('__phpc_unser_parse_signed_long'), $posPtr, $end, $longOut);
        $okI4 = $context->builder->call($context->lookupFunction('__phpc_unser_expect'), $posPtr, $end, $semi);
        $context->builder->branchIf(
            $context->builder->and($context->i32Success($okI3), $context->i32Success($okI4)),
            $intOk,
            $fail
        );
        $context->builder->positionAtEnd($intOk);
        $context->builder->store($i32->constInt(self::KIND_LONG, false), $kindOut);
        $context->builder->returnValue($oneI32);

        $context->builder->positionAtEnd($sBb);
        $okS = $context->builder->call(
            $context->lookupFunction('__phpc_unser_parse_string_item'),
            $posPtr,
            $end,
            $kindOut,
            $boolOut,
            $longOut,
            $strBuf,
            $htOut
        );
        $context->builder->returnValue($okS);

        $context->builder->positionAtEnd($aBb);
        $okA = $context->builder->call(
            $context->lookupFunction('__phpc_unser_parse_array_item'),
            $posPtr,
            $end,
            $kindOut,
            $boolOut,
            $longOut,
            $strBuf,
            $htOut
        );
        $context->builder->returnValue($okA);

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($zeroI32);
    }

    private static function emitCompilerUnserialize(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $map = $context->structFieldMap['__string__'];
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $maxLen = $context->getTypeFromString('size_t')->constInt(self::MAX_LEN, false);
        $strCap = $context->getTypeFromString('size_t')->constInt(self::STR_CAP, false);

        $payload = $fn->getParam(0);
        $out = $fn->getParam(1);

        $kindSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $boolSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $longSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $strBuf = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('int8')->arrayType(self::STR_CAP));
        $strBufPtr = $context->builder->pointerCast($strBuf, $i8p);
        $htSlot = BasicBlockHelper::entryAlloca($context, $htPtr);
        $posSlot = BasicBlockHelper::entryAlloca($context, $i8p);

        $nullPayload = $strPtr->constNull();
        $context->builder->call($context->lookupFunction('__value__writeNull'), $out);

        $retBb = $fn->appendBasicBlock('ret');
        $fail = $fn->appendBasicBlock('fail');
        $work = $fn->appendBasicBlock('work');
        $parseBb = $fn->appendBasicBlock('parse');
        $writeBb = $fn->appendBasicBlock('write');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $payload, $nullPayload);
        $context->builder->branchIf($isNull, $retBb, $work);

        $context->builder->positionAtEnd($work);
        $data = $context->builder->structGep($payload, $map['value']);
        $len = $context->builder->load($context->builder->structGep($payload, $map['length']));
        $lenZ = $context->builder->zExt($len, $context->getTypeFromString('size_t'));
        $zeroLen = $context->builder->icmp(Builder::INT_EQ, $lenZ, $context->getTypeFromString('size_t')->constInt(0, false));
        $tooLong = $context->builder->icmp(Builder::INT_UGT, $lenZ, $maxLen);
        $badLen = $context->builder->or($zeroLen, $tooLong);
        $context->builder->branchIf($badLen, $fail, $parseBb);

        $context->builder->positionAtEnd($parseBb);
        $body = $context->builder->pointerCast($data, $i8p);
        $end = $context->builder->inBoundsGEP($body, $lenZ);
        $context->builder->store($body, $posSlot);
        $ok = $context->builder->call(
            $context->lookupFunction('__phpc_unser_parse_item'),
            $posSlot,
            $end,
            $kindSlot,
            $boolSlot,
            $longSlot,
            $strBufPtr,
            $htSlot
        );
        $context->builder->branchIf($context->i32Success($ok), $writeBb, $fail);

        $context->builder->positionAtEnd($writeBb);
        $context->builder->call(
            $context->lookupFunction('__phpc_unser_write_value'),
            $out,
            $context->builder->load($kindSlot),
            $context->builder->load($boolSlot),
            $context->builder->load($longSlot),
            $strBufPtr,
            $context->builder->load($htSlot)
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $out);
        $context->builder->branch($retBb);

        $context->builder->positionAtEnd($writeBb);
        $context->builder->branch($retBb);

        $context->builder->positionAtEnd($retBb);
        $context->builder->returnVoid();
    }

    private static function emitSessionDecodePayload(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $sizeT = $context->getTypeFromString('size_t');
        $maxLen = $sizeT->constInt(self::MAX_LEN, false);
        $zeroSize = $sizeT->constInt(0, false);

        $body = $fn->getParam(0);
        $len = $fn->getParam(1);

        $kindSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $boolSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $longSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $strBuf = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('int8')->arrayType(self::STR_CAP));
        $strBufPtr = $context->builder->pointerCast($strBuf, $i8p);
        $htSlot = BasicBlockHelper::entryAlloca($context, $htPtr);
        $posSlot = BasicBlockHelper::entryAlloca($context, $i8p);

        $emptyHt = $fn->appendBasicBlock('empty');
        $work = $fn->appendBasicBlock('work');
        $fail = $fn->appendBasicBlock('fail');
        $ok = $fn->appendBasicBlock('ok');

        $nullBody = $context->builder->icmp(Builder::INT_EQ, $body, $i8p->constNull());
        $zeroLen = $context->builder->icmp(Builder::INT_EQ, $len, $zeroSize);
        $tooLong = $context->builder->icmp(Builder::INT_UGT, $len, $maxLen);
        $bad = $context->builder->or($nullBody, $context->builder->or($zeroLen, $tooLong));
        $context->builder->branchIf($bad, $emptyHt, $work);

        $context->builder->positionAtEnd($emptyHt);
        $context->builder->returnValue($context->builder->call($context->lookupFunction('__hashtable__alloc')));

        $context->builder->positionAtEnd($work);
        $end = $context->builder->inBoundsGEP($body, $len);
        $context->builder->store($body, $posSlot);
        $parsed = $context->builder->call(
            $context->lookupFunction('__phpc_unser_parse_item'),
            $posSlot,
            $end,
            $kindSlot,
            $boolSlot,
            $longSlot,
            $strBufPtr,
            $htSlot
        );
        $isArray = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($kindSlot),
            $i32->constInt(self::KIND_ARRAY, false)
        );
        $hasHt = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->load($htSlot),
            $htPtr->constNull()
        );
        $good = $context->builder->and(
            $context->i32Success($parsed),
            $context->builder->and($isArray, $hasHt)
        );
        $context->builder->branchIf($good, $ok, $fail);

        $context->builder->positionAtEnd($ok);
        $context->builder->returnValue($context->builder->load($htSlot));

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($context->builder->call($context->lookupFunction('__hashtable__alloc')));
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
