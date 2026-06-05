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
 * LLVM base_convert helpers (mirrors VmMath / former phpc_base_convert.c, #5197).
 *
 * php-src: ext/standard/math.c — _php_math_basetozval, _php_math_zvaltobase
 */
final class MathBaseConvertJit
{
    private const INT64_MAX = 9223372036854775807;

    private const DIGITS = '0123456789abcdefghijklmnopqrstuvwxyz';

    public static function implement(Context $context): void
    {
        $restore = self::captureInsertBlock($context);

        if (self::alreadyImplemented($context, 'phpc_base_convert')) {
            self::registerAll($context);
            self::restoreInsertBlock($context, $restore);

            return;
        }

        self::implementIfMissing($context, '__phpc_digit_value', self::emitDigitValue(...));
        self::implementIfMissing($context, '__phpc_char_isspace', self::emitCharIsspace(...));
        self::implementIfMissing($context, '__phpc_basetozval_core', self::emitBaseToZvalCore(...));
        self::implementIfMissing($context, '__phpc_doubletobase', self::emitDoubleToBase(...));
        self::implementIfMissing($context, 'phpc_longtobase_str', self::emitLongToBaseStr(...));
        self::implementIfMissing($context, 'phpc_basetozval_result', self::emitBaseToZvalResult(...));
        self::implementIfMissing($context, 'phpc_basetozval_write', self::emitBaseToZvalWrite(...));
        self::implementIfMissing($context, 'phpc_base_convert', self::emitBaseConvert(...));

        self::restoreInsertBlock($context, $restore);
    }

    private static function alreadyImplemented(Context $context, string $name): bool
    {
        $probe = $context->module->getNamedFunction($name);

        return null !== $probe && $probe->countBasicBlocks() > 0;
    }

    private static function registerAll(Context $context): void
    {
        foreach ([
            '__phpc_digit_value',
            '__phpc_char_isspace',
            '__phpc_basetozval_core',
            '__phpc_doubletobase',
            'phpc_longtobase_str',
            'phpc_basetozval_result',
            'phpc_basetozval_write',
            'phpc_base_convert',
        ] as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null !== $fn) {
                $context->registerFunction($name, $fn);
            }
        }
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
        $void = $context->context->voidType();
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $i64Ptr = $context->getTypeFromString('int64*');
        $doublePtr = $context->getTypeFromString('double*');
        $i32Ptr = $context->getTypeFromString('int32*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');

        return match ($name) {
            '__phpc_digit_value' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8)
            ),
            '__phpc_char_isspace' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8)
            ),
            '__phpc_basetozval_core' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $i8p, $i64, $i64Ptr, $doublePtr, $i32Ptr)
            ),
            '__phpc_doubletobase' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $double, $i32)
            ),
            'phpc_longtobase_str' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $i64, $i32)
            ),
            'phpc_basetozval_result' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i8p, $i64, $i64Ptr, $doublePtr)
            ),
            'phpc_basetozval_write' => $context->module->addFunction(
                $name,
                $context->context->functionType($void, false, $valuePtr, $i8p, $i64)
            ),
            'phpc_base_convert' => $context->module->addFunction(
                $name,
                $context->context->functionType($strPtr, false, $i8p, $i64, $i64)
            ),
            default => throw new \LogicException('Unknown base_convert JIT helper: '.$name),
        };
    }

    private static function emitDigitValue(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $c = $fn->getParam(0);
        $negOne = $i32->constInt(-1, true);

        $isDigit = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $c, $i8->constInt((int) ord('0'), false)),
            $context->builder->icmp(Builder::INT_SLE, $c, $i8->constInt((int) ord('9'), false))
        );
        $digitBb = $fn->appendBasicBlock('digit');
        $checkUpperBb = $fn->appendBasicBlock('check_upper');
        $context->builder->branchIf($isDigit, $digitBb, $checkUpperBb);

        $context->builder->positionAtEnd($digitBb);
        $context->builder->returnValue(
            $context->builder->sub(
                $context->builder->zExt($c, $i32),
                $i32->constInt((int) ord('0'), false)
            )
        );

        $context->builder->positionAtEnd($checkUpperBb);
        $isUpper = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $c, $i8->constInt((int) ord('A'), false)),
            $context->builder->icmp(Builder::INT_SLE, $c, $i8->constInt((int) ord('Z'), false))
        );
        $upperBb = $fn->appendBasicBlock('upper');
        $checkLowerBb = $fn->appendBasicBlock('check_lower');
        $context->builder->branchIf($isUpper, $upperBb, $checkLowerBb);

        $context->builder->positionAtEnd($upperBb);
        $context->builder->returnValue(
            $context->builder->add(
                $context->builder->sub(
                    $context->builder->zExt($c, $i32),
                    $i32->constInt((int) ord('A'), false)
                ),
                $i32->constInt(10, false)
            )
        );

        $context->builder->positionAtEnd($checkLowerBb);
        $isLower = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $c, $i8->constInt((int) ord('a'), false)),
            $context->builder->icmp(Builder::INT_SLE, $c, $i8->constInt((int) ord('z'), false))
        );
        $lowerBb = $fn->appendBasicBlock('lower');
        $failBb = $fn->appendBasicBlock('fail');
        $context->builder->branchIf($isLower, $lowerBb, $failBb);

        $context->builder->positionAtEnd($lowerBb);
        $context->builder->returnValue(
            $context->builder->add(
                $context->builder->sub(
                    $context->builder->zExt($c, $i32),
                    $i32->constInt((int) ord('a'), false)
                ),
                $i32->constInt(10, false)
            )
        );

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($negOne);
    }

    private static function emitCharIsspace(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $c = $fn->getParam(0);
        $one = $i32->constInt(1, false);
        $zero = $i32->constInt(0, false);

        $isSpace = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $c, $i8->constInt(0x20, false)),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $c, $i8->constInt(0x09, false)),
                $context->builder->or(
                    $context->builder->icmp(Builder::INT_EQ, $c, $i8->constInt(0x0a, false)),
                    $context->builder->or(
                        $context->builder->icmp(Builder::INT_EQ, $c, $i8->constInt(0x0d, false)),
                        $context->builder->or(
                            $context->builder->icmp(Builder::INT_EQ, $c, $i8->constInt(0x0c, false)),
                            $context->builder->icmp(Builder::INT_EQ, $c, $i8->constInt(0x0b, false))
                        )
                    )
                )
            )
        );

        $context->builder->returnValue($context->builder->select($isSpace, $one, $zero));
    }

    private static function emitBaseToZvalCore(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $sizeT = $context->getTypeFromString('size_t');
        $nullPtr = $i8p->constNull();
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $oneI32 = $i32->constInt(1, false);
        $zeroI32 = $i32->constInt(0, false);
        $zeroD = $double->constReal(0.0);
        $int64Max = $i64->constInt(self::INT64_MAX, false);

        $strIn = $fn->getParam(0);
        $baseIn = $fn->getParam(1);
        $outLong = $fn->getParam(2);
        $outDouble = $fn->getParam(3);
        $isDoubleOut = $fn->getParam(4);

        $emptySlot = $context->builder->alloca($i8, 1);
        $context->builder->store($i8->constInt(0, false), $emptySlot);
        $emptyPtr = $context->builder->pointerCast($emptySlot, $i8p);
        $strIsNull = $context->builder->icmp(Builder::INT_EQ, $strIn, $nullPtr);
        $strSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $context->builder->store($context->builder->select($strIsNull, $emptyPtr, $strIn), $strSlot);
        $str = $context->builder->load($strSlot);

        $len = $context->builder->zExt($context->builder->call($context->lookupFunction('strlen'), $str), $i64);
        $startSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $endSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($zero, $startSlot);
        $context->builder->store($len, $endSlot);

        $trimStartHead = $fn->appendBasicBlock('btz_trim_start_head');
        $trimStartBody = $fn->appendBasicBlock('btz_trim_start_body');
        $trimStartDone = $fn->appendBasicBlock('btz_trim_start_done');
        $context->builder->branch($trimStartHead);

        $context->builder->positionAtEnd($trimStartHead);
        $start = $context->builder->load($startSlot);
        $end = $context->builder->load($endSlot);
        $doneStart = $context->builder->icmp(Builder::INT_SGE, $start, $end);
        $context->builder->branchIf($doneStart, $trimStartDone, $trimStartBody);

        $context->builder->positionAtEnd($trimStartBody);
        $start = $context->builder->load($startSlot);
        $ch = $context->builder->load($context->builder->gep($str, $context->builder->trunc($start, $i32)));
        $isSpace = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('__phpc_char_isspace'), $ch),
            $zeroI32
        );
        $trimStartCont = $fn->appendBasicBlock('btz_trim_start_cont');
        $context->builder->branchIf($isSpace, $trimStartCont, $trimStartDone);

        $context->builder->positionAtEnd($trimStartCont);
        $context->builder->store($context->builder->add($start, $one), $startSlot);
        $context->builder->branch($trimStartHead);

        $context->builder->positionAtEnd($trimStartDone);
        $trimEndHead = $fn->appendBasicBlock('btz_trim_end_head');
        $trimEndBody = $fn->appendBasicBlock('btz_trim_end_body');
        $trimEndDone = $fn->appendBasicBlock('btz_trim_end_done');
        $context->builder->branch($trimEndHead);

        $context->builder->positionAtEnd($trimEndHead);
        $start = $context->builder->load($startSlot);
        $end = $context->builder->load($endSlot);
        $doneEnd = $context->builder->icmp(Builder::INT_SGE, $start, $end);
        $context->builder->branchIf($doneEnd, $trimEndDone, $trimEndBody);

        $context->builder->positionAtEnd($trimEndBody);
        $end = $context->builder->load($endSlot);
        $prev = $context->builder->sub($end, $one);
        $ch = $context->builder->load($context->builder->gep($str, $context->builder->trunc($prev, $i32)));
        $isSpace = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call($context->lookupFunction('__phpc_char_isspace'), $ch),
            $zeroI32
        );
        $trimEndCont = $fn->appendBasicBlock('btz_trim_end_cont');
        $context->builder->branchIf($isSpace, $trimEndCont, $trimEndDone);

        $context->builder->positionAtEnd($trimEndCont);
        $context->builder->store($prev, $endSlot);
        $context->builder->branch($trimEndHead);

        $context->builder->positionAtEnd($trimEndDone);
        $prefixBb = $fn->appendBasicBlock('btz_prefix');
        $loopInitBb = $fn->appendBasicBlock('btz_loop_init');
        $context->builder->branch($prefixBb);

        $context->builder->positionAtEnd($prefixBb);
        $start = $context->builder->load($startSlot);
        $end = $context->builder->load($endSlot);
        $span = $context->builder->sub($end, $start);
        $hasPrefix = $context->builder->icmp(Builder::INT_SGE, $span, $i64->constInt(2, false));
        $skipPrefix = $fn->appendBasicBlock('btz_skip_prefix');
        $noPrefix = $fn->appendBasicBlock('btz_no_prefix');
        $context->builder->branchIf($hasPrefix, $skipPrefix, $noPrefix);

        $context->builder->positionAtEnd($skipPrefix);
        $c0 = $context->builder->load($context->builder->gep($str, $context->builder->trunc($start, $i32)));
        $c1 = $context->builder->load($context->builder->gep($str, $context->builder->trunc($context->builder->add($start, $one), $i32)));
        $isZero = $context->builder->icmp(Builder::INT_EQ, $c0, $i8->constInt((int) ord('0'), false));
        $base16 = $context->builder->icmp(Builder::INT_EQ, $baseIn, $i64->constInt(16, false));
        $base8 = $context->builder->icmp(Builder::INT_EQ, $baseIn, $i64->constInt(8, false));
        $base2 = $context->builder->icmp(Builder::INT_EQ, $baseIn, $i64->constInt(2, false));
        $isX = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $c1, $i8->constInt((int) ord('x'), false)),
            $context->builder->icmp(Builder::INT_EQ, $c1, $i8->constInt((int) ord('X'), false))
        );
        $isO = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $c1, $i8->constInt((int) ord('o'), false)),
            $context->builder->icmp(Builder::INT_EQ, $c1, $i8->constInt((int) ord('O'), false))
        );
        $isB = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $c1, $i8->constInt((int) ord('b'), false)),
            $context->builder->icmp(Builder::INT_EQ, $c1, $i8->constInt((int) ord('B'), false))
        );
        $skip16 = $context->builder->and($context->builder->and($base16, $isZero), $isX);
        $skip8 = $context->builder->and($context->builder->and($base8, $isZero), $isO);
        $skip2 = $context->builder->and($context->builder->and($base2, $isZero), $isB);
        $doSkip = $context->builder->or($skip16, $context->builder->or($skip8, $skip2));
        $afterSkip = $fn->appendBasicBlock('btz_after_skip');
        $context->builder->branchIf($doSkip, $afterSkip, $noPrefix);

        $context->builder->positionAtEnd($afterSkip);
        $context->builder->store($context->builder->add($start, $i64->constInt(2, false)), $startSlot);
        $context->builder->branch($loopInitBb);

        $context->builder->positionAtEnd($noPrefix);
        $context->builder->branch($loopInitBb);

        $context->builder->positionAtEnd($loopInitBb);
        $numSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $fnumSlot = BasicBlockHelper::entryAlloca($context, $double);
        $modeSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $iSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($zero, $numSlot);
        $context->builder->store($zeroD, $fnumSlot);
        $context->builder->store($zeroI32, $modeSlot);
        $context->builder->store($context->builder->load($startSlot), $iSlot);

        $cutoff = $context->builder->signedDiv($int64Max, $baseIn);
        $cutlim = $context->builder->trunc($context->builder->signedRem($int64Max, $baseIn), $i32);

        $loopHead = $fn->appendBasicBlock('btz_loop_head');
        $loopBody = $fn->appendBasicBlock('btz_loop_body');
        $loopDigit = $fn->appendBasicBlock('btz_loop_digit');
        $loopLong = $fn->appendBasicBlock('btz_loop_long');
        $loopDouble = $fn->appendBasicBlock('btz_loop_double');
        $loopNext = $fn->appendBasicBlock('btz_loop_next');
        $loopDone = $fn->appendBasicBlock('btz_loop_done');
        $retLong = $fn->appendBasicBlock('btz_ret_long');
        $retDouble = $fn->appendBasicBlock('btz_ret_double');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $i = $context->builder->load($iSlot);
        $end = $context->builder->load($endSlot);
        $loopEnd = $context->builder->icmp(Builder::INT_SGE, $i, $end);
        $context->builder->branchIf($loopEnd, $loopDone, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $ch = $context->builder->load($context->builder->gep($str, $context->builder->trunc($i, $i32)));
        $digit = $context->builder->call($context->lookupFunction('__phpc_digit_value'), $ch);
        $negOne = $i32->constInt(-1, true);
        $badDigit = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $digit, $negOne),
            $context->builder->icmp(Builder::INT_SGE, $digit, $context->builder->trunc($baseIn, $i32))
        );
        $context->builder->branchIf($badDigit, $loopNext, $loopDigit);

        $context->builder->positionAtEnd($loopDigit);
        $mode = $context->builder->load($modeSlot);
        $inLongMode = $context->builder->icmp(Builder::INT_EQ, $mode, $zeroI32);
        $context->builder->branchIf($inLongMode, $loopLong, $loopDouble);

        $context->builder->positionAtEnd($loopLong);
        $num = $context->builder->load($numSlot);
        $fits = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLT, $num, $cutoff),
            $context->builder->and(
                $context->builder->icmp(Builder::INT_EQ, $num, $cutoff),
                $context->builder->icmp(Builder::INT_SLE, $digit, $cutlim)
            )
        );
        $toDouble = $fn->appendBasicBlock('btz_to_double');
        $stayLong = $fn->appendBasicBlock('btz_stay_long');
        $context->builder->branchIf($fits, $stayLong, $toDouble);

        $context->builder->positionAtEnd($toDouble);
        $num = $context->builder->load($numSlot);
        $context->builder->store($context->builder->siToFp($num, $double), $fnumSlot);
        $context->builder->store($oneI32, $modeSlot);
        $context->builder->branch($loopDouble);

        $context->builder->positionAtEnd($stayLong);
        $num = $context->builder->load($numSlot);
        $nextNum = $context->builder->add(
            $context->builder->mul($num, $baseIn),
            $context->builder->sext($digit, $i64)
        );
        $context->builder->store($nextNum, $numSlot);
        $context->builder->branch($loopNext);

        $context->builder->positionAtEnd($loopDouble);
        $fnum = $context->builder->load($fnumSlot);
        $baseD = $context->builder->siToFp($baseIn, $double);
        $digitD = $context->builder->siToFp($digit, $double);
        $context->builder->store(
            $context->builder->fAdd(
                $context->builder->fMul($fnum, $baseD),
                $digitD
            ),
            $fnumSlot
        );
        $context->builder->branch($loopNext);

        $context->builder->positionAtEnd($loopNext);
        $context->builder->store($context->builder->add($context->builder->load($iSlot), $one), $iSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopDone);
        $mode = $context->builder->load($modeSlot);
        $isDbl = $context->builder->icmp(Builder::INT_EQ, $mode, $oneI32);
        $context->builder->branchIf($isDbl, $retDouble, $retLong);

        $context->builder->positionAtEnd($retDouble);
        $context->builder->store($context->builder->load($fnumSlot), $outDouble);
        $context->builder->store($oneI32, $isDoubleOut);
        $context->builder->store($zero, $outLong);
        $context->builder->returnVoid();

        $context->builder->positionAtEnd($retLong);
        $context->builder->store($context->builder->load($numSlot), $outLong);
        $context->builder->store($zeroD, $outDouble);
        $context->builder->store($zeroI32, $isDoubleOut);
        $context->builder->returnVoid();
    }

    private static function emitBaseToZvalResult(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');

        $lvalSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $dvalSlot = BasicBlockHelper::entryAlloca($context, $double);
        $isDoubleSlot = BasicBlockHelper::entryAlloca($context, $i32);

        $context->builder->call(
            $context->lookupFunction('__phpc_basetozval_core'),
            $fn->getParam(0),
            $fn->getParam(1),
            $lvalSlot,
            $dvalSlot,
            $isDoubleSlot
        );

        $outLong = $fn->getParam(2);
        $outDouble = $fn->getParam(3);
        $nullLong = $context->builder->icmp(Builder::INT_EQ, $outLong, $context->getTypeFromString('int64*')->constNull());
        $nullDbl = $context->builder->icmp(Builder::INT_EQ, $outDouble, $context->getTypeFromString('double*')->constNull());
        $storeLong = $fn->appendBasicBlock('bzr_store_long');
        $skipLong = $fn->appendBasicBlock('bzr_skip_long');
        $storeDbl = $fn->appendBasicBlock('bzr_store_dbl');
        $done = $fn->appendBasicBlock('bzr_done');
        $context->builder->branchIf($nullLong, $skipLong, $storeLong);

        $context->builder->positionAtEnd($storeLong);
        $context->builder->store($context->builder->load($lvalSlot), $outLong);
        $context->builder->branch($skipLong);

        $context->builder->positionAtEnd($skipLong);
        $context->builder->branchIf($nullDbl, $done, $storeDbl);

        $context->builder->positionAtEnd($storeDbl);
        $context->builder->store($context->builder->load($dvalSlot), $outDouble);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($context->builder->load($isDoubleSlot));
    }

    private static function emitBaseToZvalWrite(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $out = $fn->getParam(0);
        $nullOut = $context->builder->icmp(
            Builder::INT_EQ,
            $out,
            $context->getTypeFromString('__value__*')->constNull()
        );
        $retBb = $fn->appendBasicBlock('bzw_ret');
        $bodyBb = $fn->appendBasicBlock('bzw_body');
        $context->builder->branchIf($nullOut, $retBb, $bodyBb);

        $context->builder->positionAtEnd($bodyBb);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $lvalSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $dvalSlot = BasicBlockHelper::entryAlloca($context, $double);
        $isDoubleSlot = BasicBlockHelper::entryAlloca($context, $i32);

        $context->builder->call(
            $context->lookupFunction('__phpc_basetozval_core'),
            $fn->getParam(1),
            $fn->getParam(2),
            $lvalSlot,
            $dvalSlot,
            $isDoubleSlot
        );

        $isDbl = $context->builder->icmp(Builder::INT_NE, $context->builder->load($isDoubleSlot), $i32->constInt(0, false));
        $dblBb = $fn->appendBasicBlock('bzw_dbl');
        $longBb = $fn->appendBasicBlock('bzw_long');
        $context->builder->branchIf($isDbl, $dblBb, $longBb);

        $context->builder->positionAtEnd($dblBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeDouble'),
            $out,
            $context->builder->load($dvalSlot)
        );
        $context->builder->branch($retBb);

        $context->builder->positionAtEnd($longBb);
        $context->builder->call(
            $context->lookupFunction('__value__writeLong'),
            $out,
            $context->builder->load($lvalSlot)
        );
        $context->builder->branch($retBb);

        $context->builder->positionAtEnd($retBb);
        $context->builder->returnVoid();
    }

    private static function emitLongToBaseStr(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $arg = $fn->getParam(0);
        $base = $fn->getParam(1);

        $valueSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $negSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $bufSlot = $context->builder->alloca($i8, 128, 'ltb_buf');
        $endSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $ptrSlot = BasicBlockHelper::entryAlloca($context, $i8p);

        $badBase = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLT, $base, $i32->constInt(2, false)),
            $context->builder->icmp(Builder::INT_SGT, $base, $i32->constInt(36, false))
        );
        $emptyBb = $fn->appendBasicBlock('ltb_empty');
        $zeroBb = $fn->appendBasicBlock('ltb_zero');
        $negBb = $fn->appendBasicBlock('ltb_neg');
        $posBb = $fn->appendBasicBlock('ltb_pos');
        $context->builder->branchIf($badBase, $emptyBb, $zeroBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue(self::emptyString($context));

        $context->builder->positionAtEnd($zeroBb);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $arg, $i64->constInt(0, false));
        $retZeroBb = $fn->appendBasicBlock('ltb_ret_zero');
        $nonZero = $fn->appendBasicBlock('ltb_nonzero');
        $context->builder->branchIf($isZero, $retZeroBb, $nonZero);

        $context->builder->positionAtEnd($retZeroBb);
        $context->builder->returnValue(self::stringInitFromLiteral($context, '0'));

        $context->builder->positionAtEnd($nonZero);
        $isNeg = $context->builder->icmp(Builder::INT_SLT, $arg, $i64->constInt(0, false));
        $context->builder->branchIf($isNeg, $negBb, $posBb);

        $context->builder->positionAtEnd($negBb);
        $context->builder->store($i32->constInt(1, false), $negSlot);
        $abs = $context->builder->sub($i64->constInt(0, false), $arg);
        $context->builder->store($abs, $valueSlot);
        $loopBb = $fn->appendBasicBlock('ltb_loop');
        $context->builder->branch($loopBb);

        $context->builder->positionAtEnd($posBb);
        $context->builder->store($i32->constInt(0, false), $negSlot);
        $context->builder->store($arg, $valueSlot);
        $context->builder->branch($loopBb);

        $context->builder->positionAtEnd($loopBb);
        $endPtr = $context->builder->inBoundsGEP($bufSlot, $i64->constInt(127, false));
        $context->builder->store($i8->constInt(0, false), $endPtr);
        $context->builder->store($endPtr, $endSlot);
        $context->builder->store($endPtr, $ptrSlot);

        $digitsPtr = self::digitsPtr($context);
        $baseU = $context->builder->zExt($base, $i64);
        $loopHead = $fn->appendBasicBlock('ltb_loop_head');
        $loopBody = $fn->appendBasicBlock('ltb_loop_body');
        $afterLoop = $fn->appendBasicBlock('ltb_after_loop');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $val = $context->builder->load($valueSlot);
        $cont = $context->builder->icmp(Builder::INT_UGT, $val, $i64->constInt(0, false));
        $ptr = $context->builder->load($ptrSlot);
        $bufStart = $context->builder->pointerCast($bufSlot, $i8p);
        $hasRoom = $context->builder->icmp(Builder::INT_UGT, $ptr, $bufStart);
        $keepGoing = $context->builder->and($cont, $hasRoom);
        $context->builder->branchIf($keepGoing, $loopBody, $afterLoop);

        $context->builder->positionAtEnd($loopBody);
        $val = $context->builder->load($valueSlot);
        $rem = $context->builder->unsigendRem($val, $baseU);
        $digitCh = $context->builder->load($context->builder->gep($digitsPtr, $context->builder->trunc($rem, $i32)));
        $ptr = $context->builder->load($ptrSlot);
        $prev = $context->builder->inBoundsGEP($ptr, $i64->constInt(-1, true));
        $context->builder->store($digitCh, $prev);
        $context->builder->store($prev, $ptrSlot);
        $context->builder->store($context->builder->unsignedDiv($val, $baseU), $valueSlot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($afterLoop);
        $isNegFlag = $context->builder->icmp(Builder::INT_NE, $context->builder->load($negSlot), $i32->constInt(0, false));
        $addSign = $fn->appendBasicBlock('ltb_add_sign');
        $finish = $fn->appendBasicBlock('ltb_finish');
        $context->builder->branchIf($isNegFlag, $addSign, $finish);

        $context->builder->positionAtEnd($addSign);
        $ptr = $context->builder->load($ptrSlot);
        $prev = $context->builder->inBoundsGEP($ptr, $i64->constInt(-1, true));
        $context->builder->store($i8->constInt((int) ord('-'), false), $prev);
        $context->builder->store($prev, $ptrSlot);
        $context->builder->branch($finish);

        $context->builder->positionAtEnd($finish);
        $ptr = $context->builder->load($ptrSlot);
        $end = $context->builder->load($endSlot);
        $len = $context->builder->sub(
            $context->builder->ptrToInt($end, $i64),
            $context->builder->ptrToInt($ptr, $i64)
        );
        $context->builder->returnValue(
            $context->builder->call($context->lookupFunction('__string__init'), $len, $ptr)
        );
    }

    private static function emitDoubleToBase(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $fvalue = $fn->getParam(0);
        $base = $fn->getParam(1);

        $negSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $fslot = BasicBlockHelper::entryAlloca($context, $double);
        $bufSlot = $context->builder->alloca($i8, 128, 'dtb_buf');
        $endSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $ptrSlot = BasicBlockHelper::entryAlloca($context, $i8p);

        $badBase = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLT, $base, $i32->constInt(2, false)),
            $context->builder->icmp(Builder::INT_SGT, $base, $i32->constInt(36, false))
        );
        $emptyBb = $fn->appendBasicBlock('dtb_empty');
        $checkBb = $fn->appendBasicBlock('dtb_check');
        $context->builder->branchIf($badBase, $emptyBb, $checkBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue(self::emptyString($context));

        $context->builder->positionAtEnd($checkBb);
        $floored = $context->builder->call($context->lookupFunction('floor'), $fvalue);
        $isZero = $context->builder->fcmp(Builder::REAL_OEQ, $floored, $double->constReal(0.0));
        $zeroBb = $fn->appendBasicBlock('dtb_zero');
        $workBb = $fn->appendBasicBlock('dtb_work');
        $context->builder->branchIf($isZero, $zeroBb, $workBb);

        $context->builder->positionAtEnd($zeroBb);
        $context->builder->returnValue(self::stringInitFromLiteral($context, '0'));

        $context->builder->positionAtEnd($workBb);
        $isNeg = $context->builder->fcmp(Builder::REAL_OLT, $fvalue, $double->constReal(0.0));
        $negBb = $fn->appendBasicBlock('dtb_neg');
        $posBb = $fn->appendBasicBlock('dtb_pos');
        $context->builder->branchIf($isNeg, $negBb, $posBb);

        $context->builder->positionAtEnd($negBb);
        $context->builder->store($i32->constInt(1, false), $negSlot);
        $context->builder->store($context->builder->fNegate($fvalue), $fslot);
        $loopInit = $fn->appendBasicBlock('dtb_loop_init');
        $context->builder->branch($loopInit);

        $context->builder->positionAtEnd($posBb);
        $context->builder->store($i32->constInt(0, false), $negSlot);
        $context->builder->store($fvalue, $fslot);
        $context->builder->branch($loopInit);

        $context->builder->positionAtEnd($loopInit);
        $endPtr = $context->builder->inBoundsGEP($bufSlot, $i64->constInt(127, false));
        $context->builder->store($i8->constInt(0, false), $endPtr);
        $context->builder->store($endPtr, $endSlot);
        $context->builder->store($endPtr, $ptrSlot);

        $digitsPtr = self::digitsPtr($context);
        $baseD = $context->builder->siToFp($context->builder->sext($base, $i64), $double);
        $loopHead = $fn->appendBasicBlock('dtb_loop_head');
        $loopBody = $fn->appendBasicBlock('dtb_loop_body');
        $afterLoop = $fn->appendBasicBlock('dtb_after_loop');
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($loopHead);
        $f = $context->builder->load($fslot);
        $cont = $context->builder->fcmp(Builder::REAL_OGE, $f, $double->constReal(1.0));
        $ptr = $context->builder->load($ptrSlot);
        $bufStart = $context->builder->pointerCast($bufSlot, $i8p);
        $hasRoom = $context->builder->icmp(Builder::INT_UGT, $ptr, $bufStart);
        $keepGoing = $context->builder->and($cont, $hasRoom);
        $context->builder->branchIf($keepGoing, $loopBody, $afterLoop);

        $context->builder->positionAtEnd($loopBody);
        $f = $context->builder->load($fslot);
        $rem = $context->builder->call($context->lookupFunction('fmod'), $f, $baseD);
        $digit = $context->builder->fpToSi($rem, $i32);
        $digitCh = $context->builder->load($context->builder->gep($digitsPtr, $digit));
        $ptr = $context->builder->load($ptrSlot);
        $prev = $context->builder->inBoundsGEP($ptr, $i64->constInt(-1, true));
        $context->builder->store($digitCh, $prev);
        $context->builder->store($prev, $ptrSlot);
        $context->builder->store($context->builder->fDiv($f, $baseD), $fslot);
        $context->builder->branch($loopHead);

        $context->builder->positionAtEnd($afterLoop);
        $isNegFlag = $context->builder->icmp(Builder::INT_NE, $context->builder->load($negSlot), $i32->constInt(0, false));
        $addSign = $fn->appendBasicBlock('dtb_add_sign');
        $finish = $fn->appendBasicBlock('dtb_finish');
        $context->builder->branchIf($isNegFlag, $addSign, $finish);

        $context->builder->positionAtEnd($addSign);
        $ptr = $context->builder->load($ptrSlot);
        $prev = $context->builder->inBoundsGEP($ptr, $i64->constInt(-1, true));
        $context->builder->store($i8->constInt((int) ord('-'), false), $prev);
        $context->builder->store($prev, $ptrSlot);
        $context->builder->branch($finish);

        $context->builder->positionAtEnd($finish);
        $ptr = $context->builder->load($ptrSlot);
        $end = $context->builder->load($endSlot);
        $len = $context->builder->sub(
            $context->builder->ptrToInt($end, $i64),
            $context->builder->ptrToInt($ptr, $i64)
        );
        $context->builder->returnValue(
            $context->builder->call($context->lookupFunction('__string__init'), $len, $ptr)
        );
    }

    private static function emitBaseConvert(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $double = $context->getTypeFromString('double');
        $from = $fn->getParam(1);
        $to = $fn->getParam(2);

        $fromI32 = $context->builder->trunc($from, $i32);
        $toI32 = $context->builder->trunc($to, $i32);
        $bad = $context->builder->or(
            $context->builder->or(
                $context->builder->icmp(Builder::INT_SLT, $fromI32, $i32->constInt(2, false)),
                $context->builder->icmp(Builder::INT_SGT, $fromI32, $i32->constInt(36, false))
            ),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_SLT, $toI32, $i32->constInt(2, false)),
                $context->builder->icmp(Builder::INT_SGT, $toI32, $i32->constInt(36, false))
            )
        );
        $emptyBb = $fn->appendBasicBlock('bc_empty');
        $workBb = $fn->appendBasicBlock('bc_work');
        $context->builder->branchIf($bad, $emptyBb, $workBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue(self::emptyString($context));

        $context->builder->positionAtEnd($workBb);
        $lvalSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $dvalSlot = BasicBlockHelper::entryAlloca($context, $double);
        $isDoubleSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->call(
            $context->lookupFunction('__phpc_basetozval_core'),
            $fn->getParam(0),
            $from,
            $lvalSlot,
            $dvalSlot,
            $isDoubleSlot
        );

        $isDbl = $context->builder->icmp(Builder::INT_NE, $context->builder->load($isDoubleSlot), $i32->constInt(0, false));
        $dblBb = $fn->appendBasicBlock('bc_dbl');
        $longBb = $fn->appendBasicBlock('bc_long');
        $context->builder->branchIf($isDbl, $dblBb, $longBb);

        $context->builder->positionAtEnd($dblBb);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('__phpc_doubletobase'),
                $context->builder->load($dvalSlot),
                $toI32
            )
        );

        $context->builder->positionAtEnd($longBb);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('phpc_longtobase_str'),
                $context->builder->load($lvalSlot),
                $toI32
            )
        );
    }

    private static function digitsPtr(Context $context): Value
    {
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->pointerCast(
            $context->constantFromString(self::DIGITS),
            $i8p
        );
    }

    private static function emptyString(Context $context): Value
    {
        return self::stringInitFromLiteral($context, '');
    }

    private static function stringInitFromLiteral(Context $context, string $literal): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $len = $i64->constInt(strlen($literal), false);
        $ptr = $context->builder->pointerCast($context->constantFromString($literal), $i8p);

        return $context->builder->call($context->lookupFunction('__string__init'), $len, $ptr);
    }

    /**
     * @return array{0: ?BasicBlock, 1: ?Value}
     */
    private static function captureInsertBlock(Context $context): array
    {
        $bb = $context->builder->getInsertBlock();
        if (null === $bb) {
            return [null, null];
        }

        return [$bb, $context->builder->getInsertBlock()->getTerminator()];
    }

    /**
     * @param array{0: ?BasicBlock, 1: ?Value} $restore
     */
    private static function restoreInsertBlock(Context $context, array $restore): void
    {
        [$bb] = $restore;
        if (null !== $bb) {
            $context->builder->positionAtEnd($bb);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }
}
