<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\PackEngine;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM __compiler_pack for AOT standalone link only (#6607, #9133).
 *
 * JIT/normal modules use {@see StringPack} + {@see PackJitHelper} PHP path.
 * php-src reference: ext/standard/pack.c — php_pack()
 */
final class StringPackJit
{
    private const MAX_SPECS = 256;

    private const MAX_OUT = 65536;

    private const SNPRINTF_BUF = 96;

    private static int $blockSuffix = 0;

    public static function implement(Context $context): void
    {
        $restore = self::captureInsertBlock($context);

        $probe = $context->module->getNamedFunction('__compiler_pack');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_pack', $probe);
            self::restoreInsertBlock($context, $restore);

            return;
        }

        self::$blockSuffix = 0;
        self::ensureLibc($context);
        self::ensureRuntimeHelpers($context);
        TypeErrorRaise::ensureLinked($context);

        self::implementIfMissing($context, '__compiler_pack_fail', self::emitFail(...));
        self::implementIfMissing($context, '__compiler_pack_put_long', self::emitPutLong(...));
        self::implementIfMissing($context, '__compiler_pack_put_float', self::emitPutFloat(...));
        self::implementIfMissing($context, '__compiler_pack_put_double', self::emitPutDouble(...));
        self::implementIfMissing($context, '__compiler_pack', self::emitPack(...));

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
        $void = $context->getTypeFromString('void');
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');

        return match ($name) {
            '__compiler_pack_fail' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $i8p)
            ),
            '__compiler_pack_put_long' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $i8p, $i64, $sizeT, $i32)
            ),
            '__compiler_pack_put_float' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $i8p, $double, $i32)
            ),
            '__compiler_pack_put_double' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $i8p, $double, $i32)
            ),
            '__compiler_pack' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $strPtr, $i64, $valuePtr)
            ),
            default => throw new \LogicException('Unknown pack JIT helper: '.$name),
        };
    }

    private static function emitFail(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pack_fail_entry');
        $context->builder->positionAtEnd($entry);

        $msg = $fn->getParam(0);
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');

        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $msg);
        $sizeT = $context->getTypeFromString('size_t');
        $context->builder->call(
            $context->lookupFunction('__compiler_jit_raise_value_error'),
            $msg,
            $context->builder->intCast($msgLen, $sizeT)
        );
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $i64->constInt(0, false),
            $context->builder->pointerCast($context->constantFromString(''), $i8p)
        );
        $context->builder->returnValue($result);
    }

    private static function emitPutLong(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pack_pl_entry');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $zl = $fn->getParam(1);
        $size = $fn->getParam(2);
        $littleEndian = $fn->getParam(3);

        $i8 = $context->getTypeFromString('int8');
        $i16 = $context->getTypeFromString('int16');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i16p = $context->getTypeFromString('int16*');
        $i32p = $context->getTypeFromString('int32*');
        $i64p = $context->getTypeFromString('int64*');
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');
        $machineLe = $i32->constInt(self::machineLe() ? 1 : 0, false);
        $maxSize = $sizeT->constInt(8, false);
        $copySize = $context->builder->select(
            $context->builder->icmp(Builder::INT_UGT, $size, $maxSize),
            $maxSize,
            $size
        );

        $bytes = $context->builder->alloca($i8, 8, 'pack_pl_bytes');
        $context->builder->call(
            $context->lookupFunction('memset'),
            $context->bytePtr($bytes),
            $i32->constInt(0, false),
            $sizeT->constInt(8, false)
        );
        $zlAddr = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($zl, $zlAddr);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($bytes),
            $context->bytePtr($zlAddr),
            $context->builder->truncOrBitCast($copySize, $sizeT)
        );

        $swapNeeded = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->icmp(Builder::INT_NE, $littleEndian, $i32->constInt(0, false)),
            $context->builder->icmp(Builder::INT_NE, $machineLe, $i32->constInt(0, false))
        );
        $swapBb = $fn->appendBasicBlock('pack_pl_swap');
        $afterSwapBb = $fn->appendBasicBlock('pack_pl_after_swap');
        $context->builder->branchIf($swapNeeded, $swapBb, $afterSwapBb);

        $context->builder->positionAtEnd($swapBb);
        $is8 = $context->builder->icmp(Builder::INT_EQ, $size, $sizeT->constInt(8, false));
        $is4 = $context->builder->icmp(Builder::INT_EQ, $size, $sizeT->constInt(4, false));
        $is2 = $context->builder->icmp(Builder::INT_EQ, $size, $sizeT->constInt(2, false));
        $swap8Bb = $fn->appendBasicBlock('pack_pl_swap8');
        $check4Bb = $fn->appendBasicBlock('pack_pl_check4');
        $context->builder->branchIf($is8, $swap8Bb, $check4Bb);

        $context->builder->positionAtEnd($swap8Bb);
        $u64p = $context->builder->pointerCast($bytes, $i64p);
        $context->builder->store(self::bswap64($context, $context->builder->load($u64p)), $u64p);
        $context->builder->branch($afterSwapBb);

        $context->builder->positionAtEnd($check4Bb);
        $swap4Bb = $fn->appendBasicBlock('pack_pl_swap4');
        $check2Bb = $fn->appendBasicBlock('pack_pl_check2');
        $context->builder->branchIf($is4, $swap4Bb, $check2Bb);

        $context->builder->positionAtEnd($swap4Bb);
        $u32p = $context->builder->pointerCast($bytes, $i32p);
        $context->builder->store(self::bswap32($context, $context->builder->load($u32p)), $u32p);
        $context->builder->branch($afterSwapBb);

        $context->builder->positionAtEnd($check2Bb);
        $swap2Bb = $fn->appendBasicBlock('pack_pl_swap2');
        $skip2Bb = $fn->appendBasicBlock('pack_pl_skip2');
        $context->builder->branchIf($is2, $swap2Bb, $skip2Bb);

        $context->builder->positionAtEnd($swap2Bb);
        $u16p = $context->builder->pointerCast($bytes, $i16p);
        $v16 = $context->builder->load($u16p);
        $context->builder->store(
            $context->builder->or(
                $context->builder->lShr($v16, $i16->constInt(8, false)),
                $context->builder->shl($v16, $i16->constInt(8, false))
            ),
            $u16p
        );
        $context->builder->branch($afterSwapBb);

        $context->builder->positionAtEnd($skip2Bb);
        $context->builder->branch($afterSwapBb);

        $context->builder->positionAtEnd($afterSwapBb);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($out),
            $context->bytePtr($bytes),
            $context->builder->truncOrBitCast($copySize, $sizeT)
        );
        $context->builder->returnVoid();
    }

    private static function emitPutFloat(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pack_pf_entry');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $d = $fn->getParam(1);
        $littleEndian = $fn->getParam(2);
        $float = $context->getTypeFromString('float');
        $i32 = $context->getTypeFromString('int32');
        $i32p = $context->getTypeFromString('int32*');
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');
        $machineLe = $i32->constInt(self::machineLe() ? 1 : 0, false);

        $mem = BasicBlockHelper::entryAlloca($context, $float);
        $context->builder->store($context->builder->fptrunc($d, $float), $mem);
        $bits = $context->builder->load($context->builder->pointerCast($mem, $i32p));
        $swapNeeded = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->icmp(Builder::INT_NE, $littleEndian, $i32->constInt(0, false)),
            $context->builder->icmp(Builder::INT_NE, $machineLe, $i32->constInt(0, false))
        );
        $finalBits = $context->builder->select($swapNeeded, self::bswap32($context, $bits), $bits);
        $context->builder->store($finalBits, $context->builder->pointerCast($mem, $i32p));
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($out),
            $context->bytePtr($mem),
            $sizeT->constInt(4, false)
        );
        $context->builder->returnVoid();
    }

    private static function emitPutDouble(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pack_pd_entry');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $d = $fn->getParam(1);
        $littleEndian = $fn->getParam(2);
        $double = $context->getTypeFromString('double');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i64p = $context->getTypeFromString('int64*');
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');
        $machineLe = $i32->constInt(self::machineLe() ? 1 : 0, false);

        $mem = BasicBlockHelper::entryAlloca($context, $double);
        $context->builder->store($d, $mem);
        $bits = $context->builder->load($context->builder->pointerCast($mem, $i64p));
        $swapNeeded = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->icmp(Builder::INT_NE, $littleEndian, $i32->constInt(0, false)),
            $context->builder->icmp(Builder::INT_NE, $machineLe, $i32->constInt(0, false))
        );
        $finalBits = $context->builder->select($swapNeeded, self::bswap64($context, $bits), $bits);
        $context->builder->store($finalBits, $context->builder->pointerCast($mem, $i64p));
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($out),
            $context->bytePtr($mem),
            $sizeT->constInt(8, false)
        );
        $context->builder->returnVoid();
    }

    private static function emitPack(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('pack_entry');
        $context->builder->positionAtEnd($entry);

        $fmt = $fn->getParam(0);
        $argc = $fn->getParam(1);
        $argv = $fn->getParam(2);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $i8p = $context->getTypeFromString('int8*');
        $i32p = $context->getTypeFromString('int32*');
        $i64p = $context->getTypeFromString('int64*');
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $stringMap = $context->structFieldMap['__string__'];
        $machineLe = $i32->constInt(self::machineLe() ? 1 : 0, false);
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $intSizeI64 = $i64->constInt(PackEngine::PACK_INT_SIZE, false);
        $floatSizeI64 = $i64->constInt(4, false);
        $doubleSizeI64 = $i64->constInt(8, false);

        $fmtNullBb = $fn->appendBasicBlock('pack_fmt_null');
        $startBb = $fn->appendBasicBlock('pack_start');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $fmt, $strPtr->constNull()),
            $fmtNullBb,
            $startBb
        );

        $context->builder->positionAtEnd($fmtNullBb);
        $msg = $context->builder->pointerCast(
            $context->constantFromString('pack(): Argument #1 ($format) must be of type string'),
            $i8p
        );
        $fail = $context->builder->call($context->lookupFunction('__compiler_pack_fail'), $msg);
        $context->builder->returnValue($fail);

        $context->builder->positionAtEnd($startBb);
        $fmtSep = $context->builder->call($context->lookupFunction('__string__separate'), $fmt);
        $format = $context->builder->structGep($fmtSep, $stringMap['value']);
        $formatLen = $context->builder->load($context->builder->structGep($fmtSep, $stringMap['length']));

        $emptyFmtBb = $fn->appendBasicBlock('pack_empty_fmt');
        $parseBb = $fn->appendBasicBlock('pack_parse');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $formatLen, $zeroI64),
            $emptyFmtBb,
            $parseBb
        );

        $context->builder->positionAtEnd($emptyFmtBb);
        $empty = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $zeroI64,
            $context->builder->pointerCast($context->constantFromString(''), $i8p)
        );
        $context->builder->returnValue($empty);

        $context->builder->positionAtEnd($parseBb);
        $specCodes = $context->builder->alloca($i8, self::MAX_SPECS, 'pack_spec_codes');
        $specArgs = $context->builder->alloca($i32, self::MAX_SPECS, 'pack_spec_args');
        $specCountSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $currentArgSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $iSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $numArgs = $context->builder->trunc($argc, $i32);
        $context->builder->store($zeroI32, $specCountSlot);
        $context->builder->store($zeroI32, $currentArgSlot);
        $context->builder->store($zeroI64, $iSlot);

        $parseHead = $fn->appendBasicBlock('pack_parse_head');
        $parseBody = $fn->appendBasicBlock('pack_parse_body');
        $parseDone = $fn->appendBasicBlock('pack_parse_done');
        $context->builder->branch($parseHead);

        $context->builder->positionAtEnd($parseHead);
        $i = $context->builder->load($iSlot);
        $specCount = $context->builder->load($specCountSlot);
        $canLoop = $context->builder->and(
            $context->builder->icmp(Builder::INT_ULT, $i, $formatLen),
            $context->builder->icmp(Builder::INT_SLT, $specCount, $i32->constInt(self::MAX_SPECS, false))
        );
        $context->builder->branchIf($canLoop, $parseBody, $parseDone);

        $context->builder->positionAtEnd($parseBody);
        $i = $context->builder->load($iSlot);
        $code = $context->builder->load($context->builder->gep($format, $i));
        $context->builder->store($context->builder->add($i, $oneI64), $iSlot);
        $argSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($oneI32, $argSlot);

        $checkArgBb = $fn->appendBasicBlock('pack_parse_check_arg');
        $haveArgSpecBb = $fn->appendBasicBlock('pack_parse_have_arg');
        $context->builder->branch($checkArgBb);

        $context->builder->positionAtEnd($checkArgBb);
        $i = $context->builder->load($iSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_ULT, $i, $formatLen),
            $haveArgSpecBb,
            $fn->appendBasicBlock('pack_parse_arg_done')
        );
        $parseArgDone = $context->builder->getInsertBlock();

        $context->builder->positionAtEnd($haveArgSpecBb);
        $c = $context->builder->load($context->builder->gep($format, $i));
        $starBb = $fn->appendBasicBlock('pack_parse_star');
        $digitEntryBb = $fn->appendBasicBlock('pack_parse_digit_entry');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $c, $i8->constInt((int) \ord('*'), false)),
            $starBb,
            $digitEntryBb
        );

        $context->builder->positionAtEnd($starBb);
        $context->builder->store($i32->constInt(-1, true), $argSlot);
        $context->builder->store($context->builder->add($i, $oneI64), $iSlot);
        $context->builder->branch($parseArgDone);

        $context->builder->positionAtEnd($digitEntryBb);
        $isDigit = self::isAsciiDigit($context, $c);
        $digitLoopHead = $fn->appendBasicBlock('pack_parse_digit_head');
        $context->builder->branchIf($isDigit, $digitLoopHead, $parseArgDone);

        $context->builder->positionAtEnd($digitLoopHead);
        $context->builder->store($zeroI32, $argSlot);
        $digitBodyBb = $fn->appendBasicBlock('pack_parse_digit_body');
        $digitDoneBb = $fn->appendBasicBlock('pack_parse_digit_done');
        $context->builder->branch($digitBodyBb);

        $context->builder->positionAtEnd($digitBodyBb);
        $i = $context->builder->load($iSlot);
        $inRange = $context->builder->icmp(Builder::INT_ULT, $i, $formatLen);
        $digitCondBb = $fn->appendBasicBlock('pack_parse_digit_cond');
        $context->builder->branchIf($inRange, $digitCondBb, $digitDoneBb);

        $context->builder->positionAtEnd($digitCondBb);
        $dc = $context->builder->load($context->builder->gep($format, $i));
        $isD = self::isAsciiDigit($context, $dc);
        $digitStepBb = $fn->appendBasicBlock('pack_parse_digit_step');
        $context->builder->branchIf($isD, $digitStepBb, $digitDoneBb);

        $context->builder->positionAtEnd($digitStepBb);
        $arg = $context->builder->load($argSlot);
        $digitVal = $context->builder->sub(
            $context->builder->zext($dc, $i32),
            $i32->constInt((int) \ord('0'), false)
        );
        $context->builder->store(
            $context->builder->add($context->builder->mul($arg, $i32->constInt(10, false)), $digitVal),
            $argSlot
        );
        $context->builder->store($context->builder->add($i, $oneI64), $iSlot);
        $context->builder->branch($digitBodyBb);

        $context->builder->positionAtEnd($digitDoneBb);
        $context->builder->branch($parseArgDone);

        $context->builder->positionAtEnd($parseArgDone);
        $arg = $context->builder->load($argSlot);
        $currentArg = $context->builder->load($currentArgSlot);

        $isPadCode = self::matchesAny($context, $code, 'xX@');
        $isStringCode = self::matchesAny($context, $code, 'aAZhH');
        $isNumericCode = self::matchesAny($context, $code, 'cCsSiIlLnNvVqQJPfgGdEe');
        $parsePadBb = $fn->appendBasicBlock('pack_parse_pad');
        $parseStringBb = $fn->appendBasicBlock('pack_parse_string');
        $parseNumericBb = $fn->appendBasicBlock('pack_parse_numeric');
        $parseUnknownBb = $fn->appendBasicBlock('pack_parse_unknown');
        $afterValidateBb = $fn->appendBasicBlock('pack_parse_after_validate');

        $context->builder->branchIf(
            $isPadCode,
            $parsePadBb,
            $fn->appendBasicBlock('pack_parse_check_string')
        );
        $parseCheckStringBb = $context->builder->getInsertBlock();

        $context->builder->positionAtEnd($parseCheckStringBb);
        $context->builder->branchIf($isStringCode, $parseStringBb, $fn->appendBasicBlock('pack_parse_check_numeric'));
        $parseCheckNumericBb = $context->builder->getInsertBlock();

        $context->builder->positionAtEnd($parseCheckNumericBb);
        $context->builder->branchIf($isNumericCode, $parseNumericBb, $parseUnknownBb);

        $context->builder->positionAtEnd($parsePadBb);
        $fixed = $context->builder->select(
            $context->builder->icmp(Builder::INT_SLT, $arg, $zeroI32),
            $oneI32,
            $arg
        );
        $context->builder->store($fixed, $argSlot);
        $context->builder->branch($afterValidateBb);

        $context->builder->positionAtEnd($parseStringBb);
        $needArgFailBb = $fn->appendBasicBlock('pack_parse_string_need_arg_fail');
        $stringArgOkBb = $fn->appendBasicBlock('pack_parse_string_arg_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $currentArg, $numArgs),
            $needArgFailBb,
            $stringArgOkBb
        );
        $context->builder->positionAtEnd($needArgFailBb);
        $msg = self::snprintfAlloca($context, 'Type %c: not enough arguments', [$code]);
        $fail = $context->builder->call($context->lookupFunction('__compiler_pack_fail'), $msg);
        $context->builder->returnValue($fail);

        $context->builder->positionAtEnd($stringArgOkBb);
        $argIsStar = $context->builder->icmp(Builder::INT_SLT, $arg, $zeroI32);
        $argStarBb = $fn->appendBasicBlock('pack_parse_string_star');
        $argDoneBb = $fn->appendBasicBlock('pack_parse_string_done');
        $context->builder->branchIf($argIsStar, $argStarBb, $argDoneBb);

        $context->builder->positionAtEnd($argStarBb);
        $argValue = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $context->builder->gep($argv, $context->builder->sext($currentArg, $i64))
        );
        $argSep = $context->builder->call($context->lookupFunction('__string__separate'), $argValue);
        $argLen = $context->builder->load($context->builder->structGep($argSep, $stringMap['length']));
        $len32 = $context->builder->trunc($argLen, $i32);
        $isZ = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('Z'), false));
        $final = $context->builder->select($isZ, $context->builder->add($len32, $oneI32), $len32);
        $context->builder->store($final, $argSlot);
        $context->builder->branch($argDoneBb);

        $context->builder->positionAtEnd($argDoneBb);
        $context->builder->store($context->builder->add($currentArg, $oneI32), $currentArgSlot);
        $context->builder->branch($afterValidateBb);

        $context->builder->positionAtEnd($parseNumericBb);
        $argNow = $context->builder->load($argSlot);
        $numericCount = $context->builder->select(
            $context->builder->icmp(Builder::INT_SLT, $argNow, $zeroI32),
            $context->builder->sub($numArgs, $currentArg),
            $argNow
        );
        $newCurrent = $context->builder->add($currentArg, $numericCount);
        $context->builder->store($numericCount, $argSlot);
        $fewArgsFailBb = $fn->appendBasicBlock('pack_parse_numeric_few_fail');
        $numericOkBb = $fn->appendBasicBlock('pack_parse_numeric_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGT, $newCurrent, $numArgs),
            $fewArgsFailBb,
            $numericOkBb
        );
        $context->builder->positionAtEnd($fewArgsFailBb);
        $msg = self::snprintfAlloca($context, 'Type %c: too few arguments', [$code]);
        $fail = $context->builder->call($context->lookupFunction('__compiler_pack_fail'), $msg);
        $context->builder->returnValue($fail);
        $context->builder->positionAtEnd($numericOkBb);
        $context->builder->store($newCurrent, $currentArgSlot);
        $context->builder->branch($afterValidateBb);

        $context->builder->positionAtEnd($parseUnknownBb);
        $msg = self::snprintfAlloca($context, 'Type %c: unknown format code', [$code]);
        $fail = $context->builder->call($context->lookupFunction('__compiler_pack_fail'), $msg);
        $context->builder->returnValue($fail);

        $context->builder->positionAtEnd($afterValidateBb);
        $specCount = $context->builder->load($specCountSlot);
        $specIdx64 = $context->builder->sext($specCount, $i64);
        $context->builder->store($code, $context->builder->gep($specCodes, $specIdx64));
        $context->builder->store($context->builder->load($argSlot), $context->builder->gep($specArgs, $specCount));
        $context->builder->store($context->builder->add($specCount, $oneI32), $specCountSlot);
        $context->builder->branch($parseHead);

        $context->builder->positionAtEnd($parseDone);
        $outputSizeSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $outputPosSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $sizeI = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($zeroI64, $outputSizeSlot);
        $context->builder->store($zeroI64, $outputPosSlot);
        $context->builder->store($zeroI32, $sizeI);

        $sizeHead = $fn->appendBasicBlock('pack_size_head');
        $sizeBody = $fn->appendBasicBlock('pack_size_body');
        $sizeDone = $fn->appendBasicBlock('pack_size_done');
        $context->builder->branch($sizeHead);

        $context->builder->positionAtEnd($sizeHead);
        $idx = $context->builder->load($sizeI);
        $specCount = $context->builder->load($specCountSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $idx, $specCount),
            $sizeBody,
            $sizeDone
        );

        $context->builder->positionAtEnd($sizeBody);
        $idx64 = $context->builder->sext($idx, $i64);
        $code = $context->builder->load($context->builder->gep($specCodes, $idx64));
        $arg = $context->builder->load($context->builder->gep($specArgs, $idx));
        $arg64 = $context->builder->sext($arg, $i64);
        $outputPos = $context->builder->load($outputPosSlot);
        $outputSize = $context->builder->load($outputSizeSlot);
        $incSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($zeroI64, $incSlot);

        $isH = self::matchesAny($context, $code, 'hH');
        $isByte = self::matchesAny($context, $code, 'aAZcCx');
        $isShort = self::matchesAny($context, $code, 'sSnv');
        $isInt = self::matchesAny($context, $code, 'iI');
        $isLong = self::matchesAny($context, $code, 'lLNV');
        $isQuad = self::matchesAny($context, $code, 'qQJP');
        $isFloat = self::matchesAny($context, $code, 'fgG');
        $isDouble = self::matchesAny($context, $code, 'dEe');
        $isX = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('X'), false));
        $isAt = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('@'), false));

        $sizeIsHBb = $fn->appendBasicBlock('pack_size_h');
        $sizeCheckByteBb = $fn->appendBasicBlock('pack_size_check_byte');
        $context->builder->branchIf($isH, $sizeIsHBb, $sizeCheckByteBb);

        $context->builder->positionAtEnd($sizeIsHBb);
        $half = $context->builder->unsignedDiv($arg64, $i64->constInt(2, false));
        $odd = $context->builder->unsigendRem($arg64, $i64->constInt(2, false));
        $context->builder->store($context->builder->add($half, $odd), $incSlot);
        $context->builder->branch($fn->appendBasicBlock('pack_size_after_set_inc'));
        $sizeAfterSetInc = $context->builder->getInsertBlock();

        $context->builder->positionAtEnd($sizeCheckByteBb);
        $sizeByteBb = $fn->appendBasicBlock('pack_size_byte');
        $sizeCheckShortBb = $fn->appendBasicBlock('pack_size_check_short');
        $context->builder->branchIf($isByte, $sizeByteBb, $sizeCheckShortBb);
        $context->builder->positionAtEnd($sizeByteBb);
        $context->builder->store($arg64, $incSlot);
        $context->builder->branch($sizeAfterSetInc);

        $context->builder->positionAtEnd($sizeCheckShortBb);
        $sizeShortBb = $fn->appendBasicBlock('pack_size_short');
        $sizeCheckIntBb = $fn->appendBasicBlock('pack_size_check_int');
        $context->builder->branchIf($isShort, $sizeShortBb, $sizeCheckIntBb);
        $context->builder->positionAtEnd($sizeShortBb);
        $context->builder->store($context->builder->mul($arg64, $i64->constInt(2, false)), $incSlot);
        $context->builder->branch($sizeAfterSetInc);

        $context->builder->positionAtEnd($sizeCheckIntBb);
        $sizeIntBb = $fn->appendBasicBlock('pack_size_int');
        $sizeCheckLongBb = $fn->appendBasicBlock('pack_size_check_long');
        $context->builder->branchIf($isInt, $sizeIntBb, $sizeCheckLongBb);
        $context->builder->positionAtEnd($sizeIntBb);
        $context->builder->store($context->builder->mul($arg64, $intSizeI64), $incSlot);
        $context->builder->branch($sizeAfterSetInc);

        $context->builder->positionAtEnd($sizeCheckLongBb);
        $sizeLongBb = $fn->appendBasicBlock('pack_size_long');
        $sizeCheckQuadBb = $fn->appendBasicBlock('pack_size_check_quad');
        $context->builder->branchIf($isLong, $sizeLongBb, $sizeCheckQuadBb);
        $context->builder->positionAtEnd($sizeLongBb);
        $context->builder->store($context->builder->mul($arg64, $i64->constInt(4, false)), $incSlot);
        $context->builder->branch($sizeAfterSetInc);

        $context->builder->positionAtEnd($sizeCheckQuadBb);
        $sizeQuadBb = $fn->appendBasicBlock('pack_size_quad');
        $sizeCheckFloatBb = $fn->appendBasicBlock('pack_size_check_float');
        $context->builder->branchIf($isQuad, $sizeQuadBb, $sizeCheckFloatBb);
        $context->builder->positionAtEnd($sizeQuadBb);
        $context->builder->store($context->builder->mul($arg64, $i64->constInt(8, false)), $incSlot);
        $context->builder->branch($sizeAfterSetInc);

        $context->builder->positionAtEnd($sizeCheckFloatBb);
        $sizeFloatBb = $fn->appendBasicBlock('pack_size_float');
        $sizeCheckDoubleBb = $fn->appendBasicBlock('pack_size_check_double');
        $context->builder->branchIf($isFloat, $sizeFloatBb, $sizeCheckDoubleBb);
        $context->builder->positionAtEnd($sizeFloatBb);
        $context->builder->store($context->builder->mul($arg64, $floatSizeI64), $incSlot);
        $context->builder->branch($sizeAfterSetInc);

        $context->builder->positionAtEnd($sizeCheckDoubleBb);
        $sizeDoubleBb = $fn->appendBasicBlock('pack_size_double');
        $sizeCheckXBb = $fn->appendBasicBlock('pack_size_check_x');
        $context->builder->branchIf($isDouble, $sizeDoubleBb, $sizeCheckXBb);
        $context->builder->positionAtEnd($sizeDoubleBb);
        $context->builder->store($context->builder->mul($arg64, $doubleSizeI64), $incSlot);
        $context->builder->branch($sizeAfterSetInc);

        $context->builder->positionAtEnd($sizeCheckXBb);
        $sizeXBb = $fn->appendBasicBlock('pack_size_x');
        $sizeCheckAtBb = $fn->appendBasicBlock('pack_size_check_at');
        $context->builder->branchIf($isX, $sizeXBb, $sizeCheckAtBb);

        $context->builder->positionAtEnd($sizeXBb);
        $newPos = $context->builder->select(
            $context->builder->icmp(Builder::INT_UGT, $arg64, $outputPos),
            $zeroI64,
            $context->builder->sub($outputPos, $arg64)
        );
        $context->builder->store($newPos, $outputPosSlot);
        $context->builder->store(
            $context->builder->add($idx, $oneI32),
            $sizeI
        );
        $context->builder->branch($sizeHead);

        $context->builder->positionAtEnd($sizeCheckAtBb);
        $sizeAtBb = $fn->appendBasicBlock('pack_size_at');
        $sizeDefaultBb = $fn->appendBasicBlock('pack_size_default');
        $context->builder->branchIf($isAt, $sizeAtBb, $sizeDefaultBb);

        $context->builder->positionAtEnd($sizeAtBb);
        $newPos = $context->builder->select(
            $context->builder->icmp(Builder::INT_SGT, $arg, $zeroI32),
            $arg64,
            $zeroI64
        );
        $context->builder->store($newPos, $outputPosSlot);
        $newSize = $context->builder->select(
            $context->builder->icmp(Builder::INT_UGT, $newPos, $outputSize),
            $newPos,
            $outputSize
        );
        $context->builder->store($newSize, $outputSizeSlot);
        $context->builder->store($context->builder->add($idx, $oneI32), $sizeI);
        $context->builder->branch($sizeHead);

        $context->builder->positionAtEnd($sizeDefaultBb);
        $context->builder->store($context->builder->add($idx, $oneI32), $sizeI);
        $context->builder->branch($sizeHead);

        $context->builder->positionAtEnd($sizeAfterSetInc);
        $inc = $context->builder->load($incSlot);
        $newPos = $context->builder->add($outputPos, $inc);
        $context->builder->store($newPos, $outputPosSlot);
        $newSize = $context->builder->select(
            $context->builder->icmp(Builder::INT_UGT, $newPos, $outputSize),
            $newPos,
            $outputSize
        );
        $context->builder->store($newSize, $outputSizeSlot);
        $context->builder->store($context->builder->add($idx, $oneI32), $sizeI);
        $context->builder->branch($sizeHead);

        $context->builder->positionAtEnd($sizeDone);
        $outputSize = $context->builder->load($outputSizeSlot);
        $overflowBb = $fn->appendBasicBlock('pack_size_overflow');
        $allocBb = $fn->appendBasicBlock('pack_alloc');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_UGT, $outputSize, $i64->constInt(self::MAX_OUT, false)),
            $overflowBb,
            $allocBb
        );
        $context->builder->positionAtEnd($overflowBb);
        $msg = $context->builder->pointerCast(
            $context->constantFromString('integer overflow in format string'),
            $i8p
        );
        $fail = $context->builder->call($context->lookupFunction('__compiler_pack_fail'), $msg);
        $context->builder->returnValue($fail);

        $context->builder->positionAtEnd($allocBb);
        $allocSize = $context->builder->select(
            $context->builder->icmp(Builder::INT_UGT, $outputSize, $zeroI64),
            $outputSize,
            $oneI64
        );
        $outputRaw = $context->builder->call(
            $context->lookupFunction('calloc'),
            $context->builder->truncOrBitCast($allocSize, $sizeT),
            $sizeT->constInt(1, false)
        );
        $output = $context->builder->pointerCast($outputRaw, $i8p);
        $oomBb = $fn->appendBasicBlock('pack_alloc_oom');
        $execBb = $fn->appendBasicBlock('pack_exec');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $outputRaw, $voidPtr->constNull()),
            $oomBb,
            $execBb
        );
        $context->builder->positionAtEnd($oomBb);
        $msg = $context->builder->pointerCast($context->constantFromString('pack(): out of memory'), $i8p);
        $fail = $context->builder->call($context->lookupFunction('__compiler_pack_fail'), $msg);
        $context->builder->returnValue($fail);

        $context->builder->positionAtEnd($execBb);
        $execI = BasicBlockHelper::entryAlloca($context, $i32);
        $execCurrentArg = BasicBlockHelper::entryAlloca($context, $i32);
        $execOutputPos = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($zeroI32, $execI);
        $context->builder->store($zeroI32, $execCurrentArg);
        $context->builder->store($zeroI64, $execOutputPos);

        $execHead = $fn->appendBasicBlock('pack_exec_head');
        $execBody = $fn->appendBasicBlock('pack_exec_body');
        $execDone = $fn->appendBasicBlock('pack_exec_done');
        $context->builder->branch($execHead);

        $context->builder->positionAtEnd($execHead);
        $idx = $context->builder->load($execI);
        $specCount = $context->builder->load($specCountSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $idx, $specCount),
            $execBody,
            $execDone
        );

        $context->builder->positionAtEnd($execBody);
        $idx64 = $context->builder->sext($idx, $i64);
        $code = $context->builder->load($context->builder->gep($specCodes, $idx64));
        $arg = $context->builder->load($context->builder->gep($specArgs, $idx));
        $argSlotExec = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($arg, $argSlotExec);
        $dispatchPadBb = $fn->appendBasicBlock('pack_exec_dispatch_pad');
        $dispatchDataBb = $fn->appendBasicBlock('pack_exec_dispatch_data');
        $context->builder->branchIf(
            self::matchesAny($context, $code, 'X@'),
            $dispatchPadBb,
            $dispatchDataBb
        );

        $context->builder->positionAtEnd($dispatchPadBb);
        $isXCode = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('X'), false));
        $execX = $fn->appendBasicBlock('pack_exec_x');
        $execAt = $fn->appendBasicBlock('pack_exec_at');
        $context->builder->branchIf($isXCode, $execX, $execAt);

        $context->builder->positionAtEnd($execX);
        $pos = $context->builder->load($execOutputPos);
        $arg64 = $context->builder->sext($arg, $i64);
        $newPos = $context->builder->select(
            $context->builder->icmp(Builder::INT_UGT, $arg64, $pos),
            $zeroI64,
            $context->builder->sub($pos, $arg64)
        );
        $context->builder->store($newPos, $execOutputPos);
        $context->builder->branch($fn->appendBasicBlock('pack_exec_next'));
        $execNext = $context->builder->getInsertBlock();

        $context->builder->positionAtEnd($execAt);
        $pos = $context->builder->load($execOutputPos);
        $arg64 = $context->builder->sext($arg, $i64);
        $growBb = $fn->appendBasicBlock('pack_exec_at_grow');
        $setAtBb = $fn->appendBasicBlock('pack_exec_at_set');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_UGT, $arg64, $pos),
            $growBb,
            $setAtBb
        );
        $context->builder->positionAtEnd($growBb);
        $context->builder->call(
            $context->lookupFunction('memset'),
            $context->builder->pointerCast($context->builder->gep($output, $pos), $voidPtr),
            $i32->constInt(0, false),
            $context->builder->truncOrBitCast($context->builder->sub($arg64, $pos), $sizeT)
        );
        $context->builder->branch($setAtBb);

        $context->builder->positionAtEnd($setAtBb);
        $context->builder->store($arg64, $execOutputPos);
        $context->builder->branch($execNext);

        $context->builder->positionAtEnd($dispatchDataBb);
        $isStringData = self::matchesAny($context, $code, 'aAZ');
        $execStringBb = $fn->appendBasicBlock('pack_exec_string');
        $checkHexBb = $fn->appendBasicBlock('pack_exec_check_hex');
        $context->builder->branchIf($isStringData, $execStringBb, $checkHexBb);

        $context->builder->positionAtEnd($execStringBb);
        $currentArg = $context->builder->load($execCurrentArg);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $context->builder->gep($argv, $context->builder->sext($currentArg, $i64))
        );
        $context->builder->store($context->builder->add($currentArg, $oneI32), $execCurrentArg);
        $strSep = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        $slen = $context->builder->load($context->builder->structGep($strSep, $stringMap['length']));
        $sdata = $context->builder->structGep($strSep, $stringMap['value']);
        $arg32 = $context->builder->load($argSlotExec);
        $arg64 = $context->builder->sext($arg32, $i64);
        $isZ = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('Z'), false));
        $argCp = $context->builder->select(
            $isZ,
            $context->builder->select(
                $context->builder->icmp(Builder::INT_SGT, $arg32, $zeroI32),
                $context->builder->sub($arg64, $oneI64),
                $zeroI64
            ),
            $arg64
        );
        $fill = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('A'), false)),
            $i32->constInt((int) \ord(' '), false),
            $i32->constInt(0, false)
        );
        $pos = $context->builder->load($execOutputPos);
        $outAt = $context->builder->gep($output, $pos);
        $context->builder->call(
            $context->lookupFunction('memset'),
            $context->bytePtr($outAt),
            $fill,
            $context->builder->truncOrBitCast($arg64, $sizeT)
        );
        $copyLen = $context->builder->select(
            $context->builder->icmp(Builder::INT_ULT, $slen, $argCp),
            $slen,
            $argCp
        );
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($outAt),
            $context->bytePtr($sdata),
            $context->builder->truncOrBitCast($copyLen, $sizeT)
        );
        $context->builder->store($context->builder->add($pos, $arg64), $execOutputPos);
        $context->builder->branch($execNext);

        $context->builder->positionAtEnd($checkHexBb);
        $isHex = self::matchesAny($context, $code, 'hH');
        $execHexBb = $fn->appendBasicBlock('pack_exec_hex');
        $checkIntLikeBb = $fn->appendBasicBlock('pack_exec_check_intlike');
        $context->builder->branchIf($isHex, $execHexBb, $checkIntLikeBb);

        $context->builder->positionAtEnd($execHexBb);
        $currentArg = $context->builder->load($execCurrentArg);
        $str = $context->builder->call(
            $context->lookupFunction('__value__readString'),
            $context->builder->gep($argv, $context->builder->sext($currentArg, $i64))
        );
        $context->builder->store($context->builder->add($currentArg, $oneI32), $execCurrentArg);
        $strSep = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        $slen = $context->builder->load($context->builder->structGep($strSep, $stringMap['length']));
        $sdata = $context->builder->structGep($strSep, $stringMap['value']);
        $remainSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $vPosSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $firstSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $nibbleShiftSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $arg32 = $context->builder->load($argSlotExec);
        $arg64 = $context->builder->sext($arg32, $i64);
        $remain = $context->builder->select(
            $context->builder->icmp(Builder::INT_UGT, $arg64, $slen),
            $slen,
            $arg64
        );
        $context->builder->store($remain, $remainSlot);
        $context->builder->store($zeroI64, $vPosSlot);
        $context->builder->store($oneI32, $firstSlot);
        $context->builder->store(
            $context->builder->select(
                $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('h'), false)),
                $zeroI32,
                $i32->constInt(4, false)
            ),
            $nibbleShiftSlot
        );
        $pos = $context->builder->load($execOutputPos);
        $context->builder->store($context->builder->sub($pos, $oneI64), $execOutputPos);

        $hexHead = $fn->appendBasicBlock('pack_exec_hex_head');
        $hexBody = $fn->appendBasicBlock('pack_exec_hex_body');
        $hexDone = $fn->appendBasicBlock('pack_exec_hex_done');
        $context->builder->branch($hexHead);

        $context->builder->positionAtEnd($hexHead);
        $remain = $context->builder->load($remainSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_UGT, $remain, $zeroI64),
            $hexBody,
            $hexDone
        );

        $context->builder->positionAtEnd($hexBody);
        $vPos = $context->builder->load($vPosSlot);
        $ch = $context->builder->load($context->builder->gep($sdata, $vPos));
        $nibble = self::hexNibble($context, $ch);
        $first = $context->builder->load($firstSlot);
        $firstTrueBb = $fn->appendBasicBlock('pack_exec_hex_first');
        $firstFalseBb = $fn->appendBasicBlock('pack_exec_hex_not_first');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $first, $zeroI32),
            $firstTrueBb,
            $firstFalseBb
        );

        $context->builder->positionAtEnd($firstTrueBb);
        $pos = $context->builder->load($execOutputPos);
        $nextPos = $context->builder->add($pos, $oneI64);
        $context->builder->store($nextPos, $execOutputPos);
        $context->builder->store($i8->constInt(0, false), $context->builder->gep($output, $nextPos));
        $context->builder->store($zeroI32, $firstSlot);
        $context->builder->branch($fn->appendBasicBlock('pack_exec_hex_merge'));
        $hexMergeBb = $context->builder->getInsertBlock();

        $context->builder->positionAtEnd($firstFalseBb);
        $context->builder->store($oneI32, $firstSlot);
        $context->builder->branch($hexMergeBb);

        $context->builder->positionAtEnd($hexMergeBb);
        $pos = $context->builder->load($execOutputPos);
        $shift = $context->builder->load($nibbleShiftSlot);
        $old = $context->builder->zext($context->builder->load($context->builder->gep($output, $pos)), $i32);
        $shiftedNibble = $context->builder->shl(
            $context->builder->zext($nibble, $i32),
            $shift
        );
        $context->builder->store(
            $context->builder->trunc($context->builder->or($old, $shiftedNibble), $i8),
            $context->builder->gep($output, $pos)
        );
        $nextShift = $context->builder->and($context->builder->add($shift, $i32->constInt(4, false)), $i32->constInt(7, false));
        $context->builder->store($nextShift, $nibbleShiftSlot);
        $context->builder->store($context->builder->add($vPos, $oneI64), $vPosSlot);
        $context->builder->store($context->builder->sub($remain, $oneI64), $remainSlot);
        $context->builder->branch($hexHead);

        $context->builder->positionAtEnd($hexDone);
        $pos = $context->builder->load($execOutputPos);
        $context->builder->store($context->builder->add($pos, $oneI64), $execOutputPos);
        $context->builder->branch($execNext);

        $context->builder->positionAtEnd($checkIntLikeBb);
        $isC = self::matchesAny($context, $code, 'cC');
        $execCBb = $fn->appendBasicBlock('pack_exec_c');
        $checkShortExecBb = $fn->appendBasicBlock('pack_exec_check_short');
        $context->builder->branchIf($isC, $execCBb, $checkShortExecBb);

        $context->builder->positionAtEnd($execCBb);
        self::emitRepLoop(
            $context,
            $fn,
            $argSlotExec,
            function () use ($context, $argv, $execCurrentArg, $execOutputPos, $output, $machineLe, $oneI64): void {
                $i32 = $context->getTypeFromString('int32');
                $i64 = $context->getTypeFromString('int64');
                $current = $context->builder->load($execCurrentArg);
                $v = $context->builder->call(
                    $context->lookupFunction('__value__readLong'),
                    $context->builder->gep($argv, $context->builder->sext($current, $i64))
                );
                $context->builder->store($context->builder->add($current, $i32->constInt(1, false)), $execCurrentArg);
                $pos = $context->builder->load($execOutputPos);
                $context->builder->call(
                    $context->lookupFunction('__compiler_pack_put_long'),
                    $context->builder->gep($output, $pos),
                    $v,
                    $context->getTypeFromString('size_t')->constInt(1, false),
                    $machineLe
                );
                $context->builder->store($context->builder->add($pos, $oneI64), $execOutputPos);
            }
        );
        $context->builder->branch($execNext);

        $context->builder->positionAtEnd($checkShortExecBb);
        $isShortExec = self::matchesAny($context, $code, 'sSnv');
        $execShortBb = $fn->appendBasicBlock('pack_exec_short');
        $checkIntExecBb = $fn->appendBasicBlock('pack_exec_check_int');
        $context->builder->branchIf($isShortExec, $execShortBb, $checkIntExecBb);

        $context->builder->positionAtEnd($execShortBb);
        $isN = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('n'), false));
        $isV = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('v'), false));
        $le = $context->builder->select(
            $isN,
            $zeroI32,
            $context->builder->select($isV, $oneI32, $machineLe)
        );
        self::emitRepLoop(
            $context,
            $fn,
            $argSlotExec,
            function () use ($context, $argv, $execCurrentArg, $execOutputPos, $output, $le): void {
                $i32 = $context->getTypeFromString('int32');
                $i64 = $context->getTypeFromString('int64');
                $current = $context->builder->load($execCurrentArg);
                $v = $context->builder->call(
                    $context->lookupFunction('__value__readLong'),
                    $context->builder->gep($argv, $context->builder->sext($current, $i64))
                );
                $context->builder->store($context->builder->add($current, $i32->constInt(1, false)), $execCurrentArg);
                $pos = $context->builder->load($execOutputPos);
                $context->builder->call(
                    $context->lookupFunction('__compiler_pack_put_long'),
                    $context->builder->gep($output, $pos),
                    $v,
                    $context->getTypeFromString('size_t')->constInt(2, false),
                    $le
                );
                $context->builder->store($context->builder->add($pos, $i64->constInt(2, false)), $execOutputPos);
            }
        );
        $context->builder->branch($execNext);

        $context->builder->positionAtEnd($checkIntExecBb);
        $isIntExec = self::matchesAny($context, $code, 'iI');
        $execIntBb = $fn->appendBasicBlock('pack_exec_int');
        $checkLongExecBb = $fn->appendBasicBlock('pack_exec_check_long');
        $context->builder->branchIf($isIntExec, $execIntBb, $checkLongExecBb);

        $context->builder->positionAtEnd($execIntBb);
        self::emitRepLoop(
            $context,
            $fn,
            $argSlotExec,
            function () use ($context, $argv, $execCurrentArg, $execOutputPos, $output, $machineLe, $intSizeI64): void {
                $i32 = $context->getTypeFromString('int32');
                $i64 = $context->getTypeFromString('int64');
                $current = $context->builder->load($execCurrentArg);
                $v = $context->builder->call(
                    $context->lookupFunction('__value__readLong'),
                    $context->builder->gep($argv, $context->builder->sext($current, $i64))
                );
                $context->builder->store($context->builder->add($current, $i32->constInt(1, false)), $execCurrentArg);
                $pos = $context->builder->load($execOutputPos);
                $context->builder->call(
                    $context->lookupFunction('__compiler_pack_put_long'),
                    $context->builder->gep($output, $pos),
                    $v,
                    $context->builder->truncOrBitCast($intSizeI64, $context->getTypeFromString('size_t')),
                    $machineLe
                );
                $context->builder->store($context->builder->add($pos, $intSizeI64), $execOutputPos);
            }
        );
        $context->builder->branch($execNext);

        $context->builder->positionAtEnd($checkLongExecBb);
        $isLongExec = self::matchesAny($context, $code, 'lLNV');
        $execLongBb = $fn->appendBasicBlock('pack_exec_long');
        $checkQuadExecBb = $fn->appendBasicBlock('pack_exec_check_quad');
        $context->builder->branchIf($isLongExec, $execLongBb, $checkQuadExecBb);

        $context->builder->positionAtEnd($execLongBb);
        $isNLong = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('N'), false));
        $isVLong = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('V'), false));
        $leLong = $context->builder->select(
            $isNLong,
            $zeroI32,
            $context->builder->select($isVLong, $oneI32, $machineLe)
        );
        self::emitRepLoop(
            $context,
            $fn,
            $argSlotExec,
            function () use ($context, $argv, $execCurrentArg, $execOutputPos, $output, $leLong): void {
                $i32 = $context->getTypeFromString('int32');
                $i64 = $context->getTypeFromString('int64');
                $current = $context->builder->load($execCurrentArg);
                $v = $context->builder->call(
                    $context->lookupFunction('__value__readLong'),
                    $context->builder->gep($argv, $context->builder->sext($current, $i64))
                );
                $context->builder->store($context->builder->add($current, $i32->constInt(1, false)), $execCurrentArg);
                $pos = $context->builder->load($execOutputPos);
                $context->builder->call(
                    $context->lookupFunction('__compiler_pack_put_long'),
                    $context->builder->gep($output, $pos),
                    $v,
                    $context->getTypeFromString('size_t')->constInt(4, false),
                    $leLong
                );
                $context->builder->store($context->builder->add($pos, $i64->constInt(4, false)), $execOutputPos);
            }
        );
        $context->builder->branch($execNext);

        $context->builder->positionAtEnd($checkQuadExecBb);
        $isQuadExec = self::matchesAny($context, $code, 'qQJP');
        $execQuadBb = $fn->appendBasicBlock('pack_exec_quad');
        $checkFloatExecBb = $fn->appendBasicBlock('pack_exec_check_float');
        $context->builder->branchIf($isQuadExec, $execQuadBb, $checkFloatExecBb);

        $context->builder->positionAtEnd($execQuadBb);
        $isJ = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('J'), false));
        $isP = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('P'), false));
        $leQuad = $context->builder->select(
            $isJ,
            $zeroI32,
            $context->builder->select($isP, $oneI32, $machineLe)
        );
        self::emitRepLoop(
            $context,
            $fn,
            $argSlotExec,
            function () use ($context, $argv, $execCurrentArg, $execOutputPos, $output, $leQuad): void {
                $i32 = $context->getTypeFromString('int32');
                $i64 = $context->getTypeFromString('int64');
                $current = $context->builder->load($execCurrentArg);
                $v = $context->builder->call(
                    $context->lookupFunction('__value__readLong'),
                    $context->builder->gep($argv, $context->builder->sext($current, $i64))
                );
                $context->builder->store($context->builder->add($current, $i32->constInt(1, false)), $execCurrentArg);
                $pos = $context->builder->load($execOutputPos);
                $context->builder->call(
                    $context->lookupFunction('__compiler_pack_put_long'),
                    $context->builder->gep($output, $pos),
                    $v,
                    $context->getTypeFromString('size_t')->constInt(8, false),
                    $leQuad
                );
                $context->builder->store($context->builder->add($pos, $i64->constInt(8, false)), $execOutputPos);
            }
        );
        $context->builder->branch($execNext);

        $context->builder->positionAtEnd($checkFloatExecBb);
        $isF = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('f'), false));
        $isG = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('g'), false));
        $isBigG = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('G'), false));
        $isAnyFloat = $context->builder->or($isF, $context->builder->or($isG, $isBigG));
        $execFloatBb = $fn->appendBasicBlock('pack_exec_float');
        $checkDoubleExecBb = $fn->appendBasicBlock('pack_exec_check_double');
        $context->builder->branchIf($isAnyFloat, $execFloatBb, $checkDoubleExecBb);

        $context->builder->positionAtEnd($execFloatBb);
        self::emitRepLoop(
            $context,
            $fn,
            $argSlotExec,
            function () use ($context, $argv, $execCurrentArg, $execOutputPos, $output, $isF, $isG, $machineLe): void {
                $i32 = $context->getTypeFromString('int32');
                $i64 = $context->getTypeFromString('int64');
                $double = $context->getTypeFromString('double');
                $float = $context->getTypeFromString('float');
                $current = $context->builder->load($execCurrentArg);
                $dv = $context->builder->call(
                    $context->lookupFunction('__value__readDouble'),
                    $context->builder->gep($argv, $context->builder->sext($current, $i64))
                );
                $context->builder->store($context->builder->add($current, $i32->constInt(1, false)), $execCurrentArg);
                $pos = $context->builder->load($execOutputPos);
                $outPtr = $context->builder->gep($output, $pos);
                $rawBb = $context->builder->getInsertBlock()->getParent()->appendBasicBlock('pack_exec_float_raw_'.(++self::$blockSuffix));
                $endianBb = $context->builder->getInsertBlock()->getParent()->appendBasicBlock('pack_exec_float_endian_'.self::$blockSuffix);
                $afterBb = $context->builder->getInsertBlock()->getParent()->appendBasicBlock('pack_exec_float_after_'.self::$blockSuffix);
                $context->builder->branchIf($isF, $rawBb, $endianBb);

                $context->builder->positionAtEnd($rawBb);
                $mem = BasicBlockHelper::entryAlloca($context, $float);
                $context->builder->store($context->builder->fptrunc($dv, $float), $mem);
                $context->builder->call(
                    $context->lookupFunction('memcpy'),
                    $outPtr,
                    $mem,
                    $context->getTypeFromString('size_t')->constInt(4, false)
                );
                $context->builder->branch($afterBb);

                $context->builder->positionAtEnd($endianBb);
                $little = $context->builder->select($isG, $i32->constInt(1, false), $i32->constInt(0, false));
                $context->builder->call($context->lookupFunction('__compiler_pack_put_float'), $outPtr, $context->builder->fpext($context->builder->fptrunc($dv, $float), $double), $little);
                $context->builder->branch($afterBb);

                $context->builder->positionAtEnd($afterBb);
                $context->builder->store($context->builder->add($pos, $i64->constInt(4, false)), $execOutputPos);
            }
        );
        $context->builder->branch($execNext);

        $context->builder->positionAtEnd($checkDoubleExecBb);
        $isD = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('d'), false));
        $isE = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('e'), false));
        $isBigE = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('E'), false));
        $isAnyDouble = $context->builder->or($isD, $context->builder->or($isE, $isBigE));
        $execDoubleBb = $fn->appendBasicBlock('pack_exec_double');
        $checkXLowerBb = $fn->appendBasicBlock('pack_exec_check_x');
        $context->builder->branchIf($isAnyDouble, $execDoubleBb, $checkXLowerBb);

        $context->builder->positionAtEnd($execDoubleBb);
        self::emitRepLoop(
            $context,
            $fn,
            $argSlotExec,
            function () use ($context, $argv, $execCurrentArg, $execOutputPos, $output, $isD, $isE): void {
                $i32 = $context->getTypeFromString('int32');
                $i64 = $context->getTypeFromString('int64');
                $current = $context->builder->load($execCurrentArg);
                $dv = $context->builder->call(
                    $context->lookupFunction('__value__readDouble'),
                    $context->builder->gep($argv, $context->builder->sext($current, $i64))
                );
                $context->builder->store($context->builder->add($current, $i32->constInt(1, false)), $execCurrentArg);
                $pos = $context->builder->load($execOutputPos);
                $outPtr = $context->builder->gep($output, $pos);
                $rawBb = $context->builder->getInsertBlock()->getParent()->appendBasicBlock('pack_exec_double_raw_'.(++self::$blockSuffix));
                $endianBb = $context->builder->getInsertBlock()->getParent()->appendBasicBlock('pack_exec_double_endian_'.self::$blockSuffix);
                $afterBb = $context->builder->getInsertBlock()->getParent()->appendBasicBlock('pack_exec_double_after_'.self::$blockSuffix);
                $context->builder->branchIf($isD, $rawBb, $endianBb);

                $context->builder->positionAtEnd($rawBb);
                $mem = BasicBlockHelper::entryAlloca($context, $context->getTypeFromString('double'));
                $context->builder->store($dv, $mem);
                $context->builder->call(
                    $context->lookupFunction('memcpy'),
                    $context->builder->pointerCast($outPtr, $context->getTypeFromString('void*')),
                    $context->builder->pointerCast($mem, $context->getTypeFromString('void*')),
                    $context->getTypeFromString('size_t')->constInt(8, false)
                );
                $context->builder->branch($afterBb);

                $context->builder->positionAtEnd($endianBb);
                $little = $context->builder->select($isE, $i32->constInt(1, false), $i32->constInt(0, false));
                $context->builder->call($context->lookupFunction('__compiler_pack_put_double'), $outPtr, $dv, $little);
                $context->builder->branch($afterBb);

                $context->builder->positionAtEnd($afterBb);
                $context->builder->store($context->builder->add($pos, $i64->constInt(8, false)), $execOutputPos);
            }
        );
        $context->builder->branch($execNext);

        $context->builder->positionAtEnd($checkXLowerBb);
        $isXLower = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('x'), false));
        $execXLowerBb = $fn->appendBasicBlock('pack_exec_x_lower');
        $execUnknownBb = $fn->appendBasicBlock('pack_exec_unknown');
        $context->builder->branchIf($isXLower, $execXLowerBb, $execUnknownBb);

        $context->builder->positionAtEnd($execXLowerBb);
        $pos = $context->builder->load($execOutputPos);
        $arg64 = $context->builder->sext($arg, $i64);
        $context->builder->call(
            $context->lookupFunction('memset'),
            $context->builder->pointerCast($context->builder->gep($output, $pos), $voidPtr),
            $i32->constInt(0, false),
            $context->builder->truncOrBitCast($arg64, $sizeT)
        );
        $context->builder->store($context->builder->add($pos, $arg64), $execOutputPos);
        $context->builder->branch($execNext);

        $context->builder->positionAtEnd($execUnknownBb);
        $msg = self::snprintfAlloca($context, 'Type %c: unknown format code', [$code]);
        $fail = $context->builder->call($context->lookupFunction('__compiler_pack_fail'), $msg);
        $context->builder->call($context->lookupFunction('free'), $output);
        $context->builder->returnValue($fail);

        $context->builder->positionAtEnd($execNext);
        $context->builder->store($context->builder->add($context->builder->load($execI), $oneI32), $execI);
        $context->builder->branch($execHead);

        $context->builder->positionAtEnd($execDone);
        $finalPos = $context->builder->load($execOutputPos);
        $result = $context->builder->call($context->lookupFunction('__string__init'), $finalPos, $output);
        $context->builder->call($context->lookupFunction('free'), $output);
        $context->builder->returnValue($result);
    }

    /**
     * @param callable(): void $body
     */
    private static function emitRepLoop(
        Context $context,
        LlvmFunction $fn,
        Value $countSlot,
        callable $body
    ): void {
        $i32 = $context->getTypeFromString('int32');
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $head = $fn->appendBasicBlock('pack_rep_head_'.(++self::$blockSuffix));
        $loopBody = $fn->appendBasicBlock('pack_rep_body_'.self::$blockSuffix);
        $done = $fn->appendBasicBlock('pack_rep_done_'.self::$blockSuffix);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $rep = $context->builder->load($countSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGT, $rep, $zero),
            $loopBody,
            $done
        );

        $context->builder->positionAtEnd($loopBody);
        $body();
        $context->builder->store($context->builder->sub($context->builder->load($countSlot), $one), $countSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function isAsciiDigit(Context $context, Value $c): Value
    {
        $i8 = $context->getTypeFromString('int8');

        return $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $c, $i8->constInt((int) \ord('0'), false)),
            $context->builder->icmp(Builder::INT_SLE, $c, $i8->constInt((int) \ord('9'), false))
        );
    }

    private static function matchesAny(Context $context, Value $c, string $codes): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $match = $context->builder->icmp(Builder::INT_EQ, $c, $i8->constInt((int) \ord($codes[0]), false));
        for ($i = 1, $len = \strlen($codes); $i < $len; ++$i) {
            $match = $context->builder->or(
                $match,
                $context->builder->icmp(Builder::INT_EQ, $c, $i8->constInt((int) \ord($codes[$i]), false))
            );
        }

        return $match;
    }

    private static function hexNibble(Context $context, Value $c): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $cu = $context->builder->zext($c, $i32);
        $digit = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $c, $i8->constInt((int) \ord('0'), false)),
            $context->builder->icmp(Builder::INT_SLE, $c, $i8->constInt((int) \ord('9'), false))
        );
        $upper = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $c, $i8->constInt((int) \ord('A'), false)),
            $context->builder->icmp(Builder::INT_SLE, $c, $i8->constInt((int) \ord('F'), false))
        );
        $lower = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $c, $i8->constInt((int) \ord('a'), false)),
            $context->builder->icmp(Builder::INT_SLE, $c, $i8->constInt((int) \ord('f'), false))
        );
        $fromDigit = $context->builder->trunc(
            $context->builder->sub($cu, $i32->constInt((int) \ord('0'), false)),
            $i8
        );
        $fromUpper = $context->builder->trunc(
            $context->builder->add(
                $context->builder->sub($cu, $i32->constInt((int) \ord('A'), false)),
                $i32->constInt(10, false)
            ),
            $i8
        );
        $fromLower = $context->builder->trunc(
            $context->builder->add(
                $context->builder->sub($cu, $i32->constInt((int) \ord('a'), false)),
                $i32->constInt(10, false)
            ),
            $i8
        );

        return $context->builder->select(
            $digit,
            $fromDigit,
            $context->builder->select(
                $upper,
                $fromUpper,
                $context->builder->select($lower, $fromLower, $i8->constInt(0, false))
            )
        );
    }

    private static function bswap32(Context $context, Value $v): Value
    {
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->or(
            $context->builder->shl($context->builder->and($v, $i32->constInt(0xFF, false)), $i32->constInt(24, false)),
            $context->builder->or(
                $context->builder->shl($context->builder->and($v, $i32->constInt(0xFF00, false)), $i32->constInt(8, false)),
                $context->builder->or(
                    $context->builder->lShr($context->builder->and($v, $i32->constInt(0xFF0000, false)), $i32->constInt(8, false)),
                    $context->builder->lShr($context->builder->and($v, $i32->constInt(0xFF000000, false)), $i32->constInt(24, false))
                )
            )
        );
    }

    private static function bswap64(Context $context, Value $v): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $lo = $context->builder->trunc($v, $i32);
        $hi = $context->builder->trunc($context->builder->lShr($v, $i32->constInt(32, false)), $i32);

        return $context->builder->or(
            $context->builder->shl($context->builder->zExt(self::bswap32($context, $lo), $i64), $i32->constInt(32, false)),
            $context->builder->zExt(self::bswap32($context, $hi), $i64)
        );
    }

    private static function ensureLibc(Context $context): void
    {
        $voidPtr = $context->getTypeFromString('void*');
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $i8p;

        foreach (
            [
                ['malloc', $voidPtr, [$sizeT]],
                ['calloc', $voidPtr, [$sizeT, $sizeT]],
                ['free', $voidTy, [$i8p]],
                ['memcpy', $voidPtr, [$voidPtr, $voidPtr, $sizeT]],
                ['memset', $voidPtr, [$voidPtr, $i32, $sizeT]],
                ['strlen', $sizeT, [$i8p]],
                ['snprintf', $i32, [$charPtr, $sizeT, $charPtr]],
            ] as [$name, $ret, $params]
        ) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');
        $double = $context->getTypeFromString('double');

        foreach (
            [
                ['__string__init', $strPtr, [$i64, $i8p]],
                ['__string__separate', $strPtr, [$strPtr]],
                ['__value__readLong', $i64, [$valuePtr]],
                ['__value__readDouble', $double, [$valuePtr]],
                ['__value__readString', $strPtr, [$valuePtr]],
                ['__compiler_jit_raise_value_error', $voidTy, [$i8p, $sizeT]],
            ] as [$name, $ret, $params]
        ) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    /** @param list<Value> $extraArgs */
    private static function snprintfAlloca(Context $context, string $fmt, array $extraArgs): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $buf = $context->builder->alloca($i8, self::SNPRINTF_BUF, 'pack_snprintf');
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $fmtPtr = $context->builder->pointerCast($context->constantFromString($fmt), $i8p);
        $args = [$bufPtr, $context->getTypeFromString('size_t')->constInt(self::SNPRINTF_BUF, false), $fmtPtr];
        foreach ($extraArgs as $arg) {
            $args[] = $arg;
        }
        $context->builder->call($context->lookupFunction('snprintf'), ...$args);

        return $bufPtr;
    }

    private static function machineLe(): bool
    {
        return 0 !== \unpack('S', "\x00\x01")[1];
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
