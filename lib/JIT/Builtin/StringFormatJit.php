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
 * LLVM __compiler_sprintf/__compiler_printf/__compiler_number_format
 * (mirrors former superglobals_refresh.c nf/sp helpers; #1492).
 */
final class StringFormatJit
{
    private const SPRINTF_MAX_OUT = 4096;

    private const PHPC_TYPE_NULL = 0;

    private const PHPC_TYPE_LONG = 1;

    private const PHPC_TYPE_BOOL = 2;

    private const PHPC_TYPE_DOUBLE = 3;

    private const PHPC_TYPE_STRING = 4;

    /** @var list<string> */
    private const HELPERS = [
        '__phpc_fmt_cstr_to_string',
        '__phpc_fmt_pow10',
        '__phpc_fmt_round_scaled',
        '__phpc_fmt_append_char',
        '__phpc_fmt_append_str',
        '__phpc_fmt_format_unsigned',
        '__phpc_fmt_format_fraction',
        '__phpc_fmt_append_decimal_ll',
        '__phpc_fmt_append_float',
        '__phpc_fmt_append_float_prec',
        '__phpc_fmt_append_spec',
        '__phpc_fmt_append_spec_f_prec',
        '__phpc_fmt_append_spec_snprintf',
        '__phpc_fmt_append_spec_flagged',
        '__compiler_sprintf',
        '__compiler_printf',
        '__compiler_number_format',
    ];

    public static function implement(Context $context): void
    {
        $restore = self::captureInsertBlock($context);
        self::ensureLibc($context);
        self::ensureRuntimeHelpers($context);

        $probe = $context->module->getNamedFunction('__compiler_sprintf');
        $snprintfSpec = $context->module->getNamedFunction('__phpc_fmt_append_spec_snprintf');
        $floatPrecSpec = $context->module->getNamedFunction('__phpc_fmt_append_spec_f_prec');
        if (
            null !== $probe
            && $probe->countBasicBlocks() > 0
            && null !== $snprintfSpec
            && $snprintfSpec->countBasicBlocks() > 0
            && null !== $floatPrecSpec
            && $floatPrecSpec->countBasicBlocks() > 0
        ) {
            $context->registerFunction('__compiler_sprintf', $probe);
            foreach (
                [
                    '__compiler_printf',
                    '__compiler_number_format',
                    '__phpc_fmt_append_spec_snprintf',
                    '__phpc_fmt_append_spec_f_prec',
                    '__phpc_fmt_append_float_prec',
                ] as $name
            ) {
                $existing = $context->module->getNamedFunction($name);
                if (null !== $existing) {
                    $context->registerFunction($name, $existing);
                }
            }
            self::restoreInsertBlock($context, $restore);

            return;
        }

        foreach (self::HELPERS as $name) {
            try {
                $context->lookupFunction($name);
            } catch (\Throwable) {
                $fn = self::declareFunction($context, $name);
                $context->registerFunction($name, $fn);
            }
        }

        self::implementIfMissing($context, '__phpc_fmt_cstr_to_string', self::emitCstrToString(...));
        self::implementIfMissing($context, '__phpc_fmt_pow10', self::emitPow10(...));
        self::implementIfMissing($context, '__phpc_fmt_round_scaled', self::emitRoundScaled(...));
        self::implementIfMissing($context, '__phpc_fmt_append_char', self::emitAppendChar(...));
        self::implementIfMissing($context, '__phpc_fmt_append_str', self::emitAppendStr(...));
        self::implementIfMissing($context, '__phpc_fmt_format_unsigned', self::emitFormatUnsigned(...));
        self::implementIfMissing($context, '__phpc_fmt_format_fraction', self::emitFormatFraction(...));
        self::implementIfMissing($context, '__phpc_fmt_append_decimal_ll', self::emitAppendDecimalLl(...));
        self::implementIfMissing($context, '__phpc_fmt_append_float', self::emitAppendFloat(...));
        self::implementIfMissing($context, '__phpc_fmt_append_float_prec', self::emitAppendFloatPrec(...));
        self::implementIfMissing($context, '__phpc_fmt_append_spec', self::emitAppendSpec(...));
        self::implementIfMissing($context, '__phpc_fmt_append_spec_f_prec', self::emitAppendSpecFloatPrec(...));
        self::implementIfMissing($context, '__phpc_fmt_append_spec_snprintf', self::emitAppendSpecSnprintf(...));
        self::implementIfMissing($context, '__phpc_fmt_append_spec_flagged', self::emitAppendSpecFlagged(...));
        self::implementIfMissing($context, '__compiler_sprintf', self::emitCompilerSprintf(...));
        self::implementIfMissing($context, '__compiler_printf', self::emitCompilerPrintf(...));
        self::implementIfMissing($context, '__compiler_number_format', self::emitCompilerNumberFormat(...));

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
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $void = $context->getTypeFromString('void');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $dbl = $context->getTypeFromString('double');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $sizeTp = $sizeT->pointerType(0);

        return match ($name) {
            '__phpc_fmt_cstr_to_string' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $i8p)
            ),
            '__phpc_fmt_pow10' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $i32)
            ),
            '__phpc_fmt_round_scaled' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $dbl, $i64)
            ),
            '__phpc_fmt_append_char' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $i8p, $sizeTp, $sizeT, $i8)
            ),
            '__phpc_fmt_append_str' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $i8p, $sizeTp, $sizeT, $i8p, $sizeT)
            ),
            '__phpc_fmt_format_unsigned' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $i64, $i8p, $sizeT, $strPtr)
            ),
            '__phpc_fmt_format_fraction' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $i64, $i64, $i8p, $sizeT)
            ),
            '__phpc_fmt_append_decimal_ll' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $i8p, $sizeTp, $sizeT, $i64)
            ),
            '__phpc_fmt_append_float' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $i8p, $sizeTp, $sizeT, $dbl)
            ),
            '__phpc_fmt_append_float_prec' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $i8p, $sizeTp, $sizeT, $dbl, $i32)
            ),
            '__phpc_fmt_append_spec' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $i8p, $sizeTp, $sizeT, $valuePtr, $i8)
            ),
            '__phpc_fmt_append_spec_f_prec' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $i8p, $sizeTp, $sizeT, $valuePtr, $i32)
            ),
            '__phpc_fmt_append_spec_snprintf' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $i8p, $sizeTp, $sizeT, $valuePtr, $i8)
            ),
            '__phpc_fmt_append_spec_flagged' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $i8p, $sizeTp, $sizeT, $valuePtr, $i8, $context->getTypeFromString('int1'), $i8)
            ),
            '__compiler_sprintf' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr, $i64, $valuePtr)
            ),
            '__compiler_printf' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $strPtr, $i64, $valuePtr)
            ),
            '__compiler_number_format' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $dbl, $i64, $strPtr, $strPtr)
            ),
            default => throw new \LogicException('Unknown format JIT helper: '.$name),
        };
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $dbl = $context->getTypeFromString('double');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $i8p->pointerType(0);
        $sizeT = $context->getTypeFromString('size_t');

        foreach (
            [
                ['strlen', $sizeT, [$i8p]],
                ['snprintf', $i32, [$i8p, $sizeT, $i8p]],
                ['strtod', $dbl, [$i8p, $i8pp]],
                ['strtoll', $i64, [$i8p, $i8pp, $i32]],
                ['isnan', $i32, [$dbl]],
                ['isinf', $i32, [$dbl]],
            ] as [$name, $ret, $params]
        ) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $void = $context->getTypeFromString('void');
        $i64 = $context->getTypeFromString('int64');
        $dbl = $context->getTypeFromString('double');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        foreach (
            [
                ['__string__init', $strPtr, [$i64, $i8p]],
                ['__string__strlen', $i64, [$strPtr]],
                ['__value__readLong', $i64, [$valuePtr]],
                ['__value__readDouble', $dbl, [$valuePtr]],
                ['__value__readString', $strPtr, [$valuePtr]],
                ['__phpc_ob_echo_substr', $void, [$i8p, $sizeT]],
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

    private static function stringData(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($str, $map['value']),
            $context->getTypeFromString('int8*')
        );
    }

    private static function stringLen(Context $context, Value $str): Value
    {
        $sizeT = $context->getTypeFromString('size_t');

        return $context->builder->trunc(
            $context->builder->call($context->lookupFunction('__string__strlen'), $str),
            $sizeT
        );
    }

    private static function valueTypeKind(Context $context, Value $valuePtr): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load($context->builder->structGep($valuePtr, $map['type']));

        return $context->builder->and(
            $context->builder->zExt($typeByte, $i32),
            $i32->constInt(127, false)
        );
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

    private static function emitPow10(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $decimals = $fn->getParam(0);
        $zeroI32 = $i32->constInt(0, false);
        $twenty = $i32->constInt(20, false);
        $oneI64 = $i64->constInt(1, false);
        $tenI64 = $i64->constInt(10, false);

        $negBb = $fn->appendBasicBlock('pow_neg');
        $clampBb = $fn->appendBasicBlock('pow_clamp');
        $loopHead = $fn->appendBasicBlock('pow_loop');
        $loopBody = $fn->appendBasicBlock('pow_body');
        $done = $fn->appendBasicBlock('pow_done');

        $isNeg = $context->builder->icmp(Builder::INT_SLT, $decimals, $zeroI32);
        $context->builder->branchIf($isNeg, $negBb, $clampBb);

        $context->builder->positionAtEnd($negBb);
        $context->builder->returnValue($oneI64);

        $context->builder->positionAtEnd($clampBb);
        $decSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $gtTwenty = $context->builder->icmp(Builder::INT_SGT, $decimals, $twenty);
        $context->builder->store($context->builder->select($gtTwenty, $twenty, $decimals), $decSlot);
        $scaleSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $iSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($oneI64, $scaleSlot);
        $context->builder->store($zeroI32, $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $dec = $context->builder->load($decSlot);
        $cont = $context->builder->icmp(Builder::INT_SLT, $i, $dec);
        $context->builder->branchIf($cont, $loopBody, $done);

        $context->builder->positionAtEnd($loopBody);
        $scale = $context->builder->load($scaleSlot);
        $context->builder->store($context->builder->mul($scale, $tenI64), $scaleSlot);
        $context->builder->store($context->builder->add($i, $i32->constInt(1, false)), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($context->builder->load($scaleSlot));
    }

    private static function emitRoundScaled(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i64 = $context->getTypeFromString('int64');
        $dbl = $context->getTypeFromString('double');
        $num = $fn->getParam(0);
        $scale = $fn->getParam(1);
        $half = $dbl->constReal(0.5);
        $negHalf = $dbl->constReal(-0.5);
        $zero = $dbl->constReal(0.0);

        $product = $context->builder->fmul($num, $context->builder->sitofp($scale, $dbl));
        $nonNeg = $context->builder->fcmp(Builder::REAL_OGE, $product, $zero);
        $posBb = $fn->appendBasicBlock('round_pos');
        $negBb = $fn->appendBasicBlock('round_neg');
        $context->builder->branchIf($nonNeg, $posBb, $negBb);

        $context->builder->positionAtEnd($posBb);
        $context->builder->returnValue($context->builder->fptosi($context->builder->fadd($product, $half), $i64));

        $context->builder->positionAtEnd($negBb);
        $context->builder->returnValue($context->builder->fptosi($context->builder->fadd($product, $negHalf), $i64));
    }

    private static function emitAppendChar(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $buf = $fn->getParam(0);
        $posPtr = $fn->getParam(1);
        $cap = $fn->getParam(2);
        $ch = $fn->getParam(3);

        $pos = $context->builder->load($posPtr);
        $fits = $context->builder->icmp(Builder::INT_ULT, $context->builder->add($pos, $one), $cap);
        $storeBb = $fn->appendBasicBlock('append_char_store');
        $done = $fn->appendBasicBlock('append_char_done');
        $context->builder->branchIf($fits, $storeBb, $done);

        $context->builder->positionAtEnd($storeBb);
        $context->builder->store($ch, $context->builder->inBoundsGEP($buf, $pos));
        $context->builder->store($context->builder->add($pos, $one), $posPtr);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
    }

    private static function emitAppendStr(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $buf = $fn->getParam(0);
        $posPtr = $fn->getParam(1);
        $cap = $fn->getParam(2);
        $src = $fn->getParam(3);
        $len = $fn->getParam(4);

        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $iSlot);
        $head = $fn->appendBasicBlock('append_str_head');
        $body = $fn->appendBasicBlock('append_str_body');
        $done = $fn->appendBasicBlock('append_str_done');
        $context->builder->branch($head);

        $appendChar = $context->lookupFunction('__phpc_fmt_append_char');

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $pos = $context->builder->load($posPtr);
        $cont = $context->builder->and(
            $context->builder->icmp(Builder::INT_ULT, $i, $len),
            $context->builder->icmp(Builder::INT_ULT, $context->builder->add($pos, $one), $cap)
        );
        $context->builder->branchIf($cont, $body, $done);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->inBoundsGEP($src, $i));
        $context->builder->call($appendChar, $buf, $posPtr, $cap, $ch);
        $context->builder->store($context->builder->add($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $context->builder->returnVoid();
    }

    private static function emitFormatUnsigned(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $strPtr = $context->getTypeFromString('__string__*');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $one = $sizeT->constInt(1, false);
        $tenI64 = $i64->constInt(10, false);
        $zeroI64 = $i64->constInt(0, false);
        $thirtyTwo = $sizeT->constInt(32, false);

        $valueIn = $fn->getParam(0);
        $buf = $fn->getParam(1);
        $cap = $fn->getParam(2);
        $thouSep = $fn->getParam(3);

        $valueSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $neg = $context->builder->icmp(Builder::INT_SLT, $valueIn, $zeroI64);
        $context->builder->store(
            $context->builder->select($neg, $context->builder->sub($zeroI64, $valueIn), $valueIn),
            $valueSlot
        );

        $isZero = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($valueSlot), $zeroI64);
        $zeroBb = $fn->appendBasicBlock('fmt_u_zero');
        $work = $fn->appendBasicBlock('fmt_u_work');
        $context->builder->branchIf($isZero, $zeroBb, $work);

        $appendChar = $context->lookupFunction('__phpc_fmt_append_char');
        $appendStr = $context->lookupFunction('__phpc_fmt_append_str');
        $posSlot = BasicBlockHelper::entryAlloca($context, $sizeT);

        $context->builder->positionAtEnd($zeroBb);
        $context->builder->store($sizeT->constInt(0, false), $posSlot);
        $context->builder->call(
            $appendChar,
            $buf,
            $posSlot,
            $cap,
            $i8->constInt(ord('0'), false)
        );
        $posZero = $context->builder->load($posSlot);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($buf, $posZero));
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($work);
        $digitsSlot = $context->builder->alloca($i8->arrayType(32), 1, 'fmt_u_digits');
        $digits = $context->builder->pointerCast($digitsSlot, $i8p);
        $digitLenSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $digitLenSlot);
        $context->builder->store($sizeT->constInt(0, false), $posSlot);

        $extractHead = $fn->appendBasicBlock('fmt_u_extract_head');
        $extractBody = $fn->appendBasicBlock('fmt_u_extract_body');
        $extractDone = $fn->appendBasicBlock('fmt_u_extract_done');
        $context->builder->branch($extractHead);

        $context->builder->positionAtEnd($extractHead);
        $val = $context->builder->load($valueSlot);
        $digitLen = $context->builder->load($digitLenSlot);
        $extractCont = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGT, $val, $zeroI64),
            $context->builder->icmp(Builder::INT_ULT, $digitLen, $thirtyTwo)
        );
        $context->builder->branchIf($extractCont, $extractBody, $extractDone);

        $context->builder->positionAtEnd($extractBody);
        $val = $context->builder->load($valueSlot);
        $digitLen = $context->builder->load($digitLenSlot);
        $rem = $context->builder->signedRem($val, $tenI64);
        $digit = $context->builder->trunc(
            $context->builder->add($rem, $i64->constInt(ord('0'), false)),
            $i8
        );
        $context->builder->store($digit, $context->builder->inBoundsGEP($digits, $digitLen));
        $context->builder->store($context->builder->add($digitLen, $one), $digitLenSlot);
        $context->builder->store($context->builder->signedDiv($val, $tenI64), $valueSlot);
        $context->builder->branch($extractHead);

        $context->builder->positionAtEnd($extractDone);
        $nullSep = $context->builder->icmp(Builder::INT_EQ, $thouSep, $strPtr->constNull());
        $sepDataSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $sepLenSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $hasSepBb = $fn->appendBasicBlock('fmt_u_has_sep');
        $noSepBb = $fn->appendBasicBlock('fmt_u_no_sep');
        $emitLoop = $fn->appendBasicBlock('fmt_u_emit_head');
        $context->builder->branchIf($nullSep, $noSepBb, $hasSepBb);

        $context->builder->positionAtEnd($noSepBb);
        $context->builder->store(self::literalCstr($context, ''), $sepDataSlot);
        $context->builder->store($sizeT->constInt(0, false), $sepLenSlot);
        $context->builder->branch($emitLoop);

        $context->builder->positionAtEnd($hasSepBb);
        $context->builder->store(self::stringData($context, $thouSep), $sepDataSlot);
        $context->builder->store(self::stringLen($context, $thouSep), $sepLenSlot);
        $context->builder->branch($emitLoop);

        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $digitLenFinal = $context->builder->load($digitLenSlot);
        $context->builder->store($digitLenFinal, $iSlot);
        $context->builder->positionAtEnd($emitLoop);
        $i = $context->builder->load($iSlot);
        $emitCont = $context->builder->icmp(Builder::INT_UGT, $i, $sizeT->constInt(0, false));
        $emitBody = $fn->appendBasicBlock('fmt_u_emit_body');
        $emitDone = $fn->appendBasicBlock('fmt_u_emit_done');
        $context->builder->branchIf($emitCont, $emitBody, $emitDone);

        $context->builder->positionAtEnd($emitBody);
        $digitLen = $context->builder->load($digitLenSlot);
        $fromLeft = $context->builder->sub($digitLen, $i);
        $sepLen = $context->builder->load($sepLenSlot);
        $modThree = $context->builder->unsigendRem($fromLeft, $sizeT->constInt(3, false));
        $needSep = $context->builder->and(
            $context->builder->icmp(Builder::INT_UGT, $sepLen, $sizeT->constInt(0, false)),
            $context->builder->and(
                $context->builder->icmp(Builder::INT_UGT, $fromLeft, $sizeT->constInt(0, false)),
                $context->builder->icmp(Builder::INT_EQ, $modThree, $sizeT->constInt(0, false))
            )
        );
        $sepBb = $fn->appendBasicBlock('fmt_u_sep');
        $digitBb = $fn->appendBasicBlock('fmt_u_digit');
        $context->builder->branchIf($needSep, $sepBb, $digitBb);

        $context->builder->positionAtEnd($sepBb);
        $context->builder->call(
            $appendStr,
            $buf,
            $posSlot,
            $cap,
            $context->builder->load($sepDataSlot),
            $context->builder->load($sepLenSlot)
        );
        $context->builder->branch($digitBb);

        $context->builder->positionAtEnd($digitBb);
        $idx = $context->builder->sub($i, $one);
        $context->builder->call(
            $appendChar,
            $buf,
            $posSlot,
            $cap,
            $context->builder->load($context->builder->inBoundsGEP($digits, $idx))
        );
        $context->builder->store($context->builder->sub($i, $one), $iSlot);
        $context->builder->branch($emitLoop);

        $context->builder->positionAtEnd($emitDone);
        $pos = $context->builder->load($posSlot);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($buf, $pos));
        $context->builder->returnVoid();
    }

    private static function emitFormatFraction(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);
        $tenI64 = $i64->constInt(10, false);
        $zeroI64 = $i64->constInt(0, false);

        $frac = $fn->getParam(0);
        $decimals = $fn->getParam(1);
        $buf = $fn->getParam(2);
        $cap = $fn->getParam(3);

        $posSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $scaleSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $iSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($sizeT->constInt(0, false), $posSlot);
        $context->builder->store(
            $context->builder->call(
                $context->lookupFunction('__phpc_fmt_pow10'),
                $context->builder->trunc($decimals, $i32)
            ),
            $scaleSlot
        );
        $context->builder->store($i32->constInt(0, false), $iSlot);

        $appendChar = $context->lookupFunction('__phpc_fmt_append_char');
        $loopHead = $fn->appendBasicBlock('fmt_f_head');
        $loopBody = $fn->appendBasicBlock('fmt_f_body');
        $loopDone = $fn->appendBasicBlock('fmt_f_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $decI32 = $context->builder->trunc($decimals, $i32);
        $cont = $context->builder->icmp(Builder::INT_SLT, $i, $decI32);
        $context->builder->branchIf($cont, $loopBody, $loopDone);

        $context->builder->positionAtEnd($loopBody);
        $scale = $context->builder->load($scaleSlot);
        $scaleZero = $context->builder->icmp(Builder::INT_EQ, $scale, $zeroI64);
        $scaleBreak = $fn->appendBasicBlock('fmt_f_break');
        $scaleCont = $fn->appendBasicBlock('fmt_f_cont');
        $context->builder->branchIf($scaleZero, $scaleBreak, $scaleCont);

        $context->builder->positionAtEnd($scaleCont);
        $newScale = $context->builder->signedDiv($scale, $tenI64);
        $context->builder->store($newScale, $scaleSlot);
        $digitVal = $context->builder->signedRem($context->builder->signedDiv($frac, $newScale), $tenI64);
        $digit = $context->builder->trunc(
            $context->builder->add($digitVal, $i64->constInt(ord('0'), false)),
            $i8
        );
        $context->builder->call($appendChar, $buf, $posSlot, $cap, $digit);
        $context->builder->store($context->builder->add($i, $i32->constInt(1, false)), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($scaleBreak);
        $context->builder->branch($loopDone);

        $context->builder->positionAtEnd($loopDone);
        $pos = $context->builder->load($posSlot);
        $padSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store(
            $context->builder->sub($context->builder->trunc($decimals, $i32), $context->builder->trunc($pos, $i32)),
            $padSlot
        );
        $padHead = $fn->appendBasicBlock('fmt_f_pad_head');
        $padBody = $fn->appendBasicBlock('fmt_f_pad_body');
        $padDone = $fn->appendBasicBlock('fmt_f_pad_done');
        $context->builder->branch($padHead);

        $context->builder->positionAtEnd($padHead);
        $pad = $context->builder->load($padSlot);
        $pos = $context->builder->load($posSlot);
        $padCont = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGT, $pad, $i32->constInt(0, false)),
            $context->builder->icmp(Builder::INT_ULT, $context->builder->add($pos, $one), $cap)
        );
        $context->builder->branchIf($padCont, $padBody, $padDone);

        $context->builder->positionAtEnd($padBody);
        $context->builder->call($appendChar, $buf, $posSlot, $cap, $i8->constInt(ord('0'), false));
        $context->builder->store($context->builder->sub($context->builder->load($padSlot), $i32->constInt(1, false)), $padSlot);
        $context->builder->branch($padHead);

        $context->builder->positionAtEnd($padDone);
        $pos = $context->builder->load($posSlot);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($buf, $pos));
        $context->builder->returnVoid();
    }

    private static function emitAppendDecimalLl(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $one = $sizeT->constInt(1, false);
        $tenI64 = $i64->constInt(10, false);
        $zeroI64 = $i64->constInt(0, false);
        $thirtyTwo = $sizeT->constInt(32, false);

        $buf = $fn->getParam(0);
        $posPtr = $fn->getParam(1);
        $cap = $fn->getParam(2);
        $valueIn = $fn->getParam(3);
        $appendChar = $context->lookupFunction('__phpc_fmt_append_char');

        $valueSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $negSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $neg = $context->builder->icmp(Builder::INT_SLT, $valueIn, $zeroI64);
        $context->builder->store($context->builder->select($neg, $i32->constInt(1, false), $i32->constInt(0, false)), $negSlot);
        $context->builder->store(
            $context->builder->select($neg, $context->builder->sub($zeroI64, $valueIn), $valueIn),
            $valueSlot
        );

        $isZero = $context->builder->icmp(Builder::INT_EQ, $context->builder->load($valueSlot), $zeroI64);
        $zeroBb = $fn->appendBasicBlock('dec_zero');
        $work = $fn->appendBasicBlock('dec_work');
        $context->builder->branchIf($isZero, $zeroBb, $work);

        $context->builder->positionAtEnd($zeroBb);
        $context->builder->call($appendChar, $buf, $posPtr, $cap, $i8->constInt(ord('0'), false));
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($work);
        $digitsSlot = $context->builder->alloca($i8->arrayType(32), 1, 'dec_digits');
        $digits = $context->builder->pointerCast($digitsSlot, $i8p);
        $digitLenSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $digitLenSlot);

        $extractHead = $fn->appendBasicBlock('dec_extract_head');
        $extractBody = $fn->appendBasicBlock('dec_extract_body');
        $extractDone = $fn->appendBasicBlock('dec_extract_done');
        $context->builder->branch($extractHead);

        $context->builder->positionAtEnd($extractHead);
        $val = $context->builder->load($valueSlot);
        $digitLen = $context->builder->load($digitLenSlot);
        $extractCont = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGT, $val, $zeroI64),
            $context->builder->icmp(Builder::INT_ULT, $digitLen, $thirtyTwo)
        );
        $context->builder->branchIf($extractCont, $extractBody, $extractDone);

        $context->builder->positionAtEnd($extractBody);
        $val = $context->builder->load($valueSlot);
        $digitLen = $context->builder->load($digitLenSlot);
        $rem = $context->builder->signedRem($val, $tenI64);
        $digit = $context->builder->trunc(
            $context->builder->add($rem, $i64->constInt(ord('0'), false)),
            $i8
        );
        $context->builder->store($digit, $context->builder->inBoundsGEP($digits, $digitLen));
        $context->builder->store($context->builder->add($digitLen, $one), $digitLenSlot);
        $context->builder->store($context->builder->signedDiv($val, $tenI64), $valueSlot);
        $context->builder->branch($extractHead);

        $context->builder->positionAtEnd($extractDone);
        $isNegative = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->load($negSlot),
            $i32->constInt(0, false)
        );
        $negBb = $fn->appendBasicBlock('dec_neg');
        $emit = $fn->appendBasicBlock('dec_emit_head');
        $context->builder->branchIf($isNegative, $negBb, $emit);

        $context->builder->positionAtEnd($negBb);
        $context->builder->call($appendChar, $buf, $posPtr, $cap, $i8->constInt(ord('-'), false));
        $context->builder->branch($emit);

        $digitLenSlot2 = $digitLenSlot;
        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->positionAtEnd($emit);
        $context->builder->store($context->builder->load($digitLenSlot2), $iSlot);
        $emitHead = $fn->appendBasicBlock('dec_emit_loop');
        $emitBody = $fn->appendBasicBlock('dec_emit_body');
        $emitDone = $fn->appendBasicBlock('dec_emit_done');
        $context->builder->branch($emitHead);

        $context->builder->positionAtEnd($emitHead);
        $i = $context->builder->load($iSlot);
        $emitCont = $context->builder->icmp(Builder::INT_UGT, $i, $sizeT->constInt(0, false));
        $context->builder->branchIf($emitCont, $emitBody, $emitDone);

        $context->builder->positionAtEnd($emitBody);
        $i = $context->builder->load($iSlot);
        $idx = $context->builder->sub($i, $one);
        $context->builder->call(
            $appendChar,
            $buf,
            $posPtr,
            $cap,
            $context->builder->load($context->builder->inBoundsGEP($digits, $idx))
        );
        $context->builder->store($context->builder->sub($i, $one), $iSlot);
        $context->builder->branch($emitHead);

        $context->builder->positionAtEnd($emitDone);
        $context->builder->returnVoid();
    }

    /**
     * php-src sprintf.c — %f non-finite prints NaN / INF / -INF (#10151).
     * Positions the builder at the returned block when the value is finite.
     */
    private static function branchUnlessSprintfNonfiniteFloat(
        Context $context,
        LlvmFunction $fn,
        Value $buf,
        Value $posPtr,
        Value $cap,
        Value $num,
        string $prefix
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $dbl = $context->getTypeFromString('double');
        $sizeT = $context->getTypeFromString('size_t');

        $appendStr = $context->lookupFunction('__phpc_fmt_append_str');

        $isNan = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('isnan'), $num),
            $i32->constInt(0, false)
        );
        $nanBb = $fn->appendBasicBlock($prefix.'_nan');
        $checkInf = $fn->appendBasicBlock($prefix.'_check_inf');
        $context->builder->branchIf($isNan, $nanBb, $checkInf);

        $context->builder->positionAtEnd($nanBb);
        $context->builder->call(
            $appendStr,
            $buf,
            $posPtr,
            $cap,
            self::literalCstr($context, 'NaN'),
            $sizeT->constInt(3, false)
        );
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($checkInf);
        $isInf = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('isinf'), $num),
            $i32->constInt(0, false)
        );
        $infBb = $fn->appendBasicBlock($prefix.'_inf');
        $work = $fn->appendBasicBlock($prefix.'_finite');
        $context->builder->branchIf($isInf, $infBb, $work);

        $context->builder->positionAtEnd($infBb);
        $negInf = $context->builder->fcmp(Builder::REAL_OLT, $num, $dbl->constReal(0.0));
        $negBb = $fn->appendBasicBlock($prefix.'_neg_inf');
        $posInfBb = $fn->appendBasicBlock($prefix.'_pos_inf');
        $context->builder->branchIf($negInf, $negBb, $posInfBb);

        $context->builder->positionAtEnd($negBb);
        $context->builder->call(
            $appendStr,
            $buf,
            $posPtr,
            $cap,
            self::literalCstr($context, '-INF'),
            $sizeT->constInt(4, false)
        );
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($posInfBb);
        $context->builder->call(
            $appendStr,
            $buf,
            $posPtr,
            $cap,
            self::literalCstr($context, 'INF'),
            $sizeT->constInt(3, false)
        );
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($work);
    }

    private static function emitAppendFloat(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');

        $buf = $fn->getParam(0);
        $posPtr = $fn->getParam(1);
        $cap = $fn->getParam(2);
        $num = $fn->getParam(3);

        self::branchUnlessSprintfNonfiniteFloat($context, $fn, $buf, $posPtr, $cap, $num, 'flt');

        $appendChar = $context->lookupFunction('__phpc_fmt_append_char');
        $appendStr = $context->lookupFunction('__phpc_fmt_append_str');
        $formatUnsigned = $context->lookupFunction('__phpc_fmt_format_unsigned');
        $formatFraction = $context->lookupFunction('__phpc_fmt_format_fraction');
        $roundScaled = $context->lookupFunction('__phpc_fmt_round_scaled');
        $pow10 = $context->lookupFunction('__phpc_fmt_pow10');
        $strlenFn = $context->lookupFunction('strlen');

        $scale = $context->builder->call($pow10, $context->getTypeFromString('int32')->constInt(6, false));
        $scaledIn = $context->builder->call($roundScaled, $num, $scale);
        $zeroI64 = $i64->constInt(0, false);
        $neg = $context->builder->icmp(Builder::INT_SLT, $scaledIn, $zeroI64);
        $negBb = $fn->appendBasicBlock('flt_neg');
        $afterNeg = $fn->appendBasicBlock('flt_after_neg');
        $context->builder->branchIf($neg, $negBb, $afterNeg);

        $scaledSlot = BasicBlockHelper::entryAlloca($context, $i64);

        $context->builder->positionAtEnd($negBb);
        $context->builder->call($appendChar, $buf, $posPtr, $cap, $i8->constInt(ord('-'), false));
        $context->builder->store($context->builder->sub($zeroI64, $scaledIn), $scaledSlot);
        $context->builder->branch($afterNeg);

        $context->builder->positionAtEnd($afterNeg);
        $context->builder->store(
            $context->builder->select($neg, $context->builder->sub($zeroI64, $scaledIn), $scaledIn),
            $scaledSlot
        );

        $scaled = $context->builder->load($scaledSlot);
        $intPart = $context->builder->signedDiv($scaled, $scale);
        $fracPart = $context->builder->signedRem($scaled, $scale);

        $intBufSlot = $context->builder->alloca($i8->arrayType(64), 1, 'flt_int_buf');
        $intBuf = $context->builder->pointerCast($intBufSlot, $i8p);
        $fracBufSlot = $context->builder->alloca($i8->arrayType(32), 1, 'flt_frac_buf');
        $fracBuf = $context->builder->pointerCast($fracBufSlot, $i8p);

        $context->builder->call(
            $formatUnsigned,
            $intPart,
            $intBuf,
            $sizeT->constInt(64, false),
            $strPtr->constNull()
        );
        $intLen = $context->builder->call($strlenFn, $intBuf);
        $context->builder->call($appendStr, $buf, $posPtr, $cap, $intBuf, $intLen);
        $context->builder->call($appendChar, $buf, $posPtr, $cap, $i8->constInt(ord('.'), false));
        $context->builder->call(
            $formatFraction,
            $fracPart,
            $i64->constInt(6, false),
            $fracBuf,
            $sizeT->constInt(32, false)
        );
        $fracLen = $context->builder->call($strlenFn, $fracBuf);
        $context->builder->call($appendStr, $buf, $posPtr, $cap, $fracBuf, $fracLen);
        $context->builder->returnVoid();
    }

    private static function emitAppendFloatPrec(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');

        $buf = $fn->getParam(0);
        $posPtr = $fn->getParam(1);
        $cap = $fn->getParam(2);
        $num = $fn->getParam(3);
        $prec = $fn->getParam(4);

        self::branchUnlessSprintfNonfiniteFloat($context, $fn, $buf, $posPtr, $cap, $num, 'flt_prec');

        $appendChar = $context->lookupFunction('__phpc_fmt_append_char');
        $appendStr = $context->lookupFunction('__phpc_fmt_append_str');
        $formatUnsigned = $context->lookupFunction('__phpc_fmt_format_unsigned');
        $formatFraction = $context->lookupFunction('__phpc_fmt_format_fraction');
        $roundScaled = $context->lookupFunction('__phpc_fmt_round_scaled');
        $pow10 = $context->lookupFunction('__phpc_fmt_pow10');
        $strlenFn = $context->lookupFunction('strlen');

        $scale = $context->builder->call($pow10, $prec);
        $scaledIn = $context->builder->call($roundScaled, $num, $scale);
        $zeroI64 = $i64->constInt(0, false);
        $neg = $context->builder->icmp(Builder::INT_SLT, $scaledIn, $zeroI64);
        $negBb = $fn->appendBasicBlock('flt_prec_neg');
        $afterNeg = $fn->appendBasicBlock('flt_prec_after_neg');
        $context->builder->branchIf($neg, $negBb, $afterNeg);

        $scaledSlot = BasicBlockHelper::entryAlloca($context, $i64);

        $context->builder->positionAtEnd($negBb);
        $context->builder->call($appendChar, $buf, $posPtr, $cap, $i8->constInt(ord('-'), false));
        $context->builder->store($context->builder->sub($zeroI64, $scaledIn), $scaledSlot);
        $context->builder->branch($afterNeg);

        $context->builder->positionAtEnd($afterNeg);
        $context->builder->store(
            $context->builder->select($neg, $context->builder->sub($zeroI64, $scaledIn), $scaledIn),
            $scaledSlot
        );

        $scaled = $context->builder->load($scaledSlot);
        $intPart = $context->builder->signedDiv($scaled, $scale);
        $fracPart = $context->builder->signedRem($scaled, $scale);

        $intBufSlot = $context->builder->alloca($i8->arrayType(64), 1, 'flt_prec_int_buf');
        $intBuf = $context->builder->pointerCast($intBufSlot, $i8p);
        $fracBufSlot = $context->builder->alloca($i8->arrayType(32), 1, 'flt_prec_frac_buf');
        $fracBuf = $context->builder->pointerCast($fracBufSlot, $i8p);

        $context->builder->call(
            $formatUnsigned,
            $intPart,
            $intBuf,
            $sizeT->constInt(64, false),
            $strPtr->constNull()
        );
        $intLen = $context->builder->call($strlenFn, $intBuf);
        $context->builder->call($appendStr, $buf, $posPtr, $cap, $intBuf, $intLen);
        $context->builder->call($appendChar, $buf, $posPtr, $cap, $i8->constInt(ord('.'), false));
        $context->builder->call(
            $formatFraction,
            $fracPart,
            $context->builder->zExt($prec, $i64),
            $fracBuf,
            $sizeT->constInt(32, false)
        );
        $fracLen = $context->builder->call($strlenFn, $fracBuf);
        $context->builder->call($appendStr, $buf, $posPtr, $cap, $fracBuf, $fracLen);
        $context->builder->returnVoid();
    }

    /** %f with explicit precision (issue #3631 positional %.Nf). */
    private static function emitAppendSpecFloatPrec(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $dbl = $context->getTypeFromString('double');
        $i8p = $context->getTypeFromString('int8*');
        $i8pp = $i8p->pointerType(0);

        $buf = $fn->getParam(0);
        $posPtr = $fn->getParam(1);
        $cap = $fn->getParam(2);
        $valuePtr = $fn->getParam(3);
        $prec = $fn->getParam(4);

        $appendFloatPrec = $context->lookupFunction('__phpc_fmt_append_float_prec');
        $readLong = $context->lookupFunction('__value__readLong');
        $readDouble = $context->lookupFunction('__value__readDouble');
        $readString = $context->lookupFunction('__value__readString');
        $strtodFn = $context->lookupFunction('strtod');
        $nullPtr = $i8pp->constNull();
        $kind = self::valueTypeKind($context, $valuePtr);

        $fDefault = $fn->appendBasicBlock('spec_fp_default');
        $fDouble = $fn->appendBasicBlock('spec_fp_double');
        $fLong = $fn->appendBasicBlock('spec_fp_long');
        $fNull = $fn->appendBasicBlock('spec_fp_null');
        $fString = $fn->appendBasicBlock('spec_fp_string');
        $fDone = $fn->appendBasicBlock('spec_fp_done');
        $fSwitch = $context->builder->branchSwitch($kind, $fDefault, 5);
        $fSwitch->addCase($i32->constInt(self::PHPC_TYPE_DOUBLE, false), $fDouble);
        $fSwitch->addCase($i32->constInt(self::PHPC_TYPE_LONG, false), $fLong);
        $fSwitch->addCase($i32->constInt(self::PHPC_TYPE_BOOL, false), $fLong);
        $fSwitch->addCase($i32->constInt(self::PHPC_TYPE_NULL, false), $fNull);
        $fSwitch->addCase($i32->constInt(self::PHPC_TYPE_STRING, false), $fString);

        $context->builder->positionAtEnd($fDouble);
        $context->builder->call(
            $appendFloatPrec,
            $buf,
            $posPtr,
            $cap,
            $context->builder->call($readDouble, $valuePtr),
            $prec
        );
        $context->builder->branch($fDone);

        $context->builder->positionAtEnd($fLong);
        $context->builder->call(
            $appendFloatPrec,
            $buf,
            $posPtr,
            $cap,
            $context->builder->sitofp($context->builder->call($readLong, $valuePtr), $dbl),
            $prec
        );
        $context->builder->branch($fDone);

        $context->builder->positionAtEnd($fNull);
        $context->builder->call(
            $appendFloatPrec,
            $buf,
            $posPtr,
            $cap,
            $dbl->constReal(0.0),
            $prec
        );
        $context->builder->branch($fDone);

        $context->builder->positionAtEnd($fString);
        $fStr = $context->builder->call($readString, $valuePtr);
        $parsedF = $context->builder->call(
            $strtodFn,
            self::stringData($context, $fStr),
            $nullPtr
        );
        $context->builder->call($appendFloatPrec, $buf, $posPtr, $cap, $parsedF, $prec);
        $context->builder->branch($fDone);

        $context->builder->positionAtEnd($fDefault);
        $context->builder->branch($fDone);

        $context->builder->positionAtEnd($fDone);
        $context->builder->returnVoid();
    }

    private static function emitAppendSpec(Context $context, LlvmFunction $fn): void
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
        $strPtr = $context->getTypeFromString('__string__*');

        $buf = $fn->getParam(0);
        $posPtr = $fn->getParam(1);
        $cap = $fn->getParam(2);
        $valuePtr = $fn->getParam(3);
        $spec = $fn->getParam(4);

        $appendStr = $context->lookupFunction('__phpc_fmt_append_str');
        $appendChar = $context->lookupFunction('__phpc_fmt_append_char');
        $appendDecimal = $context->lookupFunction('__phpc_fmt_append_decimal_ll');
        $appendFloat = $context->lookupFunction('__phpc_fmt_append_float');
        $readLong = $context->lookupFunction('__value__readLong');
        $readDouble = $context->lookupFunction('__value__readDouble');
        $readString = $context->lookupFunction('__value__readString');
        $strtollFn = $context->lookupFunction('strtoll');
        $strtodFn = $context->lookupFunction('strtod');
        $nullPtr = $i8pp->constNull();

        $kind = self::valueTypeKind($context, $valuePtr);
        $spec32 = $context->builder->zExt($spec, $i32);
        $defaultBb = $fn->appendBasicBlock('spec_default');
        $caseS = $fn->appendBasicBlock('spec_s');
        $caseD = $fn->appendBasicBlock('spec_d');
        $caseF = $fn->appendBasicBlock('spec_f');
        $caseSnprintf = $fn->appendBasicBlock('spec_snprintf');
        $switch = $context->builder->branchSwitch($spec32, $defaultBb, 4);
        $switch->addCase($i32->constInt(ord('s'), false), $caseS);
        $switch->addCase($i32->constInt(ord('d'), false), $caseD);
        $switch->addCase($i32->constInt(ord('f'), false), $caseF);
        foreach (['b', 'x', 'X', 'o', 'u', 'c', 'e', 'E', 'g', 'G', 'a', 'A'] as $snprintfSpec) {
            $switch->addCase($i32->constInt(ord($snprintfSpec), false), $caseSnprintf);
        }

        $context->builder->positionAtEnd($defaultBb);
        $context->builder->returnVoid();

        $appendSnprintf = $context->lookupFunction('__phpc_fmt_append_spec_snprintf');
        $context->builder->positionAtEnd($caseSnprintf);
        $context->builder->call($appendSnprintf, $buf, $posPtr, $cap, $valuePtr, $spec);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($caseS);
        $sDefault = $fn->appendBasicBlock('spec_s_default');
        $sString = $fn->appendBasicBlock('spec_s_string');
        $sLong = $fn->appendBasicBlock('spec_s_long');
        $sDouble = $fn->appendBasicBlock('spec_s_double');
        $sNull = $fn->appendBasicBlock('spec_s_null');
        $sDone = $fn->appendBasicBlock('spec_s_done');
        $sSwitch = $context->builder->branchSwitch($kind, $sDefault, 5);
        $sSwitch->addCase($i32->constInt(self::PHPC_TYPE_STRING, false), $sString);
        $sSwitch->addCase($i32->constInt(self::PHPC_TYPE_LONG, false), $sLong);
        $sSwitch->addCase($i32->constInt(self::PHPC_TYPE_BOOL, false), $sLong);
        $sSwitch->addCase($i32->constInt(self::PHPC_TYPE_DOUBLE, false), $sDouble);
        $sSwitch->addCase($i32->constInt(self::PHPC_TYPE_NULL, false), $sNull);

        $context->builder->positionAtEnd($sString);
        $sVal = $context->builder->call($readString, $valuePtr);
        $context->builder->call(
            $appendStr,
            $buf,
            $posPtr,
            $cap,
            self::stringData($context, $sVal),
            self::stringLen($context, $sVal)
        );
        $context->builder->branch($sDone);

        $context->builder->positionAtEnd($sLong);
        $context->builder->call($appendDecimal, $buf, $posPtr, $cap, $context->builder->call($readLong, $valuePtr));
        $context->builder->branch($sDone);

        $context->builder->positionAtEnd($sDouble);
        $context->builder->call($appendFloat, $buf, $posPtr, $cap, $context->builder->call($readDouble, $valuePtr));
        $context->builder->branch($sDone);

        $context->builder->positionAtEnd($sNull);
        $context->builder->branch($sDone);

        $context->builder->positionAtEnd($sDefault);
        $context->builder->branch($sDone);

        $context->builder->positionAtEnd($sDone);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($caseD);
        $dDefault = $fn->appendBasicBlock('spec_d_default');
        $dLong = $fn->appendBasicBlock('spec_d_long');
        $dDouble = $fn->appendBasicBlock('spec_d_double');
        $dNull = $fn->appendBasicBlock('spec_d_null');
        $dString = $fn->appendBasicBlock('spec_d_string');
        $dDone = $fn->appendBasicBlock('spec_d_done');
        $dSwitch = $context->builder->branchSwitch($kind, $dDefault, 5);
        $dSwitch->addCase($i32->constInt(self::PHPC_TYPE_LONG, false), $dLong);
        $dSwitch->addCase($i32->constInt(self::PHPC_TYPE_BOOL, false), $dLong);
        $dSwitch->addCase($i32->constInt(self::PHPC_TYPE_DOUBLE, false), $dDouble);
        $dSwitch->addCase($i32->constInt(self::PHPC_TYPE_NULL, false), $dNull);
        $dSwitch->addCase($i32->constInt(self::PHPC_TYPE_STRING, false), $dString);

        $context->builder->positionAtEnd($dLong);
        $context->builder->call($appendDecimal, $buf, $posPtr, $cap, $context->builder->call($readLong, $valuePtr));
        $context->builder->branch($dDone);

        $context->builder->positionAtEnd($dDouble);
        $context->builder->call(
            $appendDecimal,
            $buf,
            $posPtr,
            $cap,
            $context->builder->fptosi($context->builder->call($readDouble, $valuePtr), $i64)
        );
        $context->builder->branch($dDone);

        $context->builder->positionAtEnd($dNull);
        $context->builder->call($appendChar, $buf, $posPtr, $cap, $i8->constInt(ord('0'), false));
        $context->builder->branch($dDone);

        $context->builder->positionAtEnd($dString);
        $dStr = $context->builder->call($readString, $valuePtr);
        $parsed = $context->builder->call(
            $strtollFn,
            self::stringData($context, $dStr),
            $nullPtr,
            $i32->constInt(10, false)
        );
        $context->builder->call($appendDecimal, $buf, $posPtr, $cap, $parsed);
        $context->builder->branch($dDone);

        $context->builder->positionAtEnd($dDefault);
        $context->builder->branch($dDone);

        $context->builder->positionAtEnd($dDone);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($caseF);
        $fDefault = $fn->appendBasicBlock('spec_f_default');
        $fDouble = $fn->appendBasicBlock('spec_f_double');
        $fLong = $fn->appendBasicBlock('spec_f_long');
        $fNull = $fn->appendBasicBlock('spec_f_null');
        $fString = $fn->appendBasicBlock('spec_f_string');
        $fDone = $fn->appendBasicBlock('spec_f_done');
        $fSwitch = $context->builder->branchSwitch($kind, $fDefault, 5);
        $fSwitch->addCase($i32->constInt(self::PHPC_TYPE_DOUBLE, false), $fDouble);
        $fSwitch->addCase($i32->constInt(self::PHPC_TYPE_LONG, false), $fLong);
        $fSwitch->addCase($i32->constInt(self::PHPC_TYPE_BOOL, false), $fLong);
        $fSwitch->addCase($i32->constInt(self::PHPC_TYPE_NULL, false), $fNull);
        $fSwitch->addCase($i32->constInt(self::PHPC_TYPE_STRING, false), $fString);

        $context->builder->positionAtEnd($fDouble);
        $context->builder->call($appendFloat, $buf, $posPtr, $cap, $context->builder->call($readDouble, $valuePtr));
        $context->builder->branch($fDone);

        $context->builder->positionAtEnd($fLong);
        $context->builder->call(
            $appendFloat,
            $buf,
            $posPtr,
            $cap,
            $context->builder->sitofp($context->builder->call($readLong, $valuePtr), $dbl)
        );
        $context->builder->branch($fDone);

        $context->builder->positionAtEnd($fNull);
        $context->builder->call($appendChar, $buf, $posPtr, $cap, $i8->constInt(ord('0'), false));
        $context->builder->call($appendChar, $buf, $posPtr, $cap, $i8->constInt(ord('.'), false));
        $context->builder->call($appendChar, $buf, $posPtr, $cap, $i8->constInt(ord('0'), false));
        $context->builder->branch($fDone);

        $context->builder->positionAtEnd($fString);
        $fStr = $context->builder->call($readString, $valuePtr);
        $parsedF = $context->builder->call(
            $strtodFn,
            self::stringData($context, $fStr),
            $nullPtr
        );
        $context->builder->call($appendFloat, $buf, $posPtr, $cap, $parsedF);
        $context->builder->branch($fDone);

        $context->builder->positionAtEnd($fDefault);
        $context->builder->branch($fDone);

        $context->builder->positionAtEnd($fDone);
        $context->builder->returnVoid();
    }

    /**
     * Extended sprintf conversions via libc snprintf (%b %x %o %u %c %e %g, issue #4156).
     */
    private static function emitAppendSpecSnprintf(Context $context, LlvmFunction $fn): void
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

        $buf = $fn->getParam(0);
        $posPtr = $fn->getParam(1);
        $cap = $fn->getParam(2);
        $valuePtr = $fn->getParam(3);
        $spec = $fn->getParam(4);

        $appendStr = $context->lookupFunction('__phpc_fmt_append_str');
        $snprintfFn = $context->lookupFunction('snprintf');
        $strlenFn = $context->lookupFunction('strlen');
        $readLong = $context->lookupFunction('__value__readLong');
        $readDouble = $context->lookupFunction('__value__readDouble');
        $strtollFn = $context->lookupFunction('strtoll');
        $strtodFn = $context->lookupFunction('strtod');
        $nullPtr = $i8pp->constNull();
        $tmpCap = $sizeT->constInt(64, false);
        $tmpSlot = $context->builder->alloca($i8->arrayType(64), 1, 'spec_snprintf_tmp');
        $tmp = $context->builder->pointerCast($tmpSlot, $i8p);

        $spec32 = $context->builder->zExt($spec, $i32);
        $isFloatSpec = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $spec32, $i32->constInt(ord('e'), false)),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $spec32, $i32->constInt(ord('E'), false)),
                $context->builder->or(
                    $context->builder->icmp(Builder::INT_EQ, $spec32, $i32->constInt(ord('g'), false)),
                    $context->builder->or(
                        $context->builder->icmp(Builder::INT_EQ, $spec32, $i32->constInt(ord('G'), false)),
                        $context->builder->or(
                            $context->builder->icmp(Builder::INT_EQ, $spec32, $i32->constInt(ord('a'), false)),
                            $context->builder->icmp(Builder::INT_EQ, $spec32, $i32->constInt(ord('A'), false))
                        )
                    )
                )
            )
        );

        $kind = self::valueTypeKind($context, $valuePtr);
        $intValSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $floatValSlot = BasicBlockHelper::entryAlloca($context, $dbl);
        $zeroDbl = $dbl->constReal(0.0);

        $intPath = $fn->appendBasicBlock('spec_snprintf_int');
        $floatPath = $fn->appendBasicBlock('spec_snprintf_float');
        $done = $fn->appendBasicBlock('spec_snprintf_done');
        $context->builder->branchIf($isFloatSpec, $floatPath, $intPath);

        $context->builder->positionAtEnd($intPath);
        $fmtBufSlot = $context->builder->alloca($i8->arrayType(8), 1, 'spec_snprintf_fmt');
        $fmtPtr = $context->builder->pointerCast($fmtBufSlot, $i8p);
        $context->builder->store($i8->constInt(ord('%'), false), $context->builder->inBoundsGEP($fmtPtr, $i64->constInt(0, false)));
        $context->builder->store($spec, $context->builder->inBoundsGEP($fmtPtr, $i64->constInt(1, false)));
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($fmtPtr, $i64->constInt(2, false)));

        $iDefault = $fn->appendBasicBlock('spec_snprintf_i_default');
        $iLong = $fn->appendBasicBlock('spec_snprintf_i_long');
        $iDouble = $fn->appendBasicBlock('spec_snprintf_i_double');
        $iNull = $fn->appendBasicBlock('spec_snprintf_i_null');
        $iString = $fn->appendBasicBlock('spec_snprintf_i_string');
        $iEmit = $fn->appendBasicBlock('spec_snprintf_i_emit');
        $iSwitch = $context->builder->branchSwitch($kind, $iDefault, 5);
        $iSwitch->addCase($i32->constInt(self::PHPC_TYPE_LONG, false), $iLong);
        $iSwitch->addCase($i32->constInt(self::PHPC_TYPE_BOOL, false), $iLong);
        $iSwitch->addCase($i32->constInt(self::PHPC_TYPE_DOUBLE, false), $iDouble);
        $iSwitch->addCase($i32->constInt(self::PHPC_TYPE_NULL, false), $iNull);
        $iSwitch->addCase($i32->constInt(self::PHPC_TYPE_STRING, false), $iString);

        $context->builder->positionAtEnd($iLong);
        $context->builder->store($context->builder->call($readLong, $valuePtr), $intValSlot);
        $context->builder->branch($iEmit);

        $context->builder->positionAtEnd($iDouble);
        $context->builder->store(
            $context->builder->fptosi($context->builder->call($readDouble, $valuePtr), $i64),
            $intValSlot
        );
        $context->builder->branch($iEmit);

        $context->builder->positionAtEnd($iNull);
        $context->builder->store($i64->constInt(0, false), $intValSlot);
        $context->builder->branch($iEmit);

        $context->builder->positionAtEnd($iString);
        $iStr = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $parsed = $context->builder->call(
            $strtollFn,
            self::stringData($context, $iStr),
            $nullPtr,
            $i32->constInt(10, false)
        );
        $context->builder->store($parsed, $intValSlot);
        $context->builder->branch($iEmit);

        $context->builder->positionAtEnd($iDefault);
        $context->builder->store($i64->constInt(0, false), $intValSlot);
        $context->builder->branch($iEmit);

        $context->builder->positionAtEnd($iEmit);
        $context->builder->call(
            $snprintfFn,
            $tmp,
            $tmpCap,
            $fmtPtr,
            $context->builder->load($intValSlot)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($floatPath);
        $fmtBufSlotF = $context->builder->alloca($i8->arrayType(8), 1, 'spec_snprintf_fmt_f');
        $fmtPtrF = $context->builder->pointerCast($fmtBufSlotF, $i8p);
        $context->builder->store($i8->constInt(ord('%'), false), $context->builder->inBoundsGEP($fmtPtrF, $i64->constInt(0, false)));
        $context->builder->store($spec, $context->builder->inBoundsGEP($fmtPtrF, $i64->constInt(1, false)));
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($fmtPtrF, $i64->constInt(2, false)));

        $fDefault = $fn->appendBasicBlock('spec_snprintf_f_default');
        $fDouble = $fn->appendBasicBlock('spec_snprintf_f_double');
        $fLong = $fn->appendBasicBlock('spec_snprintf_f_long');
        $fNull = $fn->appendBasicBlock('spec_snprintf_f_null');
        $fString = $fn->appendBasicBlock('spec_snprintf_f_string');
        $fEmit = $fn->appendBasicBlock('spec_snprintf_f_emit');
        $fSwitch = $context->builder->branchSwitch($kind, $fDefault, 5);
        $fSwitch->addCase($i32->constInt(self::PHPC_TYPE_DOUBLE, false), $fDouble);
        $fSwitch->addCase($i32->constInt(self::PHPC_TYPE_LONG, false), $fLong);
        $fSwitch->addCase($i32->constInt(self::PHPC_TYPE_BOOL, false), $fLong);
        $fSwitch->addCase($i32->constInt(self::PHPC_TYPE_NULL, false), $fNull);
        $fSwitch->addCase($i32->constInt(self::PHPC_TYPE_STRING, false), $fString);

        $context->builder->positionAtEnd($fDouble);
        $context->builder->store($context->builder->call($readDouble, $valuePtr), $floatValSlot);
        $context->builder->branch($fEmit);

        $context->builder->positionAtEnd($fLong);
        $context->builder->store(
            $context->builder->sitofp($context->builder->call($readLong, $valuePtr), $dbl),
            $floatValSlot
        );
        $context->builder->branch($fEmit);

        $context->builder->positionAtEnd($fNull);
        $context->builder->store($zeroDbl, $floatValSlot);
        $context->builder->branch($fEmit);

        $context->builder->positionAtEnd($fString);
        $fStr = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $parsedF = $context->builder->call(
            $strtodFn,
            self::stringData($context, $fStr),
            $nullPtr
        );
        $context->builder->store($parsedF, $floatValSlot);
        $context->builder->branch($fEmit);

        $context->builder->positionAtEnd($fDefault);
        $context->builder->store($zeroDbl, $floatValSlot);
        $context->builder->branch($fEmit);

        $context->builder->positionAtEnd($fEmit);
        $context->builder->call(
            $snprintfFn,
            $tmp,
            $tmpCap,
            $fmtPtrF,
            $context->builder->load($floatValSlot)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $outLen = $context->builder->call($strlenFn, $tmp);
        $context->builder->call($appendStr, $buf, $posPtr, $cap, $tmp, $outLen);
        $context->builder->returnVoid();
    }

    /**
     * snprintf with SIGN/ALTFORM flags (php-src sprintf.c; issue #9058).
     */
    private static function emitAppendSpecFlagged(Context $context, LlvmFunction $fn): void
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

        $buf = $fn->getParam(0);
        $posPtr = $fn->getParam(1);
        $cap = $fn->getParam(2);
        $valuePtr = $fn->getParam(3);
        $signFlag = $fn->getParam(4);
        $altForm = $fn->getParam(5);
        $spec = $fn->getParam(6);

        $appendStr = $context->lookupFunction('__phpc_fmt_append_str');
        $snprintfFn = $context->lookupFunction('snprintf');
        $strlenFn = $context->lookupFunction('strlen');
        $readLong = $context->lookupFunction('__value__readLong');
        $readDouble = $context->lookupFunction('__value__readDouble');
        $strtollFn = $context->lookupFunction('strtoll');
        $strtodFn = $context->lookupFunction('strtod');
        $nullPtr = $i8pp->constNull();
        $tmpCap = $sizeT->constInt(64, false);
        $tmpSlot = $context->builder->alloca($i8->arrayType(64), 1, 'spec_flagged_tmp');
        $tmp = $context->builder->pointerCast($tmpSlot, $i8p);
        $fmtBufSlot = $context->builder->alloca($i8->arrayType(8), 1, 'spec_flagged_fmt');
        $fmtPtr = $context->builder->pointerCast($fmtBufSlot, $i8p);
        $fmtIdxSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $context->builder->store($zeroI64, $fmtIdxSlot);

        $storeFmtChar = static function (Value $ch) use ($context, $fmtPtr, $fmtIdxSlot, $oneI64): void {
            $idx = $context->builder->load($fmtIdxSlot);
            $context->builder->store($ch, $context->builder->inBoundsGEP($fmtPtr, $idx));
            $context->builder->store($context->builder->add($idx, $oneI64), $fmtIdxSlot);
        };

        $storeFmtChar($i8->constInt(ord('%'), false));
        $hasAlt = $fn->appendBasicBlock('spec_flagged_alt');
        $afterAlt = $fn->appendBasicBlock('spec_flagged_after_alt');
        $context->builder->branchIf($altForm, $hasAlt, $afterAlt);
        $context->builder->positionAtEnd($hasAlt);
        $storeFmtChar($i8->constInt(ord('#'), false));
        $context->builder->branch($afterAlt);
        $context->builder->positionAtEnd($afterAlt);
        $hasSign = $context->builder->icmp(Builder::INT_NE, $signFlag, $i8->constInt(0, false));
        $signBb = $fn->appendBasicBlock('spec_flagged_sign');
        $afterSign = $fn->appendBasicBlock('spec_flagged_after_sign');
        $context->builder->branchIf($hasSign, $signBb, $afterSign);
        $context->builder->positionAtEnd($signBb);
        $storeFmtChar($signFlag);
        $context->builder->branch($afterSign);
        $context->builder->positionAtEnd($afterSign);
        $storeFmtChar($spec);
        $storeFmtChar($i8->constInt(0, false));

        $spec32 = $context->builder->zExt($spec, $i32);
        $isFloatSpec = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $spec32, $i32->constInt(ord('e'), false)),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $spec32, $i32->constInt(ord('E'), false)),
                $context->builder->or(
                    $context->builder->icmp(Builder::INT_EQ, $spec32, $i32->constInt(ord('f'), false)),
                    $context->builder->or(
                        $context->builder->icmp(Builder::INT_EQ, $spec32, $i32->constInt(ord('g'), false)),
                        $context->builder->or(
                            $context->builder->icmp(Builder::INT_EQ, $spec32, $i32->constInt(ord('G'), false)),
                            $context->builder->or(
                                $context->builder->icmp(Builder::INT_EQ, $spec32, $i32->constInt(ord('a'), false)),
                                $context->builder->icmp(Builder::INT_EQ, $spec32, $i32->constInt(ord('A'), false))
                            )
                        )
                    )
                )
            )
        );

        $kind = self::valueTypeKind($context, $valuePtr);
        $intValSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $floatValSlot = BasicBlockHelper::entryAlloca($context, $dbl);
        $zeroDbl = $dbl->constReal(0.0);

        $intPath = $fn->appendBasicBlock('spec_flagged_int');
        $floatPath = $fn->appendBasicBlock('spec_flagged_float');
        $done = $fn->appendBasicBlock('spec_flagged_done');
        $context->builder->branchIf($isFloatSpec, $floatPath, $intPath);

        $context->builder->positionAtEnd($intPath);
        $iDefault = $fn->appendBasicBlock('spec_flagged_i_default');
        $iLong = $fn->appendBasicBlock('spec_flagged_i_long');
        $iDouble = $fn->appendBasicBlock('spec_flagged_i_double');
        $iNull = $fn->appendBasicBlock('spec_flagged_i_null');
        $iString = $fn->appendBasicBlock('spec_flagged_i_string');
        $iEmit = $fn->appendBasicBlock('spec_flagged_i_emit');
        $iSwitch = $context->builder->branchSwitch($kind, $iDefault, 5);
        $iSwitch->addCase($i32->constInt(self::PHPC_TYPE_LONG, false), $iLong);
        $iSwitch->addCase($i32->constInt(self::PHPC_TYPE_BOOL, false), $iLong);
        $iSwitch->addCase($i32->constInt(self::PHPC_TYPE_DOUBLE, false), $iDouble);
        $iSwitch->addCase($i32->constInt(self::PHPC_TYPE_NULL, false), $iNull);
        $iSwitch->addCase($i32->constInt(self::PHPC_TYPE_STRING, false), $iString);

        $context->builder->positionAtEnd($iLong);
        $context->builder->store($context->builder->call($readLong, $valuePtr), $intValSlot);
        $context->builder->branch($iEmit);

        $context->builder->positionAtEnd($iDouble);
        $context->builder->store(
            $context->builder->fptosi($context->builder->call($readDouble, $valuePtr), $i64),
            $intValSlot
        );
        $context->builder->branch($iEmit);

        $context->builder->positionAtEnd($iNull);
        $context->builder->store($zeroI64, $intValSlot);
        $context->builder->branch($iEmit);

        $context->builder->positionAtEnd($iString);
        $iStr = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $parsed = $context->builder->call(
            $strtollFn,
            self::stringData($context, $iStr),
            $nullPtr,
            $i32->constInt(10, false)
        );
        $context->builder->store($parsed, $intValSlot);
        $context->builder->branch($iEmit);

        $context->builder->positionAtEnd($iDefault);
        $context->builder->store($zeroI64, $intValSlot);
        $context->builder->branch($iEmit);

        $context->builder->positionAtEnd($iEmit);
        $context->builder->call(
            $snprintfFn,
            $tmp,
            $tmpCap,
            $fmtPtr,
            $context->builder->load($intValSlot)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($floatPath);
        $fDefault = $fn->appendBasicBlock('spec_flagged_f_default');
        $fDouble = $fn->appendBasicBlock('spec_flagged_f_double');
        $fLong = $fn->appendBasicBlock('spec_flagged_f_long');
        $fNull = $fn->appendBasicBlock('spec_flagged_f_null');
        $fString = $fn->appendBasicBlock('spec_flagged_f_string');
        $fEmit = $fn->appendBasicBlock('spec_flagged_f_emit');
        $fSwitch = $context->builder->branchSwitch($kind, $fDefault, 5);
        $fSwitch->addCase($i32->constInt(self::PHPC_TYPE_DOUBLE, false), $fDouble);
        $fSwitch->addCase($i32->constInt(self::PHPC_TYPE_LONG, false), $fLong);
        $fSwitch->addCase($i32->constInt(self::PHPC_TYPE_BOOL, false), $fLong);
        $fSwitch->addCase($i32->constInt(self::PHPC_TYPE_NULL, false), $fNull);
        $fSwitch->addCase($i32->constInt(self::PHPC_TYPE_STRING, false), $fString);

        $context->builder->positionAtEnd($fDouble);
        $context->builder->store($context->builder->call($readDouble, $valuePtr), $floatValSlot);
        $context->builder->branch($fEmit);

        $context->builder->positionAtEnd($fLong);
        $context->builder->store(
            $context->builder->sitofp($context->builder->call($readLong, $valuePtr), $dbl),
            $floatValSlot
        );
        $context->builder->branch($fEmit);

        $context->builder->positionAtEnd($fNull);
        $context->builder->store($zeroDbl, $floatValSlot);
        $context->builder->branch($fEmit);

        $context->builder->positionAtEnd($fString);
        $fStr = $context->builder->call($context->lookupFunction('__value__readString'), $valuePtr);
        $parsedF = $context->builder->call(
            $strtodFn,
            self::stringData($context, $fStr),
            $nullPtr
        );
        $context->builder->store($parsedF, $floatValSlot);
        $context->builder->branch($fEmit);

        $context->builder->positionAtEnd($fDefault);
        $context->builder->store($zeroDbl, $floatValSlot);
        $context->builder->branch($fEmit);

        $context->builder->positionAtEnd($fEmit);
        $context->builder->call(
            $snprintfFn,
            $tmp,
            $tmpCap,
            $fmtPtr,
            $context->builder->load($floatValSlot)
        );
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $outLen = $context->builder->call($strlenFn, $tmp);
        $context->builder->call($appendStr, $buf, $posPtr, $cap, $tmp, $outLen);
        $context->builder->returnVoid();
    }

    private static function emitCompilerSprintf(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $one = $sizeT->constInt(1, false);

        $fmt = $fn->getParam(0);
        $argc = $fn->getParam(1);
        $argv = $fn->getParam(2);

        $nullFmt = $fn->appendBasicBlock('sprintf_null_fmt');
        $work = $fn->appendBasicBlock('sprintf_work');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $fmt, $strPtr->constNull()),
            $nullFmt,
            $work
        );

        $context->builder->positionAtEnd($nullFmt);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('__phpc_fmt_cstr_to_string'),
                self::literalCstr($context, '')
            )
        );

        $context->builder->positionAtEnd($work);
        $format = self::stringData($context, $fmt);
        $fmtLen = self::stringLen($context, $fmt);
        $outSlot = $context->builder->alloca($i8->arrayType(self::SPRINTF_MAX_OUT + 1), 1, 'sprintf_out');
        $out = $context->builder->pointerCast($outSlot, $context->getTypeFromString('int8*'));
        $posSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $argIdxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $iSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $context->builder->store($sizeT->constInt(0, false), $posSlot);
        $context->builder->store($sizeT->constInt(0, false), $argIdxSlot);
        $context->builder->store($sizeT->constInt(0, false), $iSlot);

        $appendChar = $context->lookupFunction('__phpc_fmt_append_char');
        $appendSpec = $context->lookupFunction('__phpc_fmt_append_spec');
        $outCap = $sizeT->constInt(self::SPRINTF_MAX_OUT + 1, false);

        $loopHead = $fn->appendBasicBlock('sprintf_head');
        $loopBody = $fn->appendBasicBlock('sprintf_body');
        $loopDone = $fn->appendBasicBlock('sprintf_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $cont = $context->builder->icmp(Builder::INT_ULT, $i, $fmtLen);
        $context->builder->branchIf($cont, $loopBody, $loopDone);

        $context->builder->positionAtEnd($loopBody);
        $ch = $context->builder->load($context->builder->inBoundsGEP($format, $i));
        $notPct = $fn->appendBasicBlock('sprintf_plain');
        $pct = $fn->appendBasicBlock('sprintf_pct');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('%'), false)),
            $pct,
            $notPct
        );

        $context->builder->positionAtEnd($notPct);
        $context->builder->call($appendChar, $out, $posSlot, $outCap, $ch);
        $context->builder->store($context->builder->add($i, $one), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($pct);
        $nextI = $context->builder->add($i, $one);
        $noSpec = $fn->appendBasicBlock('sprintf_no_spec');
        $hasSpec = $fn->appendBasicBlock('sprintf_has_spec');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_UGE, $nextI, $fmtLen),
            $noSpec,
            $hasSpec
        );

        $context->builder->positionAtEnd($noSpec);
        $context->builder->branch($loopDone);

        $context->builder->positionAtEnd($hasSpec);
        self::emitSprintfParsedConversion(
            $context,
            $fn,
            $format,
            $fmtLen,
            $nextI,
            $out,
            $posSlot,
            $outCap,
            $argIdxSlot,
            $iSlot,
            $argc,
            $argv,
            $appendChar,
            $appendSpec,
            $loopHead,
            $loopDone
        );

        $context->builder->positionAtEnd($loopDone);
        $pos = $context->builder->load($posSlot);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($out, $pos));
        $context->builder->returnValue(
            $context->builder->call($context->lookupFunction('__phpc_fmt_cstr_to_string'), $out)
        );
    }

    private static function emitCompilerPrintf(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $strPtr = $context->getTypeFromString('__string__*');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $zeroI64 = $i64->constInt(0, false);

        $fmt = $fn->getParam(0);
        $argc = $fn->getParam(1);
        $argv = $fn->getParam(2);

        $sprintfFn = $context->lookupFunction('__compiler_sprintf');
        $echoFn = $context->lookupFunction('__phpc_ob_echo_substr');

        $out = $context->builder->call($sprintfFn, $fmt, $argc, $argv);
        $nullOut = $fn->appendBasicBlock('printf_null_out');
        $work = $fn->appendBasicBlock('printf_work');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $out, $strPtr->constNull()),
            $nullOut,
            $work
        );

        $context->builder->positionAtEnd($nullOut);
        $context->builder->returnValue($zeroI64);

        $context->builder->positionAtEnd($work);
        $data = self::stringData($context, $out);
        $len = self::stringLen($context, $out);
        $shouldEcho = $context->builder->and(
            $context->builder->icmp(Builder::INT_UGT, $len, $sizeT->constInt(0, false)),
            $context->builder->icmp(Builder::INT_NE, $data, $i8p->constNull())
        );
        $echoBb = $fn->appendBasicBlock('printf_echo');
        $done = $fn->appendBasicBlock('printf_done');
        $context->builder->branchIf($shouldEcho, $echoBb, $done);

        $context->builder->positionAtEnd($echoBb);
        $context->builder->call($echoFn, $data, $len);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($context->builder->zExt($len, $i64));
    }

    private static function emitCompilerNumberFormat(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');

        $num = $fn->getParam(0);
        $decimalsIn = $fn->getParam(1);
        $decSep = $fn->getParam(2);
        $thouSep = $fn->getParam(3);

        $isNan = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('isnan'), $num),
            $i32->constInt(0, false)
        );
        $nanBb = $fn->appendBasicBlock('nf_nan');
        $checkInf = $fn->appendBasicBlock('nf_check_inf');
        $context->builder->branchIf($isNan, $nanBb, $checkInf);

        $cstrToString = $context->lookupFunction('__phpc_fmt_cstr_to_string');

        $context->builder->positionAtEnd($nanBb);
        $context->builder->returnValue($context->builder->call($cstrToString, self::literalCstr($context, 'nan')));

        $context->builder->positionAtEnd($checkInf);
        $isInf = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('isinf'), $num),
            $i32->constInt(0, false)
        );
        $infBb = $fn->appendBasicBlock('nf_inf');
        $work = $fn->appendBasicBlock('nf_work');
        $context->builder->branchIf($isInf, $infBb, $work);

        $context->builder->positionAtEnd($infBb);
        $context->builder->returnValue($context->builder->call($cstrToString, self::literalCstr($context, 'inf')));

        $context->builder->positionAtEnd($work);
        $decSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $zeroI64 = $i64->constInt(0, false);
        $twenty = $i64->constInt(20, false);
        $negDec = $context->builder->icmp(Builder::INT_SLT, $decimalsIn, $zeroI64);
        $gtTwenty = $context->builder->icmp(Builder::INT_SGT, $decimalsIn, $twenty);
        $context->builder->store(
            $context->builder->select($negDec, $zeroI64, $context->builder->select($gtTwenty, $twenty, $decimalsIn)),
            $decSlot
        );

        $decimals = $context->builder->load($decSlot);
        $scale = $context->builder->call(
            $context->lookupFunction('__phpc_fmt_pow10'),
            $context->builder->trunc($decimals, $i32)
        );
        $scaledIn = $context->builder->call($context->lookupFunction('__phpc_fmt_round_scaled'), $num, $scale);
        $bufSlot = $context->builder->alloca($i8->arrayType(128), 1, 'nf_buf');
        $buf = $context->builder->pointerCast($bufSlot, $i8p);
        $intBufSlot = $context->builder->alloca($i8->arrayType(64), 1, 'nf_int_buf');
        $intBuf = $context->builder->pointerCast($intBufSlot, $i8p);
        $fracBufSlot = $context->builder->alloca($i8->arrayType(32), 1, 'nf_frac_buf');
        $fracBuf = $context->builder->pointerCast($fracBufSlot, $i8p);
        $posSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $scaledSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($sizeT->constInt(0, false), $posSlot);

        $neg = $context->builder->icmp(Builder::INT_SLT, $scaledIn, $zeroI64);
        $negBb = $fn->appendBasicBlock('nf_neg');
        $afterNeg = $fn->appendBasicBlock('nf_after_neg');
        $context->builder->branchIf($neg, $negBb, $afterNeg);

        $appendChar = $context->lookupFunction('__phpc_fmt_append_char');
        $appendStr = $context->lookupFunction('__phpc_fmt_append_str');
        $formatUnsigned = $context->lookupFunction('__phpc_fmt_format_unsigned');
        $formatFraction = $context->lookupFunction('__phpc_fmt_format_fraction');
        $strlenFn = $context->lookupFunction('strlen');
        $bufCap = $sizeT->constInt(128, false);

        $context->builder->positionAtEnd($negBb);
        $context->builder->call($appendChar, $buf, $posSlot, $bufCap, $i8->constInt(ord('-'), false));
        $context->builder->store($context->builder->sub($zeroI64, $scaledIn), $scaledSlot);
        $context->builder->branch($afterNeg);

        $context->builder->positionAtEnd($afterNeg);
        $context->builder->store(
            $context->builder->select($neg, $context->builder->sub($zeroI64, $scaledIn), $scaledIn),
            $scaledSlot
        );

        $scaled = $context->builder->load($scaledSlot);
        $intPart = $context->builder->signedDiv($scaled, $scale);
        $fracPart = $context->builder->signedRem($scaled, $scale);

        $context->builder->call(
            $formatUnsigned,
            $intPart,
            $intBuf,
            $sizeT->constInt(64, false),
            $thouSep
        );
        $intLen = $context->builder->call($strlenFn, $intBuf);
        $context->builder->call($appendStr, $buf, $posSlot, $bufCap, $intBuf, $intLen);

        $hasDecimals = $context->builder->icmp(Builder::INT_SGT, $decimals, $zeroI64);
        $fracBb = $fn->appendBasicBlock('nf_frac');
        $finish = $fn->appendBasicBlock('nf_finish');
        $context->builder->branchIf($hasDecimals, $fracBb, $finish);

        $context->builder->positionAtEnd($fracBb);
        $decLen = self::stringLen($context, $decSep);
        $useDefault = $context->builder->icmp(Builder::INT_EQ, $decLen, $sizeT->constInt(0, false));
        $decData = $context->builder->select(
            $useDefault,
            self::literalCstr($context, '.'),
            self::stringData($context, $decSep)
        );
        $decLenFinal = $context->builder->select(
            $useDefault,
            $sizeT->constInt(1, false),
            $decLen
        );
        $context->builder->call($appendStr, $buf, $posSlot, $bufCap, $decData, $decLenFinal);
        $context->builder->call(
            $formatFraction,
            $fracPart,
            $decimals,
            $fracBuf,
            $sizeT->constInt(32, false)
        );
        $fracLen = $context->builder->call($strlenFn, $fracBuf);
        $context->builder->call($appendStr, $buf, $posSlot, $bufCap, $fracBuf, $fracLen);
        $context->builder->branch($finish);

        $context->builder->positionAtEnd($finish);
        $pos = $context->builder->load($posSlot);
        $context->builder->store($i8->constInt(0, false), $context->builder->inBoundsGEP($buf, $pos));
        $context->builder->returnValue($context->builder->call($cstrToString, $buf));
    }

    /**
     * Parse %n$ positional conversion and append (php-src sprintf.c; issue #3631).
     */
    private static function emitSprintfParsedConversion(
        Context $context,
        LlvmFunction $fn,
        Value $format,
        Value $fmtLen,
        Value $nextI,
        Value $out,
        Value $posSlot,
        Value $outCap,
        Value $argIdxSlot,
        Value $iSlot,
        Value $argc,
        Value $argv,
        LlvmFunction $appendChar,
        LlvmFunction $appendSpec,
        BasicBlock $loopHead,
        BasicBlock $loopDone,
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $valuePtr = $context->getTypeFromString('__value__*');
        $one = $sizeT->constInt(1, false);
        $zeroSize = $sizeT->constInt(0, false);
        $zeroI32 = $i32->constInt(0, false);
        $precUnset = $i32->constInt(-1, true);

        $parsePosSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $usePosSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('int1'));
        $posNumSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $precSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $numSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $resolvedIdxSlot = BasicBlockHelper::entryAlloca($context, $sizeT);
        $signFlagSlot = BasicBlockHelper::entryAlloca($context, $i8);
        $altFormSlot = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('int1'));
        $context->builder->store($nextI, $parsePosSlot);
        $context->builder->store($context->getTypeFromString('int1')->constInt(0, false), $usePosSlot);
        $context->builder->store($zeroSize, $posNumSlot);
        $context->builder->store($precUnset, $precSlot);
        $context->builder->store($i8->constInt(0, false), $signFlagSlot);
        $context->builder->store($context->getTypeFromString('int1')->constInt(0, false), $altFormSlot);

        $loadParsePos = static fn () => $context->builder->load($parsePosSlot);
        $loadParseCh = static fn () => $context->builder->load(
            $context->builder->inBoundsGEP($format, $context->builder->zExt($loadParsePos(), $i64))
        );
        $advanceParse = static fn () => $context->builder->store(
            $context->builder->add($loadParsePos(), $one),
            $parsePosSlot
        );

        $parseCh = $loadParseCh();
        $pctLit = $fn->appendBasicBlock('sprintf_pct_lit');
        $tryPos = $fn->appendBasicBlock('sprintf_try_pos');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $parseCh, $i8->constInt(ord('%'), false)),
            $pctLit,
            $tryPos
        );

        $context->builder->positionAtEnd($pctLit);
        $context->builder->call($appendChar, $out, $posSlot, $outCap, $i8->constInt(ord('%'), false));
        $context->builder->store($context->builder->add($loadParsePos(), $one), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($tryPos);
        $notDigit = $fn->appendBasicBlock('sprintf_not_pos_digit');
        $posDigit = $fn->appendBasicBlock('sprintf_pos_digit');
        $context->builder->branchIf(self::isAsciiDigit($context, $loadParseCh()), $posDigit, $notDigit);

        $context->builder->positionAtEnd($posDigit);
        $context->builder->store($zeroSize, $numSlot);
        $posHead = $fn->appendBasicBlock('sprintf_pos_head');
        $posBody = $fn->appendBasicBlock('sprintf_pos_body');
        $posAfter = $fn->appendBasicBlock('sprintf_pos_after');
        $context->builder->branch($posHead);

        $context->builder->positionAtEnd($posHead);
        $posCont = $context->builder->and(
            self::isAsciiDigit($context, $loadParseCh()),
            $context->builder->icmp(Builder::INT_ULT, $loadParsePos(), $fmtLen)
        );
        $context->builder->branchIf($posCont, $posBody, $posAfter);

        $context->builder->positionAtEnd($posBody);
        $digitVal = $context->builder->sub(
            $context->builder->zExt($loadParseCh(), $sizeT),
            $sizeT->constInt(ord('0'), false)
        );
        $accum = $context->builder->load($numSlot);
        $context->builder->store(
            $context->builder->add(
                $context->builder->mul($accum, $sizeT->constInt(10, false)),
                $digitVal
            ),
            $numSlot
        );
        $advanceParse();
        $context->builder->branch($posHead);

        $context->builder->positionAtEnd($posAfter);
        $noPosDollar = $fn->appendBasicBlock('sprintf_no_pos_dollar');
        $hasPosDollar = $fn->appendBasicBlock('sprintf_has_pos_dollar');
        $atEndAfterNum = $context->builder->icmp(Builder::INT_UGE, $loadParsePos(), $fmtLen);
        $context->builder->branchIf($atEndAfterNum, $noPosDollar, $hasPosDollar);

        $posSet = $fn->appendBasicBlock('sprintf_pos_set');
        $context->builder->positionAtEnd($hasPosDollar);
        $isDollar = $context->builder->icmp(
            Builder::INT_EQ,
            $loadParseCh(),
            $i8->constInt(ord('$'), false)
        );
        $context->builder->branchIf($isDollar, $posSet, $noPosDollar);

        $context->builder->positionAtEnd($posSet);
        $context->builder->store($context->getTypeFromString('int1')->constInt(1, false), $usePosSlot);
        $context->builder->store($context->builder->load($numSlot), $posNumSlot);
        $advanceParse();
        $context->builder->branch($noPosDollar);

        $context->builder->positionAtEnd($noPosDollar);
        $parseFlags = $fn->appendBasicBlock('sprintf_parse_flags');
        $context->builder->branch($parseFlags);

        $context->builder->positionAtEnd($notDigit);
        $context->builder->branch($parseFlags);

        $flagsHead = $fn->appendBasicBlock('sprintf_flags_head');
        $flagsBody = $fn->appendBasicBlock('sprintf_flags_body');
        $afterFlags = $fn->appendBasicBlock('sprintf_after_flags');
        $context->builder->positionAtEnd($parseFlags);
        $context->builder->branch($flagsHead);

        $context->builder->positionAtEnd($flagsHead);
        $flagsCont = $context->builder->and(
            $context->builder->icmp(Builder::INT_ULT, $loadParsePos(), $fmtLen),
            $context->builder->icmp(Builder::INT_NE, $loadParseCh(), $i8->constInt(0, false))
        );
        $context->builder->branchIf($flagsCont, $flagsBody, $afterFlags);

        $context->builder->positionAtEnd($flagsBody);
        $flagCh = $loadParseCh();
        $isMinus = $context->builder->icmp(Builder::INT_EQ, $flagCh, $i8->constInt(ord('-'), false));
        $isZero = $context->builder->icmp(Builder::INT_EQ, $flagCh, $i8->constInt(ord('0'), false));
        $isPlus = $context->builder->icmp(Builder::INT_EQ, $flagCh, $i8->constInt(ord('+'), false));
        $isSpace = $context->builder->icmp(Builder::INT_EQ, $flagCh, $i8->constInt(ord(' '), false));
        $isHash = $context->builder->icmp(Builder::INT_EQ, $flagCh, $i8->constInt(ord('#'), false));
        $isKnownFlag = $context->builder->or(
            $isMinus,
            $context->builder->or(
                $isZero,
                $context->builder->or($isPlus, $context->builder->or($isSpace, $isHash))
            )
        );
        $flagKnown = $fn->appendBasicBlock('sprintf_flag_known');
        $flagUnknown = $fn->appendBasicBlock('sprintf_flag_unknown');
        $context->builder->branchIf($isKnownFlag, $flagKnown, $flagUnknown);

        $context->builder->positionAtEnd($flagUnknown);
        $context->builder->branch($afterFlags);

        $flagApply = $fn->appendBasicBlock('sprintf_flag_apply');
        $flagPlus = $fn->appendBasicBlock('sprintf_flag_plus');
        $flagSpace = $fn->appendBasicBlock('sprintf_flag_space');
        $flagSpaceApply = $fn->appendBasicBlock('sprintf_flag_space_apply');
        $flagHash = $fn->appendBasicBlock('sprintf_flag_hash');
        $context->builder->positionAtEnd($flagKnown);
        $flagCh32 = $context->builder->zExt($flagCh, $i32);
        $flagSwitch = $context->builder->branchSwitch($flagCh32, $flagApply, 5);
        $flagSwitch->addCase($i32->constInt(ord('-'), false), $flagApply);
        $flagSwitch->addCase($i32->constInt(ord('0'), false), $flagApply);
        $flagSwitch->addCase($i32->constInt(ord('+'), false), $flagPlus);
        $flagSwitch->addCase($i32->constInt(ord(' '), false), $flagSpace);
        $flagSwitch->addCase($i32->constInt(ord('#'), false), $flagHash);

        $context->builder->positionAtEnd($flagPlus);
        $context->builder->store($i8->constInt(ord('+'), false), $signFlagSlot);
        $context->builder->branch($flagApply);

        $context->builder->positionAtEnd($flagSpace);
        $noSignYet = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($signFlagSlot),
            $i8->constInt(0, false)
        );
        $context->builder->branchIf($noSignYet, $flagSpaceApply, $flagApply);

        $context->builder->positionAtEnd($flagSpaceApply);
        $context->builder->branch($flagApply);

        $context->builder->positionAtEnd($flagHash);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitValueError($context, 'Unknown format specifier "#"');
        $context->builder->call($context->lookupFunction('abort'));
        $context->llvm->lib->LLVMBuildUnreachable($context->builder->builder);

        $context->builder->positionAtEnd($flagApply);
        $advanceParse();
        $context->builder->branch($flagsHead);

        $context->builder->positionAtEnd($afterFlags);
        $skipWidth = $fn->appendBasicBlock('sprintf_skip_width');
        $context->builder->branch($skipWidth);

        $widthHead = $fn->appendBasicBlock('sprintf_width_head');
        $widthBody = $fn->appendBasicBlock('sprintf_width_body');
        $afterWidth = $fn->appendBasicBlock('sprintf_after_width');
        $context->builder->positionAtEnd($skipWidth);
        $context->builder->branch($widthHead);

        $context->builder->positionAtEnd($widthHead);
        $widthCont = $context->builder->and(
            self::isAsciiDigit($context, $loadParseCh()),
            $context->builder->icmp(Builder::INT_ULT, $loadParsePos(), $fmtLen)
        );
        $context->builder->branchIf($widthCont, $widthBody, $afterWidth);

        $context->builder->positionAtEnd($widthBody);
        $advanceParse();
        $context->builder->branch($widthHead);

        $context->builder->positionAtEnd($afterWidth);
        $noPrec = $fn->appendBasicBlock('sprintf_no_prec');
        $hasDot = $fn->appendBasicBlock('sprintf_has_dot');
        $atEndWidth = $context->builder->icmp(Builder::INT_UGE, $loadParsePos(), $fmtLen);
        $context->builder->branchIf($atEndWidth, $noPrec, $hasDot);

        $precStart = $fn->appendBasicBlock('sprintf_prec_start');
        $context->builder->positionAtEnd($hasDot);
        $isDot = $context->builder->icmp(Builder::INT_EQ, $loadParseCh(), $i8->constInt(ord('.'), false));
        $context->builder->branchIf($isDot, $precStart, $noPrec);

        $context->builder->positionAtEnd($precStart);
        $advanceParse();
        $precHead = $fn->appendBasicBlock('sprintf_prec_head');
        $precBody = $fn->appendBasicBlock('sprintf_prec_body');
        $context->builder->branch($precHead);

        $context->builder->positionAtEnd($precHead);
        $precCont = $context->builder->and(
            self::isAsciiDigit($context, $loadParseCh()),
            $context->builder->icmp(Builder::INT_ULT, $loadParsePos(), $fmtLen)
        );
        $context->builder->branchIf($precCont, $precBody, $noPrec);

        $context->builder->positionAtEnd($precBody);
        $pDigit = $context->builder->sub(
            $context->builder->zExt($loadParseCh(), $i32),
            $i32->constInt(ord('0'), false)
        );
        $pAccum = $context->builder->load($precSlot);
        $pIsUnset = $context->builder->icmp(Builder::INT_SLT, $pAccum, $zeroI32);
        $pNext = $context->builder->select(
            $pIsUnset,
            $pDigit,
            $context->builder->add(
                $context->builder->mul($pAccum, $i32->constInt(10, false)),
                $pDigit
            )
        );
        $context->builder->store($pNext, $precSlot);
        $advanceParse();
        $context->builder->branch($precHead);

        $context->builder->positionAtEnd($noPrec);
        $noSpec = $fn->appendBasicBlock('sprintf_no_spec_end');
        $readSpec = $fn->appendBasicBlock('sprintf_read_spec');
        $atEndPrec = $context->builder->icmp(Builder::INT_UGE, $loadParsePos(), $fmtLen);
        $context->builder->branchIf($atEndPrec, $noSpec, $readSpec);

        $context->builder->positionAtEnd($noSpec);
        $context->builder->branch($loopDone);

        $context->builder->positionAtEnd($readSpec);
        $specCh = $loadParseCh();
        $advanceParse();
        $usePos = $context->builder->load($usePosSlot);
        $appendSpecFloatPrec = $context->lookupFunction('__phpc_fmt_append_spec_f_prec');
        $isFloatSpec = $context->builder->icmp(Builder::INT_EQ, $specCh, $i8->constInt(ord('f'), false));
        $precVal = $context->builder->load($precSlot);
        $hasPrec = $context->builder->icmp(Builder::INT_SGE, $precVal, $zeroI32);
        $usePrecFloat = $context->builder->and($isFloatSpec, $hasPrec);
        $resolvePos = $fn->appendBasicBlock('sprintf_resolve_pos');
        $resolveSeq = $fn->appendBasicBlock('sprintf_resolve_seq');
        $noSeqArgs = $fn->appendBasicBlock('sprintf_no_seq_args');
        $hasSeqArg = $fn->appendBasicBlock('sprintf_has_seq_arg');
        $noPosArgs = $fn->appendBasicBlock('sprintf_no_pos_args');
        $hasPosArg = $fn->appendBasicBlock('sprintf_has_pos_arg');
        $doAppend = $fn->appendBasicBlock('sprintf_do_append');
        $skipArg = $fn->appendBasicBlock('sprintf_skip_arg');
        $emitArg = $fn->appendBasicBlock('sprintf_emit_arg');
        $checkFlags = $fn->appendBasicBlock('sprintf_check_flags');
        $plainEmit = $fn->appendBasicBlock('sprintf_plain_emit');
        $flaggedEmit = $fn->appendBasicBlock('sprintf_flagged_emit');
        $precEmit = $fn->appendBasicBlock('sprintf_prec_emit');
        $afterArg = $fn->appendBasicBlock('sprintf_after_arg');
        $appendSpecFlagged = $context->lookupFunction('__phpc_fmt_append_spec_flagged');
        $context->builder->branchIf($usePos, $resolvePos, $resolveSeq);

        $context->builder->positionAtEnd($resolveSeq);
        $seqIdx = $context->builder->load($argIdxSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_UGE, $seqIdx, $context->builder->trunc($argc, $sizeT)),
            $noSeqArgs,
            $hasSeqArg
        );

        $context->builder->positionAtEnd($noSeqArgs);
        $context->builder->branch($loopDone);

        $context->builder->positionAtEnd($hasSeqArg);
        $context->builder->store($seqIdx, $resolvedIdxSlot);
        $context->builder->store($context->builder->add($seqIdx, $one), $argIdxSlot);
        $context->builder->branch($doAppend);

        $context->builder->positionAtEnd($resolvePos);
        $posNum = $context->builder->load($posNumSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_UGT, $posNum, $context->builder->trunc($argc, $sizeT)),
            $noPosArgs,
            $hasPosArg
        );

        $context->builder->positionAtEnd($noPosArgs);
        $context->builder->branch($loopDone);

        $context->builder->positionAtEnd($hasPosArg);
        $context->builder->store($context->builder->sub($posNum, $one), $resolvedIdxSlot);
        $context->builder->branch($doAppend);

        $context->builder->positionAtEnd($doAppend);
        $resolvedIdx = $context->builder->load($resolvedIdxSlot);
        $context->builder->branchIf($context->builder->icmp(Builder::INT_EQ, $argv, $valuePtr->constNull()), $skipArg, $emitArg);

        $context->builder->positionAtEnd($emitArg);
        $context->builder->branchIf($usePrecFloat, $precEmit, $checkFlags);

        $context->builder->positionAtEnd($checkFlags);
        $hasSignFlag = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->load($signFlagSlot),
            $i8->constInt(0, false)
        );
        $hasFlags = $context->builder->or($hasSignFlag, $context->builder->load($altFormSlot));
        $context->builder->branchIf($hasFlags, $flaggedEmit, $plainEmit);

        $context->builder->positionAtEnd($flaggedEmit);
        $argValFlagged = $context->builder->inBoundsGEP($argv, $context->builder->zExt($resolvedIdx, $i64));
        $context->builder->call(
            $appendSpecFlagged,
            $out,
            $posSlot,
            $outCap,
            $argValFlagged,
            $context->builder->load($signFlagSlot),
            $context->builder->load($altFormSlot),
            $specCh
        );
        $context->builder->branch($afterArg);

        $context->builder->positionAtEnd($plainEmit);
        $argVal = $context->builder->inBoundsGEP($argv, $context->builder->zExt($resolvedIdx, $i64));
        $context->builder->call($appendSpec, $out, $posSlot, $outCap, $argVal, $specCh);
        $context->builder->branch($afterArg);

        $context->builder->positionAtEnd($precEmit);
        $argValPrec = $context->builder->inBoundsGEP($argv, $context->builder->zExt($resolvedIdx, $i64));
        $context->builder->call($appendSpecFloatPrec, $out, $posSlot, $outCap, $argValPrec, $precVal);
        $context->builder->branch($afterArg);

        $context->builder->positionAtEnd($skipArg);
        $context->builder->branch($afterArg);

        $context->builder->positionAtEnd($afterArg);
        $context->builder->store($loadParsePos(), $iSlot);
        $context->builder->branch($loopHead);
    }

    private static function isAsciiDigit(Context $context, Value $ch): Value
    {
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ch, $i8->constInt(ord('0'), false)),
            $context->builder->icmp(Builder::INT_SLE, $ch, $i8->constInt(ord('9'), false))
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

            return;
        }
        $context->builder->clearInsertionPosition();
    }
}
