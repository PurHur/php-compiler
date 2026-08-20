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
 * For `%s`/`%S`, coerce float/int to strings before snprintf (#33010) — never pass a
 * double/long to a libc `%s` call (php-src formatted_print.c drives coercion from the
 * conversion specifier, not from the PHP arg type alone).
 *
 * php-src: ext/standard/formatted_print.c — single-arg format dispatch
 */
final class SprintfSnprintfRuntime
{
    private const BUF_SIZE = 256;

    private const FMT_WANTS_STRING_ABI = '__compiler_sprintf_fmt_wants_string';

    /** Format with one __value__ arg using the user format string as snprintf pattern. */
    public static function formatOneArg(
        Context $context,
        LlvmFunction $fn,
        Value $fmtSep,
        Value $argv
    ): Value {
        self::ensureDecls($context);

        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $sizeT = $context->getTypeFromString('size_t');
        $valueMap = $context->structFieldMap['__value__'];

        $entry = $context->builder->gep($argv, $i64->constInt(0, false));
        $typeByte = $context->builder->load(
            $context->builder->structGep($entry, $valueMap['type'])
        );
        $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        $fmtNul = self::nullTerminatedCopy($context, $fmtSep);
        $outBuf = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $sizeT->constInt(self::BUF_SIZE, false)
        );
        $outChar = $context->builder->pointerCast($outBuf, $charPtr);
        $bufSize = $sizeT->constInt(self::BUF_SIZE, false);
        LibcExtern::ensureSnprintf($context);

        $wantsStr = $context->builder->icmp(
            Builder::INT_NE,
            $context->builder->call(
                $context->lookupFunction(self::FMT_WANTS_STRING_ABI),
                $fmtNul
            ),
            $i8->constInt(0, false)
        );

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
        // Masked type byte is Vm TYPE_STRING (4), not Jit TYPE_STRING|IS_REFCOUNTED (#33010).
        $isString = $context->builder->icmp(
            Builder::INT_EQ,
            $typeKind,
            $i8->constInt(VmVariable::TYPE_STRING, false)
        );
        $context->builder->branchIf($isString, $stringBb, $fallbackBb);

        $context->builder->positionAtEnd($doubleBb);
        $dbl = $context->builder->call($context->lookupFunction('__value__readDouble'), $entry);
        $dblAsStr = $fn->appendBasicBlock('sprintf_snprintf_double_as_str');
        $dblNum = $fn->appendBasicBlock('sprintf_snprintf_double_numeric');
        $context->builder->branchIf($wantsStr, $dblAsStr, $dblNum);

        $context->builder->positionAtEnd($dblAsStr);
        $dblStr = ZendDoubleStringRuntime::formatGcvt($context, $dbl);
        list($writtenDStr, $endDStr) = self::emitSnprintfWithString(
            $context,
            $doneBb,
            $outChar,
            $bufSize,
            $fmtNul,
            $dblStr
        );

        $context->builder->positionAtEnd($dblNum);
        $writtenDNum = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $outChar,
            $bufSize,
            $fmtNul,
            $dbl
        );
        $endDNum = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($longBb);
        $lng = $context->builder->call($context->lookupFunction('__value__readLong'), $entry);
        $lngAsStr = $fn->appendBasicBlock('sprintf_snprintf_long_as_str');
        $lngNum = $fn->appendBasicBlock('sprintf_snprintf_long_numeric');
        $context->builder->branchIf($wantsStr, $lngAsStr, $lngNum);

        $context->builder->positionAtEnd($lngAsStr);
        $lngStr = VmResourceIdString::formatBoxedNativeLong($context, $lng);
        list($writtenLStr, $endLStr) = self::emitSnprintfWithString(
            $context,
            $doneBb,
            $outChar,
            $bufSize,
            $fmtNul,
            $lngStr
        );

        $context->builder->positionAtEnd($lngNum);
        $writtenLNum = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $outChar,
            $bufSize,
            $fmtNul,
            $lng
        );
        $endLNum = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($stringBb);
        $strVal = $context->builder->call($context->lookupFunction('__value__readString'), $entry);
        list($writtenS, $endS) = self::emitSnprintfWithString(
            $context,
            $doneBb,
            $outChar,
            $bufSize,
            $fmtNul,
            $strVal
        );

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
        $i32 = $context->getTypeFromString('int32');
        $writtenPhi = $context->builder->phi($i32);
        $writtenPhi->addIncoming($writtenDStr, $endDStr);
        $writtenPhi->addIncoming($writtenDNum, $endDNum);
        $writtenPhi->addIncoming($writtenLStr, $endLStr);
        $writtenPhi->addIncoming($writtenLNum, $endLNum);
        $writtenPhi->addIncoming($writtenS, $endS);
        $writtenPhi->addIncoming($writtenF, $endF);

        $len = $context->builder->zExt($writtenPhi, $i64);
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $len,
            $context->builder->pointerCast($outBuf, $i8p)
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $outBuf);
        $context->builder->call(
            $context->lookupFunction('__mm__free'),
            $context->builder->pointerCast($fmtNul, $i8p)
        );

        return $result;
    }

    /**
     * @return array{0: Value, 1: \PHPLLVM\BasicBlock}
     */
    private static function emitSnprintfWithString(
        Context $context,
        $doneBb,
        Value $outChar,
        Value $bufSize,
        Value $fmtNul,
        Value $strVal
    ): array {
        $i8p = $context->getTypeFromString('int8*');
        $strSep = $context->builder->call(
            $context->lookupFunction('__string__separate'),
            $strVal
        );
        $strNul = self::nullTerminatedCopy($context, $strSep);
        $written = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $outChar,
            $bufSize,
            $fmtNul,
            $strNul
        );
        $context->builder->call(
            $context->lookupFunction('__mm__free'),
            $context->builder->pointerCast($strNul, $i8p)
        );
        $end = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        return [$written, $end];
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
            $exist = $context->module->getNamedFunction($name);
            if (null === $exist) {
                $exist = $context->module->addFunction(
                    $name,
                    $context->context->functionType($ret, $vararg, ...$params)
                );
            }
            $context->registerFunction($name, $exist);
        }
        LibcExtern::ensureMemcpyImplemented($context);
        self::ensureFmtWantsString($context);
        ZendDoubleStringRuntime::ensureStandaloneBodies($context);
    }

    /**
     * Returns i8 1 when the first conversion specifier is %s / %S.
     * php-src: ext/standard/formatted_print.c — conversion char after flags/width/precision.
     */
    private static function ensureFmtWantsString(Context $context): void
    {
        $name = self::FMT_WANTS_STRING_ABI;
        try {
            $context->lookupFunction($name);

            return;
        } catch (\Throwable) {
        }
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $savedInsert = BasicBlockHelper::tryGetInsertBlock($context);
        $savedActive = $context->activeFunction;
        $savedLowering = $context->loweringLlvmFunction;

        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $ft = $context->context->functionType($i8, false, $charPtr);
        $fn = null !== $probe ? $probe : $context->module->addFunction($name, $ft);

        $entry = $fn->appendBasicBlock('fmt_wants_str_entry');
        $context->registerFunction($name, $fn);
        $context->activeFunction = $name;
        $context->loweringLlvmFunction = $fn instanceof LlvmFunction ? $fn : null;
        $context->builder->positionAtEnd($entry);

        try {
            $pSlot = BasicBlockHelper::entryAlloca($context, $charPtr);
            $context->builder->store($fn->getParam(0), $pSlot);

            $loop = $fn->appendBasicBlock('fmt_wants_str_loop');
            $yes = $fn->appendBasicBlock('fmt_wants_str_yes');
            $no = $fn->appendBasicBlock('fmt_wants_str_no');
            $context->builder->branch($loop);

            $context->builder->positionAtEnd($loop);
            $p = $context->builder->load($pSlot);
            $ch = $context->builder->load($p);
            $isNul = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0, false));
            $scan = $fn->appendBasicBlock('fmt_wants_str_scan');
            $context->builder->branchIf($isNul, $no, $scan);

            $context->builder->positionAtEnd($scan);
            $isPct = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('%'), false));
            $advance = $fn->appendBasicBlock('fmt_wants_str_advance');
            $afterPct = $fn->appendBasicBlock('fmt_wants_str_after_pct');
            $context->builder->branchIf($isPct, $afterPct, $advance);

            $context->builder->positionAtEnd($advance);
            $context->builder->store($context->builder->gep($p, $i64->constInt(1, false)), $pSlot);
            $context->builder->branch($loop);

            $context->builder->positionAtEnd($afterPct);
            $p1 = $context->builder->gep($p, $i64->constInt(1, false));
            $ch1 = $context->builder->load($p1);
            $isEsc = $context->builder->icmp(Builder::INT_EQ, $ch1, $i8->constInt(ord('%'), false));
            $escaped = $fn->appendBasicBlock('fmt_wants_str_escaped');
            $spec = $fn->appendBasicBlock('fmt_wants_str_spec');
            $context->builder->branchIf($isEsc, $escaped, $spec);

            $context->builder->positionAtEnd($escaped);
            $context->builder->store($context->builder->gep($p1, $i64->constInt(1, false)), $pSlot);
            $context->builder->branch($loop);

            $context->builder->positionAtEnd($spec);
            $context->builder->store($p1, $pSlot);
            $specLoop = $fn->appendBasicBlock('fmt_wants_str_spec_loop');
            $context->builder->branch($specLoop);

            $context->builder->positionAtEnd($specLoop);
            $sp = $context->builder->load($pSlot);
            $sc = $context->builder->load($sp);
            $scNul = $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(0, false));
            $specBody = $fn->appendBasicBlock('fmt_wants_str_spec_body');
            $context->builder->branchIf($scNul, $no, $specBody);

            $context->builder->positionAtEnd($specBody);
            $isSkip = self::emitIsSpecifierMeta($context, $sc);
            $skipOne = $fn->appendBasicBlock('fmt_wants_str_skip_one');
            $atConv = $fn->appendBasicBlock('fmt_wants_str_at_conv');
            $context->builder->branchIf($isSkip, $skipOne, $atConv);

            $context->builder->positionAtEnd($skipOne);
            $context->builder->store($context->builder->gep($sp, $i64->constInt(1, false)), $pSlot);
            $context->builder->branch($specLoop);

            $context->builder->positionAtEnd($atConv);
            $isS = $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('s'), false)),
                $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('S'), false))
            );
            $context->builder->branchIf($isS, $yes, $no);

            $context->builder->positionAtEnd($yes);
            $context->builder->returnValue($i8->constInt(1, false));

            $context->builder->positionAtEnd($no);
            $context->builder->returnValue($i8->constInt(0, false));
        } finally {
            $context->activeFunction = $savedActive;
            $context->loweringLlvmFunction = $savedLowering;
            BasicBlockHelper::restoreInsertBlock($context, $savedInsert);
        }
    }

    private static function emitIsSpecifierMeta(Context $context, Value $sc): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $b = $context->builder;
        $eq = static fn (int $c) => $b->icmp(Builder::INT_EQ, $sc, $i8->constInt($c, false));
        $ge0 = $b->icmp(Builder::INT_SGE, $sc, $i8->constInt(ord('0'), false));
        $le9 = $b->icmp(Builder::INT_SLE, $sc, $i8->constInt(ord('9'), false));
        $isDigit = $b->and($ge0, $le9);

        return $b->or(
            $b->or(
                $b->or($eq(ord('-')), $b->or($eq(ord('+')), $eq(ord(' ')))),
                $b->or($eq(ord('#')), $eq(ord("'")))
            ),
            $b->or(
                $b->or($isDigit, $b->or($eq(ord('*')), $eq(ord('.')))),
                $b->or(
                    $eq(ord('$')),
                    $b->or(
                        $b->or($eq(ord('h')), $eq(ord('l'))),
                        $b->or(
                            $b->or($eq(ord('L')), $eq(ord('z'))),
                            $b->or($eq(ord('j')), $eq(ord('t')))
                        )
                    )
                )
            )
        );
    }
}
