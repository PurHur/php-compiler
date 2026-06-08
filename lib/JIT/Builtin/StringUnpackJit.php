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
 * LLVM __compiler_unpack (mirrors unpack_jit_runtime.c / ext/standard/UnpackEngine.php, #6306).
 *
 * php-src: ext/standard/pack.c — php_unpack()
 */
final class StringUnpackJit
{
    private const MAX_SPECS = 256;

    private const MAX_NAME = 64;

    private const SNPRINTF_BUF = 96;

    private const ERR_LEVEL = 2;

    private static int $blockSuffix = 0;

    public static function implement(Context $context): void
    {
        $restore = self::captureInsertBlock($context);

        $probe = $context->module->getNamedFunction('__compiler_unpack');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_unpack', $probe);
            self::restoreInsertBlock($context, $restore);

            return;
        }

        self::$blockSuffix = 0;
        self::ensureLibc($context);
        self::ensureRuntimeHelpers($context);

        self::implementIfMissing($context, '__compiler_unpack_fail', self::emitFail(...));
        self::implementIfMissing($context, '__compiler_unpack_read_long', self::emitReadLong(...));
        self::implementIfMissing($context, '__compiler_unpack_need_bytes', self::emitNeedBytes(...));
        self::implementIfMissing($context, '__compiler_unpack_is_code', self::emitIsCode(...));
        self::implementIfMissing($context, '__compiler_unpack_store_long', self::emitStoreLong(...));
        self::implementIfMissing($context, '__compiler_unpack_store_string', self::emitStoreString(...));
        self::implementIfMissing($context, '__compiler_unpack_hex', self::emitHex(...));
        self::implementIfMissing($context, '__compiler_unpack_parse_format', self::emitParseFormat(...));
        self::implementIfMissing($context, '__compiler_unpack_execute', self::emitExecute(...));
        self::implementIfMissing($context, '__compiler_unpack', self::emitUnpack(...));

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
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i32p = $context->getTypeFromString('int32*');
        $i64p = $context->getTypeFromString('int64*');

        return match ($name) {
            '__compiler_unpack_fail' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $valuePtr, $i8p)
            ),
            '__compiler_unpack_read_long' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $i8p, $i64, $i32, $i32)
            ),
            '__compiler_unpack_need_bytes' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $i8, $i32)
            ),
            '__compiler_unpack_is_code' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8)
            ),
            '__compiler_unpack_store_long' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $htPtr, $i32, $i8p, $i32p, $i64)
            ),
            '__compiler_unpack_store_string' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $htPtr, $i32, $i8p, $i32p, $strPtr)
            ),
            '__compiler_unpack_hex' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $i8p, $i64, $i32, $i32)
            ),
            '__compiler_unpack_parse_format' => $context->module->addFunction(
                $name,
                $context->context->functionType(
                    $i32,
                    false,
                    $i8p,
                    $i64,
                    $i8p,
                    $i64,
                    $i8p,
                    $i32p,
                    $i32p,
                    $i8p,
                    $i32p,
                    $valuePtr
                )
            ),
            '__compiler_unpack_execute' => $context->module->addFunction(
                $name,
                $context->context->functionType(
                    $i32,
                    false,
                    $i8p,
                    $i32,
                    $i32p,
                    $i32p,
                    $i8p,
                    $i64,
                    $i64p,
                    $htPtr,
                    $i32p,
                    $valuePtr
                )
            ),
            '__compiler_unpack' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $strPtr, $strPtr, $i64, $valuePtr)
            ),
            default => throw new \LogicException('Unknown unpack JIT helper: '.$name),
        };
    }

    private static function emitFail(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('upk_fail_entry');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $msg = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        $msgLen = $context->builder->call($context->lookupFunction('strlen'), $msg);
        $context->builder->call(
            $context->lookupFunction('__compiler_trigger_error'),
            $msg,
            $msgLen,
            $i32->constInt(self::ERR_LEVEL, false),
            $context->builder->pointerCast($context->constantFromString(''), $i8p),
            $i32->constInt(0, false)
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeBool'),
            $out,
            $i8->constInt(0, false)
        );
        $context->builder->returnVoid();
    }

    private static function emitReadLong(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('upk_rl_entry');
        $context->builder->positionAtEnd($entry);

        $bytes = $fn->getParam(0);
        $size = $fn->getParam(1);
        $littleEndian = $fn->getParam(2);
        $isSigned = $fn->getParam(3);

        $i8 = $context->getTypeFromString('int8');
        $i16 = $context->getTypeFromString('int16');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $i16p = $context->getTypeFromString('int16*');
        $i32p = $context->getTypeFromString('int32*');
        $i64p = $context->getTypeFromString('int64*');
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $four = $i64->constInt(4, false);
        $eight = $i64->constInt(8, false);
        $machineLe = $i32->constInt(self::machineLe() ? 1 : 0, false);

        $buf = $context->builder->alloca($i8, 8, 'upk_rl_buf');
        $context->builder->call(
            $context->lookupFunction('memset'),
            $context->bytePtr($buf),
            $i32->constInt(0, false),
            $sizeT->constInt(8, false)
        );
        $copySize = $context->builder->select(
            $context->builder->icmp(Builder::INT_UGT, $size, $eight),
            $eight,
            $size
        );
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->bytePtr($buf),
            $context->bytePtr($bytes),
            $context->builder->truncOrBitCast($copySize, $sizeT)
        );

        $leDiffers = $context->builder->icmp(
            Builder::INT_NE,
            $littleEndian,
            $machineLe
        );
        $swapBb = $fn->appendBasicBlock('upk_rl_swap');
        $afterSwapBb = $fn->appendBasicBlock('upk_rl_after_swap');
        $context->builder->branchIf($leDiffers, $swapBb, $afterSwapBb);

        $context->builder->positionAtEnd($swapBb);
        $is8 = $context->builder->icmp(Builder::INT_EQ, $size, $eight);
        $is4 = $context->builder->icmp(Builder::INT_EQ, $size, $four);
        $is2 = $context->builder->icmp(Builder::INT_EQ, $size, $two);
        $swap8Bb = $fn->appendBasicBlock('upk_rl_swap8');
        $check4Bb = $fn->appendBasicBlock('upk_rl_check4');
        $context->builder->branchIf($is8, $swap8Bb, $check4Bb);

        $context->builder->positionAtEnd($swap8Bb);
        $u64p = $context->builder->pointerCast($buf, $i64p);
        $u64 = $context->builder->load($u64p);
        $lo = $context->builder->trunc($u64, $i32);
        $hi = $context->builder->trunc($context->builder->lShr($u64, $i32->constInt(32, false)), $i32);
        $bswapLo = self::bswap32($context, $lo);
        $bswapHi = self::bswap32($context, $hi);
        $swapped = $context->builder->or(
            $context->builder->shl($context->builder->zExt($bswapLo, $i64), $i32->constInt(32, false)),
            $context->builder->zExt($bswapHi, $i64)
        );
        $context->builder->store($swapped, $u64p);
        $context->builder->branch($afterSwapBb);

        $context->builder->positionAtEnd($check4Bb);
        $swap4Bb = $fn->appendBasicBlock('upk_rl_swap4');
        $check2Bb = $fn->appendBasicBlock('upk_rl_check2');
        $context->builder->branchIf($is4, $swap4Bb, $check2Bb);

        $context->builder->positionAtEnd($swap4Bb);
        $u32p = $context->builder->pointerCast($buf, $i32p);
        $context->builder->store(self::bswap32($context, $context->builder->load($u32p)), $u32p);
        $context->builder->branch($afterSwapBb);

        $context->builder->positionAtEnd($check2Bb);
        $swap2Bb = $fn->appendBasicBlock('upk_rl_swap2');
        $skipSwapBb = $fn->appendBasicBlock('upk_rl_skip_swap');
        $context->builder->branchIf($is2, $swap2Bb, $skipSwapBb);

        $context->builder->positionAtEnd($swap2Bb);
        $u16p = $context->builder->pointerCast($buf, $i16p);
        $v16 = $context->builder->load($u16p);
        $context->builder->store(
            $context->builder->or(
                $context->builder->lShr($v16, $i16->constInt(8, false)),
                $context->builder->shl($v16, $i16->constInt(8, false))
            ),
            $u16p
        );
        $context->builder->branch($afterSwapBb);

        $context->builder->positionAtEnd($skipSwapBb);
        $context->builder->branch($afterSwapBb);

        $context->builder->positionAtEnd($afterSwapBb);
        $signedBb = $fn->appendBasicBlock('upk_rl_signed');
        $unsignedBb = $fn->appendBasicBlock('upk_rl_unsigned');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $isSigned, $i32->constInt(0, false)),
            $signedBb,
            $unsignedBb
        );

        $context->builder->positionAtEnd($signedBb);
        $is1 = $context->builder->icmp(Builder::INT_EQ, $size, $one);
        $is2s = $context->builder->icmp(Builder::INT_EQ, $size, $two);
        $is4s = $context->builder->icmp(Builder::INT_EQ, $size, $four);
        $is8s = $context->builder->icmp(Builder::INT_EQ, $size, $eight);
        $s1Bb = $fn->appendBasicBlock('upk_rl_s1');
        $sCheck2Bb = $fn->appendBasicBlock('upk_rl_s_check2');
        $context->builder->branchIf($is1, $s1Bb, $sCheck2Bb);

        $context->builder->positionAtEnd($s1Bb);
        $b0 = $context->builder->load($buf);
        $context->builder->returnValue($context->builder->sext($b0, $i64));

        $context->builder->positionAtEnd($sCheck2Bb);
        $s2Bb = $fn->appendBasicBlock('upk_rl_s2');
        $sCheck4Bb = $fn->appendBasicBlock('upk_rl_s_check4');
        $context->builder->branchIf($is2s, $s2Bb, $sCheck4Bb);

        $context->builder->positionAtEnd($s2Bb);
        $context->builder->returnValue(
            $context->builder->sext($context->builder->load($context->builder->pointerCast($buf, $i16p)), $i64)
        );

        $context->builder->positionAtEnd($sCheck4Bb);
        $s4Bb = $fn->appendBasicBlock('upk_rl_s4');
        $sCheck8Bb = $fn->appendBasicBlock('upk_rl_s_check8');
        $context->builder->branchIf($is4s, $s4Bb, $sCheck8Bb);

        $context->builder->positionAtEnd($s4Bb);
        $context->builder->returnValue(
            $context->builder->sext($context->builder->load($context->builder->pointerCast($buf, $i32p)), $i64)
        );

        $context->builder->positionAtEnd($sCheck8Bb);
        $s8Bb = $fn->appendBasicBlock('upk_rl_s8');
        $sZeroBb = $fn->appendBasicBlock('upk_rl_s_zero');
        $context->builder->branchIf($is8s, $s8Bb, $sZeroBb);

        $context->builder->positionAtEnd($s8Bb);
        $context->builder->returnValue(
            $context->builder->load($context->builder->pointerCast($buf, $i64p))
        );

        $context->builder->positionAtEnd($sZeroBb);
        $context->builder->returnValue($zero);

        $context->builder->positionAtEnd($unsignedBb);
        $u64Val = $context->builder->load($context->builder->pointerCast($buf, $i64p));
        $context->builder->returnValue($context->builder->trunc($u64Val, $i64));
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

    private static function emitNeedBytes(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('upk_nb_entry');
        $context->builder->positionAtEnd($entry);

        $code = $fn->getParam(0);
        $arg = $fn->getParam(1);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $negOne = $i64->constInt(-1, true);
        $zero = $i64->constInt(0, false);
        $two = $i64->constInt(2, false);
        $four = $i64->constInt(4, false);
        $eight = $i64->constInt(8, false);
        $intSize = $i64->constInt(\PHP_INT_SIZE, false);
        $arg64 = $context->builder->sext($arg, $i64);

        $hexNeed = $context->builder->add(
            $context->builder->unsignedDiv($arg64, $two),
            $context->builder->zext($context->builder->trunc($context->builder->unsignedRem($arg64, $two), $i32), $i64)
        );
        $result = $negOne;
        foreach (
            [
                'X' => $zero,
                '@' => $zero,
                'h' => $hexNeed,
                'H' => $hexNeed,
                'a' => $arg64,
                'A' => $arg64,
                'Z' => $arg64,
                'c' => $arg64,
                'C' => $arg64,
                'x' => $arg64,
                's' => $context->builder->mul($arg64, $two),
                'S' => $context->builder->mul($arg64, $two),
                'n' => $context->builder->mul($arg64, $two),
                'v' => $context->builder->mul($arg64, $two),
                'i' => $context->builder->mul($arg64, $intSize),
                'I' => $context->builder->mul($arg64, $intSize),
                'l' => $context->builder->mul($arg64, $four),
                'L' => $context->builder->mul($arg64, $four),
                'N' => $context->builder->mul($arg64, $four),
                'V' => $context->builder->mul($arg64, $four),
                'q' => $context->builder->mul($arg64, $eight),
                'Q' => $context->builder->mul($arg64, $eight),
                'J' => $context->builder->mul($arg64, $eight),
                'P' => $context->builder->mul($arg64, $eight),
            ] as $ch => $val
        ) {
            $eq = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord($ch), false));
            $result = $context->builder->select($eq, $val, $result);
        }

        $context->builder->returnValue($result);
    }

    private static function emitIsCode(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('upk_ic_entry');
        $context->builder->positionAtEnd($entry);

        $c = $fn->getParam(0);
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $codes = 'aAZhHcCsSiIlLnNvVqQJJPfgGdDeExX@';
        $isCode = $context->builder->icmp(Builder::INT_EQ, $c, $i8->constInt((int) \ord($codes[0]), false));
        for ($i = 1, $len = \strlen($codes); $i < $len; ++$i) {
            $isCode = $context->builder->or(
                $isCode,
                $context->builder->icmp(Builder::INT_EQ, $c, $i8->constInt((int) \ord($codes[$i]), false))
            );
        }
        $context->builder->returnValue($context->builder->zext($isCode, $i32));
    }

    private static function emitStoreLong(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('upk_sl_entry');
        $context->builder->positionAtEnd($entry);

        $ht = $fn->getParam(0);
        $hasName = $fn->getParam(1);
        $name = $fn->getParam(2);
        $autoIdx = $fn->getParam(3);
        $val = $fn->getParam(4);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');

        $namedBb = $fn->appendBasicBlock('upk_sl_named');
        $indexedBb = $fn->appendBasicBlock('upk_sl_indexed');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $hasName, $i32->constInt(0, false)),
            $namedBb,
            $indexedBb
        );

        $context->builder->positionAtEnd($namedBb);
        $nameLen = $context->builder->call($context->lookupFunction('strlen'), $name);
        $key = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->sext($nameLen, $i64),
            $name
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $key,
            $val
        );
        $doneBb = $fn->appendBasicBlock('upk_sl_done');
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($indexedBb);
        $idx = $context->builder->load($autoIdx);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setLongAt'),
            $ht,
            $context->builder->zext($idx, $sizeT),
            $val
        );
        $context->builder->store($context->builder->add($idx, $i32->constInt(1, false)), $autoIdx);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static function emitStoreString(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('upk_ss_entry');
        $context->builder->positionAtEnd($entry);

        $ht = $fn->getParam(0);
        $hasName = $fn->getParam(1);
        $name = $fn->getParam(2);
        $autoIdx = $fn->getParam(3);
        $val = $fn->getParam(4);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');

        $namedBb = $fn->appendBasicBlock('upk_ss_named');
        $indexedBb = $fn->appendBasicBlock('upk_ss_indexed');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_NE, $hasName, $i32->constInt(0, false)),
            $namedBb,
            $indexedBb
        );

        $context->builder->positionAtEnd($namedBb);
        $nameLen = $context->builder->call($context->lookupFunction('strlen'), $name);
        $key = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->sext($nameLen, $i64),
            $name
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $key,
            $val
        );
        $doneBb = $fn->appendBasicBlock('upk_ss_done');
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($indexedBb);
        $idx = $context->builder->load($autoIdx);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringAt'),
            $ht,
            $context->builder->zext($idx, $sizeT),
            $val
        );
        $context->builder->store($context->builder->add($idx, $i32->constInt(1, false)), $autoIdx);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $context->builder->returnVoid();
    }

    private static function emitHex(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('upk_hex_entry');
        $context->builder->positionAtEnd($entry);

        $input = $fn->getParam(0);
        $pos = $fn->getParam(1);
        $arg = $fn->getParam(2);
        $isH = $fn->getParam(3);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $arg64 = $context->builder->sext($arg, $i64);

        $buf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->add($context->builder->truncOrBitCast($arg64, $sizeT), $sizeT->constInt(1, false))
        );
        $bufPtr = $context->builder->pointerCast($buf, $i8p);
        $nSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($zero, $nSlot);

        $loopHead = $fn->appendBasicBlock('upk_hex_head');
        $loopBody = $fn->appendBasicBlock('upk_hex_body');
        $loopDone = $fn->appendBasicBlock('upk_hex_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $n = $context->builder->load($nSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $n, $arg),
            $loopBody,
            $loopDone
        );

        $context->builder->positionAtEnd($loopBody);
        $n = $context->builder->load($nSlot);
        $bi = $context->builder->unsignedDiv($context->builder->zext($n, $i64), $i64->constInt(2, false));
        $b = $context->builder->zext(
            $context->builder->load($context->builder->gep($input, $context->builder->add($pos, $bi))),
            $i32
        );
        $odd = $context->builder->and($n, $one);
        $highFirst = $context->builder->icmp(Builder::INT_NE, $isH, $zero);
        $highNibble = $context->builder->and($context->builder->lShr($b, $i32->constInt(4, false)), $i32->constInt(0xF, false));
        $lowNibble = $context->builder->and($b, $i32->constInt(0xF, false));
        $useHigh = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $odd, $zero),
            $highNibble,
            $lowNibble
        );
        $useLow = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $odd, $zero),
            $lowNibble,
            $highNibble
        );
        $nibble = $context->builder->select($highFirst, $useHigh, $useLow);
        $isDigit = $context->builder->icmp(Builder::INT_SLT, $nibble, $i32->constInt(10, false));
        $ch = $context->builder->select(
            $isDigit,
            $context->builder->add($nibble, $i32->constInt((int) \ord('0'), false)),
            $context->builder->add($nibble, $i32->constInt((int) \ord('a') - 10, false))
        );
        $context->builder->store($context->builder->trunc($ch, $i8), $context->builder->gep($bufPtr, $context->builder->zext($n, $i64)));
        $context->builder->store($context->builder->add($n, $one), $nSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->store($i8->constInt(0, false), $context->builder->gep($bufPtr, $arg64));
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $arg64,
            $bufPtr
        );
        $context->builder->call($context->lookupFunction('free'), $bufPtr);
        $context->builder->returnValue($result);
    }

    private static function emitParseFormat(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('upk_pf_entry');
        $context->builder->positionAtEnd($entry);

        $format = $fn->getParam(0);
        $formatLen = $fn->getParam(1);
        $codes = $fn->getParam(2);
        $args = $fn->getParam(3);
        $names = $fn->getParam(4);
        $hasNames = $fn->getParam(5);
        $specCount = $fn->getParam(6);
        $out = $fn->getParam(7);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $oneI32 = $i32->constInt(1, false);
        $zeroI32 = $i32->constInt(0, false);
        $maxSpecs = $i64->constInt(self::MAX_SPECS, false);
        $maxName = $i64->constInt(self::MAX_NAME, false);

        $iSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($zeroI64, $iSlot);
        $context->builder->store($zeroI32, $specCount);

        $loopHead = $fn->appendBasicBlock('upk_pf_head');
        $loopBody = $fn->appendBasicBlock('upk_pf_body');
        $loopDone = $fn->appendBasicBlock('upk_pf_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $count = $context->builder->load($specCount);
        $canLoop = $context->builder->and(
            $context->builder->icmp(Builder::INT_SLT, $i, $formatLen),
            $context->builder->icmp(Builder::INT_SLT, $context->builder->sext($count, $i64), $maxSpecs)
        );
        $context->builder->branchIf($canLoop, $loopBody, $loopDone);

        $context->builder->positionAtEnd($loopBody);
        $i = $context->builder->load($iSlot);
        $slashBb = $fn->appendBasicBlock('upk_pf_slash');
        $readCodeBb = $fn->appendBasicBlock('upk_pf_read_code');
        $isSlash = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($context->builder->gep($format, $i)),
            $i8->constInt((int) \ord('/'), false)
        );
        $context->builder->branchIf($isSlash, $slashBb, $readCodeBb);

        $context->builder->positionAtEnd($slashBb);
        $context->builder->store($context->builder->add($i, $oneI64), $iSlot);
        $context->builder->branch($readCodeBb);

        $context->builder->positionAtEnd($readCodeBb);
        $i = $context->builder->load($iSlot);
        $breakBb = $fn->appendBasicBlock('upk_pf_break');
        $haveCodeBb = $fn->appendBasicBlock('upk_pf_have_code');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $i, $formatLen),
            $breakBb,
            $haveCodeBb
        );

        $context->builder->positionAtEnd($haveCodeBb);
        $code = $context->builder->load($context->builder->gep($format, $i));
        $context->builder->store($context->builder->add($i, $oneI64), $iSlot);
        $argSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($oneI32, $argSlot);

        $i = $context->builder->load($iSlot);
        $parseArgBb = $fn->appendBasicBlock('upk_pf_parse_arg');
        $nameBb = $fn->appendBasicBlock('upk_pf_name');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $i, $formatLen),
            $parseArgBb,
            $nameBb
        );

        $context->builder->positionAtEnd($parseArgBb);
        $i = $context->builder->load($iSlot);
        $c = $context->builder->load($context->builder->gep($format, $i));
        $starBb = $fn->appendBasicBlock('upk_pf_star');
        $digitBb = $fn->appendBasicBlock('upk_pf_digit');
        $afterArgBb = $fn->appendBasicBlock('upk_pf_after_arg');
        $isStar = $context->builder->icmp(Builder::INT_EQ, $c, $i8->constInt((int) \ord('*'), false));
        $context->builder->branchIf($isStar, $starBb, $digitBb);

        $context->builder->positionAtEnd($starBb);
        $context->builder->store($i32->constInt(-1, true), $argSlot);
        $context->builder->store($context->builder->add($i, $oneI64), $iSlot);
        $context->builder->branch($afterArgBb);

        $context->builder->positionAtEnd($digitBb);
        $isDigit = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $c, $i8->constInt((int) \ord('0'), false)),
            $context->builder->icmp(Builder::INT_SLE, $c, $i8->constInt((int) \ord('9'), false))
        );
        $parseDigitsBb = $fn->appendBasicBlock('upk_pf_parse_digits');
        $context->builder->branchIf($isDigit, $parseDigitsBb, $afterArgBb);

        $context->builder->positionAtEnd($parseDigitsBb);
        $context->builder->store($zeroI32, $argSlot);
        $digitHead = $fn->appendBasicBlock('upk_pf_digit_head');
        $digitBody = $fn->appendBasicBlock('upk_pf_digit_body');
        $context->builder->branch($digitHead);
        $context->builder->positionAtEnd($digitHead);
        $i = $context->builder->load($iSlot);
        $context->builder->branchIf(
            $context->builder->and(
                $context->builder->icmp(Builder::INT_SLT, $i, $formatLen),
                $context->builder->and(
                    $context->builder->icmp(
                        Builder::INT_SGE,
                        $context->builder->load($context->builder->gep($format, $i)),
                        $i8->constInt((int) \ord('0'), false)
                    ),
                    $context->builder->icmp(
                        Builder::INT_SLE,
                        $context->builder->load($context->builder->gep($format, $i)),
                        $i8->constInt((int) \ord('9'), false)
                    )
                )
            ),
            $digitBody,
            $afterArgBb
        );
        $context->builder->positionAtEnd($digitBody);
        $i = $context->builder->load($iSlot);
        $digit = $context->builder->sub(
            $context->builder->zext($context->builder->load($context->builder->gep($format, $i)), $i32),
            $i32->constInt((int) \ord('0'), false)
        );
        $arg = $context->builder->load($argSlot);
        $context->builder->store(
            $context->builder->add($context->builder->mul($arg, $i32->constInt(10, false)), $digit),
            $argSlot
        );
        $context->builder->store($context->builder->add($i, $oneI64), $iSlot);
        $context->builder->branch($digitHead);

        $context->builder->positionAtEnd($afterArgBb);
        $context->builder->branch($nameBb);

        $context->builder->positionAtEnd($nameBb);
        $count = $context->builder->load($specCount);
        $nameBase = $context->builder->gep($names, $context->builder->mul($context->builder->sext($count, $i64), $maxName));
        $nlenSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($zeroI64, $nlenSlot);
        $context->builder->store($i8->constInt(0, false), $nameBase);

        $nameHead = $fn->appendBasicBlock('upk_pf_name_head');
        $nameBody = $fn->appendBasicBlock('upk_pf_name_body');
        $nameDone = $fn->appendBasicBlock('upk_pf_name_done');
        $context->builder->branch($nameHead);

        $context->builder->positionAtEnd($nameHead);
        $i = $context->builder->load($iSlot);
        $c = $context->builder->load($context->builder->gep($format, $i));
        $canName = $context->builder->and(
            $context->builder->icmp(Builder::INT_SLT, $i, $formatLen),
            $context->builder->icmp(Builder::INT_NE, $c, $i8->constInt((int) \ord('/'), false))
        );
        $context->builder->branchIf($canName, $nameBody, $nameDone);

        $context->builder->positionAtEnd($nameBody);
        $nlen = $context->builder->load($nlenSlot);
        $tooLong = $context->builder->icmp(Builder::INT_SGE, $nlen, $context->builder->sub($maxName, $oneI64));
        $tooLongBb = $fn->appendBasicBlock('upk_pf_name_too_long');
        $storeNameBb = $fn->appendBasicBlock('upk_pf_name_store');
        $context->builder->branchIf($tooLong, $tooLongBb, $storeNameBb);

        $context->builder->positionAtEnd($tooLongBb);
        $msg = $context->builder->pointerCast(
            $context->constantFromString('unpack(): Argument #1 ($format) contains name longer than 64 characters'),
            $i8p
        );
        $context->builder->call($context->lookupFunction('__compiler_unpack_fail'), $out, $msg);
        $context->builder->returnValue($zeroI32);

        $context->builder->positionAtEnd($storeNameBb);
        $i = $context->builder->load($iSlot);
        $nlen = $context->builder->load($nlenSlot);
        $c = $context->builder->load($context->builder->gep($format, $i));
        $context->builder->store($c, $context->builder->gep($nameBase, $nlen));
        $context->builder->store($context->builder->add($nlen, $oneI64), $nlenSlot);
        $context->builder->store($context->builder->add($i, $oneI64), $iSlot);
        $context->builder->branch($nameHead);

        $context->builder->positionAtEnd($nameDone);
        $nlen = $context->builder->load($nlenSlot);
        $context->builder->store($i8->constInt(0, false), $context->builder->gep($nameBase, $nlen));
        $validBb = $fn->appendBasicBlock('upk_pf_valid');
        $invalidBb = $fn->appendBasicBlock('upk_pf_invalid');
        $supported = self::isSupportedParseCode($context, $code);
        $context->builder->branchIf($supported, $validBb, $invalidBb);

        $context->builder->positionAtEnd($invalidBb);
        $msg = self::snprintfAlloca($context, 'unpack(): Type %c: unknown format code', [$code]);
        $context->builder->call($context->lookupFunction('__compiler_unpack_fail'), $out, $msg);
        $context->builder->returnValue($zeroI32);

        $context->builder->positionAtEnd($validBb);
        $count = $context->builder->load($specCount);
        $context->builder->store($code, $context->builder->gep($codes, $context->builder->sext($count, $i64)));
        $context->builder->store($context->builder->load($argSlot), $context->builder->gep($args, $count));
        $hasName = $context->builder->icmp(Builder::INT_UGT, $nlen, $zeroI64);
        $context->builder->store($context->builder->zext($hasName, $i32), $context->builder->gep($hasNames, $count));
        $context->builder->store($context->builder->add($count, $oneI32), $specCount);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($breakBb);
        $context->builder->branch($loopDone);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->returnValue($oneI32);
    }

    private static function isSupportedParseCode(Context $context, Value $code): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $codes = 'aAZhHcCsSiIlLnNvVqQJJPxX@fgGdDeE';
        $ok = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord($codes[0]), false));
        for ($i = 1, $len = \strlen($codes); $i < $len; ++$i) {
            $ok = $context->builder->or(
                $ok,
                $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord($codes[$i]), false))
            );
        }

        return $ok;
    }

    private static function emitExecute(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('upk_ex_entry');
        $context->builder->positionAtEnd($entry);

        $codes = $fn->getParam(0);
        $specCount = $fn->getParam(1);
        $args = $fn->getParam(2);
        $hasNames = $fn->getParam(3);
        $names = $fn->getParam(4);
        $input = $fn->getParam(5);
        $inputLen = $fn->getParam(6);
        $posSlot = $fn->getParam(7);
        $ht = $fn->getParam(8);
        $autoIdx = $fn->getParam(9);
        $out = $fn->getParam(10);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $oneI64 = $i64->constInt(1, false);
        $twoI64 = $i64->constInt(2, false);
        $fourI64 = $i64->constInt(4, false);
        $eightI64 = $i64->constInt(8, false);
        $intSizeI64 = $i64->constInt(\PHP_INT_SIZE, false);
        $maxName = $i64->constInt(self::MAX_NAME, false);
        $machineLe = $i32->constInt(self::machineLe() ? 1 : 0, false);
        $zeroI64 = $i64->constInt(0, false);

        $iSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($zeroI32, $iSlot);
        $effectiveArgSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $nextIterBb = $fn->appendBasicBlock('upk_ex_next');

        $loopHead = $fn->appendBasicBlock('upk_ex_head');
        $loopBody = $fn->appendBasicBlock('upk_ex_body');
        $loopDone = $fn->appendBasicBlock('upk_ex_done');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $i, $specCount),
            $loopBody,
            $loopDone
        );

        $context->builder->positionAtEnd($loopBody);
        $i = $context->builder->load($iSlot);
        $idx64 = $context->builder->sext($i, $i64);
        $code = $context->builder->load($context->builder->gep($codes, $idx64));
        $arg = $context->builder->load($context->builder->gep($args, $i));
        $hasName = $context->builder->load($context->builder->gep($hasNames, $i));
        $name = $context->builder->gep($names, $context->builder->mul($idx64, $maxName));
        $arg64 = $context->builder->sext($arg, $i64);
        $pos = $context->builder->load($posSlot);
        $remaining = $context->builder->sub($inputLen, $pos);
        $isStar = $context->builder->icmp(Builder::INT_SLT, $arg, $zeroI32);
        $context->builder->store($arg, $effectiveArgSlot);

        $starBb = $fn->appendBasicBlock('upk_ex_star');
        $normalNeedBb = $fn->appendBasicBlock('upk_ex_normal_need');
        $context->builder->branchIf($isStar, $starBb, $normalNeedBb);

        $context->builder->positionAtEnd($starBb);
        self::emitStarSpec(
            $context,
            $fn,
            $code,
            $remaining,
            $effectiveArgSlot,
            $input,
            $inputLen,
            $posSlot,
            $ht,
            $hasName,
            $name,
            $autoIdx,
            $machineLe,
            $oneI64,
            $twoI64,
            $fourI64,
            $eightI64,
            $intSizeI64,
            $out,
            $normalNeedBb,
            $nextIterBb
        );

        $context->builder->positionAtEnd($normalNeedBb);
        $arg = $context->builder->load($effectiveArgSlot);
        $arg64 = $context->builder->sext($arg, $i64);

        $need = $context->builder->call($context->lookupFunction('__compiler_unpack_need_bytes'), $code, $arg);
        $needFailBb = $fn->appendBasicBlock('upk_ex_need_fail');
        $dispatchBb = $fn->appendBasicBlock('upk_ex_dispatch');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $need, $i64->constInt(-1, true)),
            $needFailBb,
            $dispatchBb
        );

        $context->builder->positionAtEnd($needFailBb);
        $msg = self::snprintfAlloca($context, 'unpack(): Type %c: unknown format code', [$code]);
        $context->builder->call($context->lookupFunction('__compiler_unpack_fail'), $out, $msg);
        $context->builder->returnValue($zeroI32);

        $context->builder->positionAtEnd($dispatchBb);
        $isX = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('X'), false));
        $xBb = $fn->appendBasicBlock('upk_ex_x');
        $checkAtBb = $fn->appendBasicBlock('upk_ex_check_at');
        $context->builder->branchIf($isX, $xBb, $checkAtBb);

        $context->builder->positionAtEnd($xBb);
        $pos = $context->builder->load($posSlot);
        $newPos = $context->builder->select(
            $context->builder->icmp(Builder::INT_UGT, $arg64, $pos),
            $zeroI64,
            $context->builder->sub($pos, $arg64)
        );
        $context->builder->store($newPos, $posSlot);
        $context->builder->branch($nextIterBb);

        $context->builder->positionAtEnd($checkAtBb);
        $isAt = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('@'), false));
        $atBb = $fn->appendBasicBlock('upk_ex_at');
        $checkXLowerBb = $fn->appendBasicBlock('upk_ex_check_xlower');
        $context->builder->branchIf($isAt, $atBb, $checkXLowerBb);

        $context->builder->positionAtEnd($atBb);
        $newPos = $context->builder->select(
            $context->builder->icmp(Builder::INT_SGT, $arg, $zeroI32),
            $arg64,
            $zeroI64
        );
        $context->builder->store($newPos, $posSlot);
        $context->builder->branch($nextIterBb);

        $context->builder->positionAtEnd($checkXLowerBb);
        $isXLower = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('x'), false));
        $xLowerBb = $fn->appendBasicBlock('upk_ex_xlower');
        $checkDataBb = $fn->appendBasicBlock('upk_ex_check_data');
        $context->builder->branchIf($isXLower, $xLowerBb, $checkDataBb);

        $context->builder->positionAtEnd($xLowerBb);
        $pos = $context->builder->load($posSlot);
        $xFailBb = $fn->appendBasicBlock('upk_ex_x_fail');
        $xOkBb = $fn->appendBasicBlock('upk_ex_x_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_UGT, $context->builder->add($pos, $arg64), $inputLen),
            $xFailBb,
            $xOkBb
        );
        $context->builder->positionAtEnd($xFailBb);
        $msg = $context->builder->pointerCast(
            $context->constantFromString('unpack(): Type x: not enough input, need more bytes'),
            $i8p
        );
        $context->builder->call($context->lookupFunction('__compiler_unpack_fail'), $out, $msg);
        $context->builder->returnValue($zeroI32);
        $context->builder->positionAtEnd($xOkBb);
        $context->builder->store($context->builder->add($pos, $arg64), $posSlot);
        $context->builder->branch($nextIterBb);

        $context->builder->positionAtEnd($checkDataBb);
        $pos = $context->builder->load($posSlot);
        $insuffBb = $fn->appendBasicBlock('upk_ex_insufficient');
        $dataBb = $fn->appendBasicBlock('upk_ex_data');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_UGT, $context->builder->add($pos, $need), $inputLen),
            $insuffBb,
            $dataBb
        );
        $context->builder->positionAtEnd($insuffBb);
        $have = $context->builder->trunc($context->builder->sub($inputLen, $pos), $i32);
        $need32 = $context->builder->trunc($need, $i32);
        $msg = self::snprintfAlloca(
            $context,
            'unpack(): Type %c: not enough input, need %d, have %d',
            [$code, $need32, $have]
        );
        $context->builder->call($context->lookupFunction('__compiler_unpack_fail'), $out, $msg);
        $context->builder->returnValue($zeroI32);

        $context->builder->positionAtEnd($dataBb);
        $pos = $context->builder->load($posSlot);
        $unsupportedBb = $fn->appendBasicBlock('upk_ex_unsupported');
        $afterDataBb = $fn->appendBasicBlock('upk_ex_after_data');
        self::emitExecuteDataCase(
            $context,
            $fn,
            $code,
            $arg,
            $arg64,
            $hasName,
            $name,
            $input,
            $pos,
            $posSlot,
            $need,
            $ht,
            $autoIdx,
            $machineLe,
            $oneI64,
            $twoI64,
            $fourI64,
            $eightI64,
            $intSizeI64,
            $unsupportedBb,
            $afterDataBb
        );
        $context->builder->positionAtEnd($unsupportedBb);
        $msg = $context->builder->pointerCast(
            $context->constantFromString('unpack(): format not supported in this compiler build'),
            $i8p
        );
        $context->builder->call($context->lookupFunction('__compiler_unpack_fail'), $out, $msg);
        $context->builder->returnValue($zeroI32);
        $context->builder->positionAtEnd($afterDataBb);
        $context->builder->branch($nextIterBb);

        $context->builder->positionAtEnd($nextIterBb);
        $context->builder->store($context->builder->add($context->builder->load($iSlot), $oneI32), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $context->builder->returnValue($oneI32);
    }

    private static function emitExecuteDataCase(
        Context $context,
        LlvmFunction $fn,
        Value $code,
        Value $arg,
        Value $arg64,
        Value $hasName,
        Value $name,
        Value $input,
        Value $pos,
        Value $posSlot,
        Value $need,
        Value $ht,
        Value $autoIdx,
        Value $machineLe,
        Value $oneI64,
        Value $twoI64,
        Value $fourI64,
        Value $eightI64,
        Value $intSizeI64,
        BasicBlock $unsupportedBb,
        BasicBlock $afterDataBb
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $voidPtr = $context->getTypeFromString('void*');
        $sizeT = $context->getTypeFromString('size_t');
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $zeroI64 = $i64->constInt(0, false);
        $oneI64 = $i64->constInt(1, false);
        $isZ = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('Z'), false));
        $isA = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('a'), false)),
            $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('A'), false))
        );
        $isString = $context->builder->or($isA, $isZ);
        $strBb = $fn->appendBasicBlock('upk_ex_string');
        $checkHexBb = $fn->appendBasicBlock('upk_ex_check_hex');
        $context->builder->branchIf($isString, $strBb, $checkHexBb);

        $context->builder->positionAtEnd($strBb);
        $plainStrBb = $fn->appendBasicBlock('upk_ex_plain_str');
        $zStrBb = $fn->appendBasicBlock('upk_ex_z_str');
        $context->builder->branchIf($isZ, $zStrBb, $plainStrBb);

        $context->builder->positionAtEnd($plainStrBb);
        $slice = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $arg64,
            $context->builder->gep($input, $pos)
        );
        $context->builder->call(
            $context->lookupFunction('__compiler_unpack_store_string'),
            $ht,
            $hasName,
            $name,
            $autoIdx,
            $slice
        );
        $context->builder->store($context->builder->add($pos, $arg64), $posSlot);
        $context->builder->branch($afterDataBb);

        $context->builder->positionAtEnd($zStrBb);
        $zLenSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($zeroI64, $zLenSlot);
        $zHead = $fn->appendBasicBlock('upk_ex_z_head');
        $zBody = $fn->appendBasicBlock('upk_ex_z_body');
        $zDoneBb = $fn->appendBasicBlock('upk_ex_z_done');
        $context->builder->branch($zHead);
        $context->builder->positionAtEnd($zHead);
        $zIdx = $context->builder->load($zLenSlot);
        $zAtEnd = $context->builder->icmp(Builder::INT_SGE, $zIdx, $arg64);
        $zIsNull = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->load($context->builder->gep($input, $context->builder->add($pos, $zIdx))),
            $i8->constInt(0, false)
        );
        $context->builder->branchIf(
            $context->builder->or($zAtEnd, $zIsNull),
            $zDoneBb,
            $zBody
        );
        $context->builder->positionAtEnd($zBody);
        $context->builder->store($context->builder->add($zIdx, $oneI64), $zLenSlot);
        $context->builder->branch($zHead);
        $context->builder->positionAtEnd($zDoneBb);
        $zSlice = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $context->builder->load($zLenSlot),
            $context->builder->gep($input, $pos)
        );
        $context->builder->call(
            $context->lookupFunction('__compiler_unpack_store_string'),
            $ht,
            $hasName,
            $name,
            $autoIdx,
            $zSlice
        );
        $context->builder->store($context->builder->add($pos, $arg64), $posSlot);
        $context->builder->branch($afterDataBb);

        $context->builder->positionAtEnd($checkHexBb);
        $isHex = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('h'), false)),
            $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('H'), false))
        );
        $hexBb = $fn->appendBasicBlock('upk_ex_hex');
        $checkByteBb = $fn->appendBasicBlock('upk_ex_check_byte');
        $context->builder->branchIf($isHex, $hexBb, $checkByteBb);

        $context->builder->positionAtEnd($hexBb);
        $isH = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('H'), false));
        $hexStr = $context->builder->call(
            $context->lookupFunction('__compiler_unpack_hex'),
            $input,
            $pos,
            $arg,
            $context->builder->zext($isH, $i32)
        );
        $context->builder->call(
            $context->lookupFunction('__compiler_unpack_store_string'),
            $ht,
            $hasName,
            $name,
            $autoIdx,
            $hexStr
        );
        $context->builder->store($context->builder->add($pos, $need), $posSlot);
        $context->builder->branch($afterDataBb);

        $context->builder->positionAtEnd($checkByteBb);
        $isC = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('c'), false)),
            $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('C'), false))
        );
        $byteBb = $fn->appendBasicBlock('upk_ex_byte');
        $checkShortBb = $fn->appendBasicBlock('upk_ex_check_short');
        $context->builder->branchIf($isC, $byteBb, $checkShortBb);

        $context->builder->positionAtEnd($byteBb);
        $signed = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('c'), false));
        self::emitRepLoop(
            $context,
            $fn,
            $arg,
            $posSlot,
            $oneI64,
            function () use ($context, $input, $posSlot, $machineLe, $signed, $ht, $hasName, $name, $autoIdx, $oneI64): void {
                $pos = $context->builder->load($posSlot);
                $val = $context->builder->call(
                    $context->lookupFunction('__compiler_unpack_read_long'),
                    $context->builder->gep($input, $pos),
                    $oneI64,
                    $machineLe,
                    $context->builder->zext($signed, $context->getTypeFromString('int32'))
                );
                $context->builder->call(
                    $context->lookupFunction('__compiler_unpack_store_long'),
                    $ht,
                    $hasName,
                    $name,
                    $autoIdx,
                    $val
                );
                $context->builder->store($context->builder->add($pos, $oneI64), $posSlot);
            },
            $afterDataBb
        );

        $context->builder->positionAtEnd($checkShortBb);
        $isShort = $context->builder->or(
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('s'), false)),
                $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('S'), false))
            ),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('n'), false)),
                $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('v'), false))
            )
        );
        $shortBb = $fn->appendBasicBlock('upk_ex_short');
        $checkIntBb = $fn->appendBasicBlock('upk_ex_check_int');
        $context->builder->branchIf($isShort, $shortBb, $checkIntBb);

        $context->builder->positionAtEnd($shortBb);
        $signed = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('s'), false));
        $isN = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('n'), false));
        $isV = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('v'), false));
        $le = $context->builder->select(
            $isN,
            $i32->constInt(0, false),
            $context->builder->select($isV, $i32->constInt(1, false), $machineLe)
        );
        self::emitRepLoop(
            $context,
            $fn,
            $arg,
            $posSlot,
            $twoI64,
            function () use ($context, $input, $posSlot, $le, $signed, $ht, $hasName, $name, $autoIdx, $twoI64): void {
                $pos = $context->builder->load($posSlot);
                $val = $context->builder->call(
                    $context->lookupFunction('__compiler_unpack_read_long'),
                    $context->builder->gep($input, $pos),
                    $twoI64,
                    $le,
                    $context->builder->zext($signed, $context->getTypeFromString('int32'))
                );
                $context->builder->call(
                    $context->lookupFunction('__compiler_unpack_store_long'),
                    $ht,
                    $hasName,
                    $name,
                    $autoIdx,
                    $val
                );
                $context->builder->store($context->builder->add($pos, $twoI64), $posSlot);
            },
            $afterDataBb
        );

        $context->builder->positionAtEnd($checkIntBb);
        $isInt = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('i'), false)),
            $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('I'), false))
        );
        $intBb = $fn->appendBasicBlock('upk_ex_int');
        $checkLongBb = $fn->appendBasicBlock('upk_ex_check_long');
        $context->builder->branchIf($isInt, $intBb, $checkLongBb);

        $context->builder->positionAtEnd($intBb);
        $signed = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('i'), false));
        self::emitRepLoop(
            $context,
            $fn,
            $arg,
            $posSlot,
            $intSizeI64,
            function () use ($context, $input, $posSlot, $machineLe, $signed, $ht, $hasName, $name, $autoIdx, $intSizeI64): void {
                $pos = $context->builder->load($posSlot);
                $val = $context->builder->call(
                    $context->lookupFunction('__compiler_unpack_read_long'),
                    $context->builder->gep($input, $pos),
                    $intSizeI64,
                    $machineLe,
                    $context->builder->zext($signed, $context->getTypeFromString('int32'))
                );
                $context->builder->call(
                    $context->lookupFunction('__compiler_unpack_store_long'),
                    $ht,
                    $hasName,
                    $name,
                    $autoIdx,
                    $val
                );
                $context->builder->store($context->builder->add($pos, $intSizeI64), $posSlot);
            },
            $afterDataBb
        );

        $context->builder->positionAtEnd($checkLongBb);
        $isLong = $context->builder->or(
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('l'), false)),
                $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('L'), false))
            ),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('N'), false)),
                $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('V'), false))
            )
        );
        $longBb = $fn->appendBasicBlock('upk_ex_long');
        $checkQuadBb = $fn->appendBasicBlock('upk_ex_check_quad');
        $context->builder->branchIf($isLong, $longBb, $checkQuadBb);

        $context->builder->positionAtEnd($longBb);
        $signed = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('l'), false)),
            $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('L'), false))
        );
        $isN = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('N'), false));
        $isV = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('V'), false));
        $le = $context->builder->select(
            $isN,
            $i32->constInt(0, false),
            $context->builder->select($isV, $i32->constInt(1, false), $machineLe)
        );
        self::emitRepLoop(
            $context,
            $fn,
            $arg,
            $posSlot,
            $fourI64,
            function () use ($context, $input, $posSlot, $le, $signed, $ht, $hasName, $name, $autoIdx, $fourI64): void {
                $pos = $context->builder->load($posSlot);
                $val = $context->builder->call(
                    $context->lookupFunction('__compiler_unpack_read_long'),
                    $context->builder->gep($input, $pos),
                    $fourI64,
                    $le,
                    $context->builder->zext($signed, $context->getTypeFromString('int32'))
                );
                $context->builder->call(
                    $context->lookupFunction('__compiler_unpack_store_long'),
                    $ht,
                    $hasName,
                    $name,
                    $autoIdx,
                    $val
                );
                $context->builder->store($context->builder->add($pos, $fourI64), $posSlot);
            },
            $afterDataBb
        );

        $context->builder->positionAtEnd($checkQuadBb);
        $isQuad = $context->builder->or(
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('q'), false)),
                $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('Q'), false))
            ),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('J'), false)),
                $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('P'), false))
            )
        );
        $quadBb = $fn->appendBasicBlock('upk_ex_quad');
        $context->builder->branchIf($isQuad, $quadBb, $unsupportedBb);

        $context->builder->positionAtEnd($quadBb);
        $signed = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('q'), false)),
            $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('Q'), false))
        );
        $isJ = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('J'), false));
        $isP = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('P'), false));
        $le = $context->builder->select(
            $isJ,
            $i32->constInt(0, false),
            $context->builder->select($isP, $i32->constInt(1, false), $machineLe)
        );
        self::emitRepLoop(
            $context,
            $fn,
            $arg,
            $posSlot,
            $eightI64,
            function () use ($context, $input, $posSlot, $le, $signed, $ht, $hasName, $name, $autoIdx, $eightI64): void {
                $pos = $context->builder->load($posSlot);
                $val = $context->builder->call(
                    $context->lookupFunction('__compiler_unpack_read_long'),
                    $context->builder->gep($input, $pos),
                    $eightI64,
                    $le,
                    $context->builder->zext($signed, $context->getTypeFromString('int32'))
                );
                $context->builder->call(
                    $context->lookupFunction('__compiler_unpack_store_long'),
                    $ht,
                    $hasName,
                    $name,
                    $autoIdx,
                    $val
                );
                $context->builder->store($context->builder->add($pos, $eightI64), $posSlot);
            },
            $afterDataBb
        );
    }

    private static function emitStarSpec(
        Context $context,
        LlvmFunction $fn,
        Value $code,
        Value $remaining,
        Value $effectiveArgSlot,
        Value $input,
        Value $inputLen,
        Value $posSlot,
        Value $ht,
        Value $hasName,
        Value $name,
        Value $autoIdx,
        Value $machineLe,
        Value $oneI64,
        Value $twoI64,
        Value $fourI64,
        Value $eightI64,
        Value $intSizeI64,
        Value $out,
        BasicBlock $normalNeedBb,
        BasicBlock $nextIterBb
    ): void {
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $twoI32 = $i32->constInt(2, false);

        $isX = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('X'), false));
        $xStarBb = $fn->appendBasicBlock('upk_ex_star_x_upper');
        $checkStrBb = $fn->appendBasicBlock('upk_ex_star_check_str');
        $context->builder->branchIf($isX, $xStarBb, $checkStrBb);

        $context->builder->positionAtEnd($xStarBb);
        $msg = $context->builder->pointerCast(
            $context->constantFromString("unpack(): Type X: '*' ignored"),
            $context->getTypeFromString('int8*')
        );
        $context->builder->call($context->lookupFunction('__compiler_unpack_fail'), $out, $msg);
        $context->builder->store($oneI32, $effectiveArgSlot);
        $context->builder->branch($normalNeedBb);

        $context->builder->positionAtEnd($checkStrBb);
        $isAString = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('a'), false)),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('A'), false)),
                $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('Z'), false))
            )
        );
        $isHex = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('h'), false)),
            $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('H'), false))
        );
        $isStr = $context->builder->or($isAString, $isHex);
        $strStarBb = $fn->appendBasicBlock('upk_ex_star_str');
        $checkXLowerBb = $fn->appendBasicBlock('upk_ex_star_check_xlower');
        $context->builder->branchIf($isStr, $strStarBb, $checkXLowerBb);

        $context->builder->positionAtEnd($strStarBb);
        $resolved = $context->builder->select(
            $isHex,
            $context->builder->trunc($context->builder->mul($remaining, $twoI64), $i32),
            $context->builder->trunc($remaining, $i32)
        );
        $context->builder->store($resolved, $effectiveArgSlot);
        $context->builder->branch($normalNeedBb);

        $context->builder->positionAtEnd($checkXLowerBb);
        $isXLower = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('x'), false));
        $xStarBb = $fn->appendBasicBlock('upk_ex_star_xlower');
        $numStarBb = $fn->appendBasicBlock('upk_ex_star_num');
        $context->builder->branchIf($isXLower, $xStarBb, $numStarBb);

        $context->builder->positionAtEnd($xStarBb);
        $context->builder->store($context->builder->trunc($remaining, $i32), $effectiveArgSlot);
        $context->builder->branch($normalNeedBb);

        $context->builder->positionAtEnd($numStarBb);
        $isC = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('c'), false)),
            $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('C'), false))
        );
        $byteBb = $fn->appendBasicBlock('upk_ex_star_byte');
        $checkShortBb = $fn->appendBasicBlock('upk_ex_star_check_short');
        $context->builder->branchIf($isC, $byteBb, $checkShortBb);

        $context->builder->positionAtEnd($byteBb);
        $signed = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('c'), false));
        self::emitStarRepLoop(
            $context,
            $fn,
            $oneI64,
            $input,
            $inputLen,
            $posSlot,
            function () use ($context, $input, $posSlot, $machineLe, $signed, $ht, $hasName, $name, $autoIdx, $oneI64): void {
                $pos = $context->builder->load($posSlot);
                $val = $context->builder->call(
                    $context->lookupFunction('__compiler_unpack_read_long'),
                    $context->builder->gep($input, $pos),
                    $oneI64,
                    $machineLe,
                    $context->builder->zext($signed, $context->getTypeFromString('int32'))
                );
                $context->builder->call(
                    $context->lookupFunction('__compiler_unpack_store_long'),
                    $ht,
                    $hasName,
                    $name,
                    $autoIdx,
                    $val
                );
                $context->builder->store($context->builder->add($pos, $oneI64), $posSlot);
            },
            $nextIterBb
        );

        $context->builder->positionAtEnd($checkShortBb);
        $isShort = $context->builder->or(
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('s'), false)),
                $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('S'), false))
            ),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('n'), false)),
                $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('v'), false))
            )
        );
        $shortBb = $fn->appendBasicBlock('upk_ex_star_short');
        $checkIntBb = $fn->appendBasicBlock('upk_ex_star_check_int');
        $context->builder->branchIf($isShort, $shortBb, $checkIntBb);

        $context->builder->positionAtEnd($shortBb);
        $signed = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('s'), false));
        $isN = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('n'), false));
        $isV = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('v'), false));
        $le = $context->builder->select(
            $isN,
            $zeroI32,
            $context->builder->select($isV, $oneI32, $machineLe)
        );
        self::emitStarRepLoop(
            $context,
            $fn,
            $twoI64,
            $input,
            $inputLen,
            $posSlot,
            function () use ($context, $input, $posSlot, $le, $signed, $ht, $hasName, $name, $autoIdx, $twoI64): void {
                $pos = $context->builder->load($posSlot);
                $val = $context->builder->call(
                    $context->lookupFunction('__compiler_unpack_read_long'),
                    $context->builder->gep($input, $pos),
                    $twoI64,
                    $le,
                    $context->builder->zext($signed, $context->getTypeFromString('int32'))
                );
                $context->builder->call(
                    $context->lookupFunction('__compiler_unpack_store_long'),
                    $ht,
                    $hasName,
                    $name,
                    $autoIdx,
                    $val
                );
                $context->builder->store($context->builder->add($pos, $twoI64), $posSlot);
            },
            $nextIterBb
        );

        $context->builder->positionAtEnd($checkIntBb);
        $isInt = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('i'), false)),
            $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('I'), false))
        );
        $intBb = $fn->appendBasicBlock('upk_ex_star_int');
        $checkLongBb = $fn->appendBasicBlock('upk_ex_star_check_long');
        $context->builder->branchIf($isInt, $intBb, $checkLongBb);

        $context->builder->positionAtEnd($intBb);
        $signed = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('i'), false));
        self::emitStarRepLoop(
            $context,
            $fn,
            $intSizeI64,
            $input,
            $inputLen,
            $posSlot,
            function () use ($context, $input, $posSlot, $machineLe, $signed, $ht, $hasName, $name, $autoIdx, $intSizeI64): void {
                $pos = $context->builder->load($posSlot);
                $val = $context->builder->call(
                    $context->lookupFunction('__compiler_unpack_read_long'),
                    $context->builder->gep($input, $pos),
                    $intSizeI64,
                    $machineLe,
                    $context->builder->zext($signed, $context->getTypeFromString('int32'))
                );
                $context->builder->call(
                    $context->lookupFunction('__compiler_unpack_store_long'),
                    $ht,
                    $hasName,
                    $name,
                    $autoIdx,
                    $val
                );
                $context->builder->store($context->builder->add($pos, $intSizeI64), $posSlot);
            },
            $nextIterBb
        );

        $context->builder->positionAtEnd($checkLongBb);
        $isLong = $context->builder->or(
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('l'), false)),
                $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('L'), false))
            ),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('N'), false)),
                $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('V'), false))
            )
        );
        $longBb = $fn->appendBasicBlock('upk_ex_star_long');
        $quadBb = $fn->appendBasicBlock('upk_ex_star_quad');
        $context->builder->branchIf($isLong, $longBb, $quadBb);

        $context->builder->positionAtEnd($longBb);
        $signed = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('l'), false)),
            $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('L'), false))
        );
        $isN = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('N'), false));
        $isV = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('V'), false));
        $le = $context->builder->select(
            $isN,
            $zeroI32,
            $context->builder->select($isV, $oneI32, $machineLe)
        );
        self::emitStarRepLoop(
            $context,
            $fn,
            $fourI64,
            $input,
            $inputLen,
            $posSlot,
            function () use ($context, $input, $posSlot, $le, $signed, $ht, $hasName, $name, $autoIdx, $fourI64): void {
                $pos = $context->builder->load($posSlot);
                $val = $context->builder->call(
                    $context->lookupFunction('__compiler_unpack_read_long'),
                    $context->builder->gep($input, $pos),
                    $fourI64,
                    $le,
                    $context->builder->zext($signed, $context->getTypeFromString('int32'))
                );
                $context->builder->call(
                    $context->lookupFunction('__compiler_unpack_store_long'),
                    $ht,
                    $hasName,
                    $name,
                    $autoIdx,
                    $val
                );
                $context->builder->store($context->builder->add($pos, $fourI64), $posSlot);
            },
            $nextIterBb
        );

        $context->builder->positionAtEnd($quadBb);
        $signed = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('q'), false)),
            $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('Q'), false))
        );
        $isJ = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('J'), false));
        $isP = $context->builder->icmp(Builder::INT_EQ, $code, $i8->constInt((int) \ord('P'), false));
        $le = $context->builder->select(
            $isJ,
            $zeroI32,
            $context->builder->select($isP, $oneI32, $machineLe)
        );
        self::emitStarRepLoop(
            $context,
            $fn,
            $eightI64,
            $input,
            $inputLen,
            $posSlot,
            function () use ($context, $input, $posSlot, $le, $signed, $ht, $hasName, $name, $autoIdx, $eightI64): void {
                $pos = $context->builder->load($posSlot);
                $val = $context->builder->call(
                    $context->lookupFunction('__compiler_unpack_read_long'),
                    $context->builder->gep($input, $pos),
                    $eightI64,
                    $le,
                    $context->builder->zext($signed, $context->getTypeFromString('int32'))
                );
                $context->builder->call(
                    $context->lookupFunction('__compiler_unpack_store_long'),
                    $ht,
                    $hasName,
                    $name,
                    $autoIdx,
                    $val
                );
                $context->builder->store($context->builder->add($pos, $eightI64), $posSlot);
            },
            $nextIterBb
        );
    }

    /**
     * @param callable(): void $body
     */
    private static function emitStarRepLoop(
        Context $context,
        LlvmFunction $fn,
        Value $step,
        Value $input,
        Value $inputLen,
        Value $posSlot,
        callable $body,
        BasicBlock $doneBb
    ): void {
        $head = $fn->appendBasicBlock('upk_star_head_'.(++self::$blockSuffix));
        $bodyBb = $fn->appendBasicBlock('upk_star_body_'.self::$blockSuffix);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $pos = $context->builder->load($posSlot);
        $left = $context->builder->sub($inputLen, $pos);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $left, $step),
            $bodyBb,
            $doneBb
        );

        $context->builder->positionAtEnd($bodyBb);
        $body();
        $context->builder->branch($head);
    }

    /**
     * @param callable(): void $body
     */
    private static function emitRepLoop(
        Context $context,
        LlvmFunction $fn,
        Value $arg,
        Value $posSlot,
        Value $step,
        callable $body,
        BasicBlock $doneBb
    ): void {
        $i32 = $context->getTypeFromString('int32');
        $zero = $i32->constInt(0, false);
        $one = $i32->constInt(1, false);
        $repSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($arg, $repSlot);

        $head = $fn->appendBasicBlock('upk_rep_head_'.(++self::$blockSuffix));
        $bodyBb = $fn->appendBasicBlock('upk_rep_body_'.self::$blockSuffix);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $rep = $context->builder->load($repSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGT, $rep, $zero),
            $bodyBb,
            $doneBb
        );

        $context->builder->positionAtEnd($bodyBb);
        $body();
        $context->builder->store($context->builder->sub($context->builder->load($repSlot), $one), $repSlot);
        $context->builder->branch($head);
    }

    private static function emitUnpack(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('upk_entry');
        $context->builder->positionAtEnd($entry);

        $fmt = $fn->getParam(0);
        $data = $fn->getParam(1);
        $offset = $fn->getParam(2);
        $out = $fn->getParam(3);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $map = $context->structFieldMap['__string__'];
        $zeroI64 = $i64->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);

        $fmtNullBb = $fn->appendBasicBlock('upk_fmt_null');
        $dataCheckBb = $fn->appendBasicBlock('upk_data_check');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $fmt, $strPtr->constNull()),
            $fmtNullBb,
            $dataCheckBb
        );

        $context->builder->positionAtEnd($fmtNullBb);
        $msg = $context->builder->pointerCast(
            $context->constantFromString('unpack(): Argument #1 ($format) must be of type string'),
            $i8p
        );
        $context->builder->call($context->lookupFunction('__compiler_unpack_fail'), $out, $msg);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($dataCheckBb);
        $dataNullBb = $fn->appendBasicBlock('upk_data_null');
        $workBb = $fn->appendBasicBlock('upk_work');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $data, $strPtr->constNull()),
            $dataNullBb,
            $workBb
        );

        $context->builder->positionAtEnd($dataNullBb);
        $msg = $context->builder->pointerCast(
            $context->constantFromString('unpack(): Argument #2 ($data) must be of type string'),
            $i8p
        );
        $context->builder->call($context->lookupFunction('__compiler_unpack_fail'), $out, $msg);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($workBb);
        $fmtSep = $context->builder->call($context->lookupFunction('__string__separate'), $fmt);
        $dataSep = $context->builder->call($context->lookupFunction('__string__separate'), $data);
        $format = $context->builder->structGep($fmtSep, $map['value']);
        $formatLen = $context->builder->load($context->builder->structGep($fmtSep, $map['length']));
        $input = $context->builder->structGep($dataSep, $map['value']);
        $inputLen = $context->builder->load($context->builder->structGep($dataSep, $map['length']));

        $offsetBadBb = $fn->appendBasicBlock('upk_offset_bad');
        $parseBb = $fn->appendBasicBlock('upk_parse');
        $offsetNeg = $context->builder->icmp(Builder::INT_SLT, $offset, $zeroI64);
        $offsetPast = $context->builder->icmp(Builder::INT_UGT, $offset, $inputLen);
        $context->builder->branchIf(
            $context->builder->or($offsetNeg, $offsetPast),
            $offsetBadBb,
            $parseBb
        );

        $context->builder->positionAtEnd($offsetBadBb);
        $msg = $context->builder->pointerCast(
            $context->constantFromString('unpack(): Argument #3 ($offset) must be contained in argument #2 ($data)'),
            $i8p
        );
        $context->builder->call($context->lookupFunction('__compiler_unpack_fail'), $out, $msg);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($parseBb);
        $codes = $context->builder->alloca($i8, self::MAX_SPECS, 'upk_codes');
        $args = $context->builder->alloca($i32, self::MAX_SPECS, 'upk_args');
        $hasNames = $context->builder->alloca($i32, self::MAX_SPECS, 'upk_has_names');
        $names = $context->builder->alloca($i8, self::MAX_SPECS * self::MAX_NAME, 'upk_names');
        $specCount = $context->builder->alloca($i32, 1, 'upk_spec_count');
        $context->builder->store($i32->constInt(0, false), $specCount);

        $parsed = $context->builder->call(
            $context->lookupFunction('__compiler_unpack_parse_format'),
            $format,
            $formatLen,
            $codes,
            $args,
            $names,
            $hasNames,
            $specCount,
            $out
        );
        $parseFailBb = $fn->appendBasicBlock('upk_parse_fail');
        $execBb = $fn->appendBasicBlock('upk_exec');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $parsed, $i32->constInt(0, false)),
            $parseFailBb,
            $execBb
        );
        $context->builder->positionAtEnd($parseFailBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($execBb);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $posSlot = $context->builder->alloca($i64, 1, 'upk_pos');
        $autoIdx = $context->builder->alloca($i32, 1, 'upk_auto_idx');
        $context->builder->store($offset, $posSlot);
        $context->builder->store($oneI32, $autoIdx);

        $executed = $context->builder->call(
            $context->lookupFunction('__compiler_unpack_execute'),
            $codes,
            $context->builder->load($specCount),
            $args,
            $hasNames,
            $names,
            $input,
            $inputLen,
            $posSlot,
            $ht,
            $autoIdx,
            $out
        );
        $execFailBb = $fn->appendBasicBlock('upk_exec_fail');
        $successBb = $fn->appendBasicBlock('upk_success');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $executed, $i32->constInt(0, false)),
            $execFailBb,
            $successBb
        );
        $context->builder->positionAtEnd($execFailBb);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($successBb);
        $context->builder->call($context->lookupFunction('__value__writeHashtable'), $out, $ht);
        $context->builder->returnVoid();
    }

    private static function ensureLibc(Context $context): void
    {
        $voidPtr = $context->getTypeFromString('void*');
        $voidTy = $context->getTypeFromString('void');
        $sizeT = $context->getTypeFromString('size_t');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $i8p;

        foreach (
            [
                ['malloc', $voidPtr, [$sizeT]],
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
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $voidTy = $context->getTypeFromString('void');

        foreach (
            [
                ['__hashtable__alloc', $htPtr, []],
                ['__hashtable__setStringKeyLong', $voidTy, [$htPtr, $strPtr, $i64]],
                ['__hashtable__setStringKeyString', $voidTy, [$htPtr, $strPtr, $strPtr]],
                ['__hashtable__setLongAt', $voidTy, [$htPtr, $sizeT, $i64]],
                ['__hashtable__setStringAt', $voidTy, [$htPtr, $sizeT, $strPtr]],
                ['__string__init', $strPtr, [$i64, $i8p]],
                ['__string__separate', $strPtr, [$strPtr]],
                ['__value__writeBool', $voidTy, [$valuePtr, $i8]],
                ['__value__writeHashtable', $voidTy, [$valuePtr, $htPtr]],
                ['__compiler_trigger_error', $voidTy, [$i8p, $sizeT, $i32, $i8p, $i32]],
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
        $buf = $context->builder->alloca($i8, self::SNPRINTF_BUF, 'upk_snprintf');
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
