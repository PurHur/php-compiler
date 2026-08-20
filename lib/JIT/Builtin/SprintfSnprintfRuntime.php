<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPCompiler\VM\VmResourceIdString;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * argv==1 sprintf/printf via libc snprintf — bypasses broken NestedJIT pack path (#31963).
 *
 * php-src: ext/standard/formatted_print.c — conversion specifier drives coercion
 * (%s/%S stringify via zend_make_printable_zval; never pass double/long to libc %s) (#33010).
 */
final class SprintfSnprintfRuntime
{
    private const BUF_SIZE = 256;

    /** Format with one __value__ arg using the user format string as snprintf pattern. */
    public static function formatOneArg(
        Context $context,
        LlvmFunction $fn,
        Value $fmtSep,
        Value $argv
    ): Value {
        self::ensureDecls($context);
        ZendDoubleStringRuntime::ensureLinked($context);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $valueMap = $context->structFieldMap['__value__'];

        $entry = $context->builder->gep($argv, $i64->constInt(0, false));
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        $fmtNul = self::nullTerminatedCopy($context, $fmtSep);
        $isStrSpecSlot = BasicBlockHelper::entryAlloca($context, $i8);
        self::emitStoreIsStringConversionSpec($context, $fn, $fmtNul, $isStrSpecSlot);

        $outBuf = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $sizeT->constInt(self::BUF_SIZE, false)
        );
        $outChar = $context->builder->pointerCast($outBuf, $charPtr);
        $bufSize = $sizeT->constInt(self::BUF_SIZE, false);

        $doubleBb = $fn->appendBasicBlock('sprintf_snprintf_double');
        $longBb = $fn->appendBasicBlock('sprintf_snprintf_long');
        $stringBb = $fn->appendBasicBlock('sprintf_snprintf_string');
        $fallbackBb = $fn->appendBasicBlock('sprintf_snprintf_fallback');
        $doneBb = $fn->appendBasicBlock('sprintf_snprintf_done');

        $isDouble = $context->builder->or(
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeKind,
                $i8->constInt(VmVariable::TYPE_FLOAT, false)
            ),
            $context->builder->icmp(
                Builder::INT_EQ,
                $typeKind,
                $i8->constInt(JitVariable::TYPE_NATIVE_DOUBLE, false)
            )
        );
        $afterDouble = $fn->appendBasicBlock('sprintf_snprintf_after_double');
        $context->builder->branchIf($isDouble, $doubleBb, $afterDouble);

        $context->builder->positionAtEnd($afterDouble);
        $isLong = $context->builder->icmp(
            Builder::INT_EQ,
            $typeKind,
            $i8->constInt(JitVariable::TYPE_NATIVE_LONG, false)
        );
        $afterLong = $fn->appendBasicBlock('sprintf_snprintf_after_long');
        $context->builder->branchIf($isLong, $longBb, $afterLong);

        $context->builder->positionAtEnd($afterLong);
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeKind,
            $i8->constInt(JitVariable::TYPE_STRING, false)
        );
        $context->builder->branchIf($isString, $stringBb, $fallbackBb);

        // Float/double: %s/%S → zend_gcvt string first (#33010); else raw snprintf.
        $context->builder->positionAtEnd($doubleBb);
        $dbl = $context->builder->call($context->lookupFunction('__value__readDouble'), $entry);
        // snprintf(3) via LibcExtern::ensureSnprintf after always-on drop (#32092).
        LibcExtern::ensureSnprintf($context);
        $isStrD = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->load($isStrSpecSlot),
            $i8->constInt(0, false)
        );
        $doubleStrBb = $fn->appendBasicBlock('sprintf_snprintf_double_str');
        $doubleRawBb = $fn->appendBasicBlock('sprintf_snprintf_double_raw');
        $doubleMerge = $fn->appendBasicBlock('sprintf_snprintf_double_merge');
        $context->builder->branchIf($isStrD, $doubleStrBb, $doubleRawBb);

        $context->builder->positionAtEnd($doubleRawBb);
        $writtenDRaw = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $outChar,
            $bufSize,
            $fmtNul,
            $dbl
        );
        $endDRaw = $context->builder->getInsertBlock();
        $context->builder->branch($doubleMerge);

        $context->builder->positionAtEnd($doubleStrBb);
        $dblStr = ZendDoubleStringRuntime::formatGcvt($context, $dbl);
        $dblSep = $context->builder->call($context->lookupFunction('__string__separate'), $dblStr);
        $dblNul = self::nullTerminatedCopy($context, $dblSep);
        $writtenDStr = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $outChar,
            $bufSize,
            $fmtNul,
            $dblNul
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $dblNul);
        $endDStr = $context->builder->getInsertBlock();
        $context->builder->branch($doubleMerge);

        $context->builder->positionAtEnd($doubleMerge);
        $writtenD = $context->builder->phi($i32);
        $writtenD->addIncoming($writtenDRaw, $endDRaw);
        $writtenD->addIncoming($writtenDStr, $endDStr);
        $endD = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        // Long: %s/%S → decimal string first (#33010); else raw snprintf.
        $context->builder->positionAtEnd($longBb);
        $lng = $context->builder->call($context->lookupFunction('__value__readLong'), $entry);
        $isStrL = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->load($isStrSpecSlot),
            $i8->constInt(0, false)
        );
        $longStrBb = $fn->appendBasicBlock('sprintf_snprintf_long_str');
        $longRawBb = $fn->appendBasicBlock('sprintf_snprintf_long_raw');
        $longMerge = $fn->appendBasicBlock('sprintf_snprintf_long_merge');
        $context->builder->branchIf($isStrL, $longStrBb, $longRawBb);

        $context->builder->positionAtEnd($longRawBb);
        $writtenLRaw = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $outChar,
            $bufSize,
            $fmtNul,
            $lng
        );
        $endLRaw = $context->builder->getInsertBlock();
        $context->builder->branch($longMerge);

        $context->builder->positionAtEnd($longStrBb);
        $lngStr = VmResourceIdString::formatBoxedNativeLong($context, $lng);
        $lngSep = $context->builder->call($context->lookupFunction('__string__separate'), $lngStr);
        $lngNul = self::nullTerminatedCopy($context, $lngSep);
        $writtenLStr = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $outChar,
            $bufSize,
            $fmtNul,
            $lngNul
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $lngNul);
        $endLStr = $context->builder->getInsertBlock();
        $context->builder->branch($longMerge);

        $context->builder->positionAtEnd($longMerge);
        $writtenL = $context->builder->phi($i32);
        $writtenL->addIncoming($writtenLRaw, $endLRaw);
        $writtenL->addIncoming($writtenLStr, $endLStr);
        $endL = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($stringBb);
        $strVal = $context->builder->call($context->lookupFunction('__value__readString'), $entry);
        $strSep = $context->builder->call($context->lookupFunction('__string__separate'), $strVal);
        $strNul = self::nullTerminatedCopy($context, $strSep);
        $writtenS = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $outChar,
            $bufSize,
            $fmtNul,
            $strNul
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $strNul);
        $endS = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($fallbackBb);
        $writtenF = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $outChar,
            $bufSize,
            $fmtNul
        );
        $endF = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $writtenPhi = $context->builder->phi($i32);
        $writtenPhi->addIncoming($writtenD, $endD);
        $writtenPhi->addIncoming($writtenL, $endL);
        $writtenPhi->addIncoming($writtenS, $endS);
        $writtenPhi->addIncoming($writtenF, $endF);

        $len = $context->builder->zExt($writtenPhi, $i64);
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $context->builder->pointerCast($outBuf, $i8p)
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $outBuf);
        $context->builder->call($context->lookupFunction('__mm__free'), $fmtNul);

        return $result;
    }

    /**
     * Scan user format for a %s/%S conversion (php-src formatted_print.c).
     * Stores 1 in $outSlot when the first real conversion specifier is s/S.
     */
    private static function emitStoreIsStringConversionSpec(
        Context $context,
        LlvmFunction $fn,
        Value $fmtNul,
        Value $outSlot
    ): void {
        $b = $context->builder;
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');

        $b->store($i8->constInt(0, false), $outSlot);
        $ptrSlot = BasicBlockHelper::entryAlloca($context, $i8p);
        $b->store($b->pointerCast($fmtNul, $i8p), $ptrSlot);

        $scanHead = $fn->appendBasicBlock('sprintf_fmt_scan_head');
        $scanBody = $fn->appendBasicBlock('sprintf_fmt_scan_body');
        $pctBb = $fn->appendBasicBlock('sprintf_fmt_pct');
        $afterEsc = $fn->appendBasicBlock('sprintf_fmt_after_esc');
        $flagsBody = $fn->appendBasicBlock('sprintf_fmt_flags_body');
        $flagAdvance = $fn->appendBasicBlock('sprintf_fmt_flag_adv');
        $widthBb = $fn->appendBasicBlock('sprintf_fmt_width');
        $widthStar = $fn->appendBasicBlock('sprintf_fmt_width_star');
        $widthDigitBody = $fn->appendBasicBlock('sprintf_fmt_width_digit_body');
        $widthDigitAdv = $fn->appendBasicBlock('sprintf_fmt_width_digit_adv');
        $precBb = $fn->appendBasicBlock('sprintf_fmt_prec');
        $afterDot = $fn->appendBasicBlock('sprintf_fmt_after_dot');
        $precStarAdv = $fn->appendBasicBlock('sprintf_fmt_prec_star_adv');
        $precDigitBody = $fn->appendBasicBlock('sprintf_fmt_prec_digit_body');
        $precDigitAdv = $fn->appendBasicBlock('sprintf_fmt_prec_digit_adv');
        $convBb = $fn->appendBasicBlock('sprintf_fmt_conv');
        $scanNext = $fn->appendBasicBlock('sprintf_fmt_scan_next');
        $scanDone = $fn->appendBasicBlock('sprintf_fmt_scan_done');

        $b->branch($scanHead);

        $b->positionAtEnd($scanHead);
        $p = $b->load($ptrSlot);
        $c = $b->load($p);
        $b->branchIf(
            $b->icmp(Builder::INT_EQ, $c, $i8->constInt(0, false)),
            $scanDone,
            $scanBody
        );

        $b->positionAtEnd($scanBody);
        $b->branchIf(
            $b->icmp(Builder::INT_EQ, $c, $i8->constInt(\ord('%'), false)),
            $pctBb,
            $scanNext
        );

        $b->positionAtEnd($pctBb);
        $p1 = $b->gep($p, $i64->constInt(1, false));
        $b->store($p1, $ptrSlot);
        $c1 = $b->load($p1);
        $isEsc = $b->icmp(Builder::INT_EQ, $c1, $i8->constInt(\ord('%'), false));
        $b->branchIf($isEsc, $afterEsc, $flagsBody);

        $b->positionAtEnd($afterEsc);
        $b->store($b->gep($p1, $i64->constInt(1, false)), $ptrSlot);
        $b->branch($scanHead);

        // Skip flags: # 0 - + ' and space
        $b->positionAtEnd($flagsBody);
        $pf = $b->load($ptrSlot);
        $cf = $b->load($pf);
        $isFlag = $b->or(
            $b->or(
                $b->icmp(Builder::INT_EQ, $cf, $i8->constInt(\ord('#'), false)),
                $b->icmp(Builder::INT_EQ, $cf, $i8->constInt(\ord('0'), false))
            ),
            $b->or(
                $b->or(
                    $b->icmp(Builder::INT_EQ, $cf, $i8->constInt(\ord('-'), false)),
                    $b->icmp(Builder::INT_EQ, $cf, $i8->constInt(\ord('+'), false))
                ),
                $b->or(
                    $b->icmp(Builder::INT_EQ, $cf, $i8->constInt(\ord(' '), false)),
                    $b->icmp(Builder::INT_EQ, $cf, $i8->constInt(\ord('\''), false))
                )
            )
        );
        $b->branchIf($isFlag, $flagAdvance, $widthBb);
        $b->positionAtEnd($flagAdvance);
        $b->store($b->gep($pf, $i64->constInt(1, false)), $ptrSlot);
        $b->branch($flagsBody);

        // Width: * or digits
        $b->positionAtEnd($widthBb);
        $pw = $b->load($ptrSlot);
        $cw = $b->load($pw);
        $b->branchIf(
            $b->icmp(Builder::INT_EQ, $cw, $i8->constInt(\ord('*'), false)),
            $widthStar,
            $widthDigitBody
        );
        $b->positionAtEnd($widthStar);
        $b->store($b->gep($pw, $i64->constInt(1, false)), $ptrSlot);
        $b->branch($precBb);

        $b->positionAtEnd($widthDigitBody);
        $pwd = $b->load($ptrSlot);
        $cwd = $b->load($pwd);
        $isDigitW = $b->and(
            $b->icmp(Builder::INT_UGE, $cwd, $i8->constInt(\ord('0'), false)),
            $b->icmp(Builder::INT_ULE, $cwd, $i8->constInt(\ord('9'), false))
        );
        $b->branchIf($isDigitW, $widthDigitAdv, $precBb);
        $b->positionAtEnd($widthDigitAdv);
        $b->store($b->gep($pwd, $i64->constInt(1, false)), $ptrSlot);
        $b->branch($widthDigitBody);

        // Precision: .[*|digits]
        $b->positionAtEnd($precBb);
        $pp = $b->load($ptrSlot);
        $cp = $b->load($pp);
        $b->branchIf(
            $b->icmp(Builder::INT_EQ, $cp, $i8->constInt(\ord('.'), false)),
            $afterDot,
            $convBb
        );

        $b->positionAtEnd($afterDot);
        $pp1 = $b->gep($pp, $i64->constInt(1, false));
        $b->store($pp1, $ptrSlot);
        $cp1 = $b->load($pp1);
        $b->branchIf(
            $b->icmp(Builder::INT_EQ, $cp1, $i8->constInt(\ord('*'), false)),
            $precStarAdv,
            $precDigitBody
        );
        $b->positionAtEnd($precStarAdv);
        $b->store($b->gep($pp1, $i64->constInt(1, false)), $ptrSlot);
        $b->branch($convBb);

        $b->positionAtEnd($precDigitBody);
        $ppd = $b->load($ptrSlot);
        $cpd = $b->load($ppd);
        $isDigitP = $b->and(
            $b->icmp(Builder::INT_UGE, $cpd, $i8->constInt(\ord('0'), false)),
            $b->icmp(Builder::INT_ULE, $cpd, $i8->constInt(\ord('9'), false))
        );
        $b->branchIf($isDigitP, $precDigitAdv, $convBb);
        $b->positionAtEnd($precDigitAdv);
        $b->store($b->gep($ppd, $i64->constInt(1, false)), $ptrSlot);
        $b->branch($precDigitBody);

        // Conversion specifier
        $b->positionAtEnd($convBb);
        $pc = $b->load($ptrSlot);
        $cc = $b->load($pc);
        $isStr = $b->or(
            $b->icmp(Builder::INT_EQ, $cc, $i8->constInt(\ord('s'), false)),
            $b->icmp(Builder::INT_EQ, $cc, $i8->constInt(\ord('S'), false))
        );
        $b->store(
            $b->select($isStr, $i8->constInt(1, false), $i8->constInt(0, false)),
            $outSlot
        );
        $b->branch($scanDone);

        $b->positionAtEnd($scanNext);
        $b->store($b->gep($b->load($ptrSlot), $i64->constInt(1, false)), $ptrSlot);
        $b->branch($scanHead);

        $b->positionAtEnd($scanDone);
    }

    public static function nullTerminatedCopyPublic(Context $context, Value $strSep): Value
    {
        return self::nullTerminatedCopy($context, $strSep);
    }

    private static function nullTerminatedCopy(Context $context, Value $strSep): Value
    {
        $stringMap = $context->structFieldMap['__string__'];
        $sizeT = $context->getTypeFromString('size_t');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $charPtr = $context->getTypeFromString('char*');

        $len = $context->builder->load($context->builder->structGep($strSep, $stringMap['length']));
        $data = $context->builder->structGep($strSep, $stringMap['value']);
        $allocSize = $context->builder->add($len, $sizeT->constInt(1, false));
        $buf = $context->builder->call($context->lookupFunction('__mm__malloc'), $allocSize);
        $bufI8 = $context->builder->pointerCast($buf, $i8p);
        LibcExtern::ensureMemcpyImplemented($context);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $bufI8,
            $data,
            $len
        );
        $nulPtr = $context->builder->gep($bufI8, $len);
        $context->builder->store($i8->constInt(0, false), $nulPtr);

        return $context->builder->pointerCast($buf, $charPtr);
    }

    public static function ensureDeclsPublic(Context $context): void
    {
        self::ensureDecls($context);
    }

    private static function ensureDecls(Context $context): void
    {
        $double = $context->getTypeFromString('double');
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $valuePtr = $context->getTypeFromString('__value__*');
        $voidTy = $context->getTypeFromString('void');

        LibcExtern::ensureSnprintf($context);
        foreach (
            [
                '__mm__malloc' => [$i8p, false, [$sizeT]],
                '__mm__free' => [$voidTy, false, [$i8p]],
                '__string__init' => [$strPtr, false, [$i64, $i8p]],
                '__string__separate' => [$strPtr, false, [$strPtr]],
                '__value__readDouble' => [$double, false, [$valuePtr]],
                '__value__readLong' => [$i64, false, [$valuePtr]],
                '__value__readString' => [$strPtr, false, [$valuePtr]],
            ] as $name => [$ret, $vararg, $params]
        ) {
            try {
                $context->lookupFunction($name);
                continue;
            } catch (\Throwable) {
            }
            // Reuse the module symbol when lookupFunction misses the registry (#32122 / #31894).
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                $fn = $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, $vararg, ...$params)
                );
            }
            $context->registerFunction($name, $fn);
        }
        LibcExtern::ensureMemcpyImplemented($context);
    }
}
