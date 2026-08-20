<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * argv==1 sprintf/printf via libc snprintf — bypasses broken NestedJIT pack path (#31963).
 *
 * Conversion specifier drives coercion (php-src formatted_print.c). Never pass a double/long
 * to libc `%s` — that is SIGSEGV (#33010).
 *
 * php-src: ext/standard/formatted_print.c — single-arg format dispatch
 */
final class SprintfSnprintfRuntime
{
    private const BUF_SIZE = 256;

    private const FMT_IS_S_ABI = '__phpc_sprintf_fmt_is_string_conv';

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
        // Keep full tag — TYPE_VALUE floats must not be mistaken for NATIVE_* (#33010).
        $typeKind = $context->builder->and($typeByte, $i8->constInt(0x7f, false));

        $fmtNul = self::nullTerminatedCopy($context, $fmtSep);
        $outBuf = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $sizeT->constInt(self::BUF_SIZE, false)
        );
        $outChar = $context->builder->pointerCast($outBuf, $charPtr);
        $bufSize = $sizeT->constInt(self::BUF_SIZE, false);
        LibcExtern::ensureSnprintf($context); // snprintf(3) after always-on drop (#32092)
        // Link float→string bodies before as_s uses them (avoid cross-function BB edges) (#33010).
        $savedForZend = null;
        try {
            $savedForZend = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }
        ZendDoubleStringRuntime::ensureStandaloneBodies($context);
        if (null !== $savedForZend) {
            $context->builder->positionAtEnd($savedForZend);
        }
        $fmtIsS = $context->builder->call(
            $context->lookupFunction(self::FMT_IS_S_ABI),
            $fmtNul
        );

        $asSBb = $fn->appendBasicBlock('sprintf_snprintf_as_s');
        $typedBb = $fn->appendBasicBlock('sprintf_snprintf_typed');
        $doneBb = $fn->appendBasicBlock('sprintf_snprintf_done');
        // %s/%S first — never pass double/long to libc %s (#33010).
        $context->builder->branchIf($fmtIsS, $asSBb, $typedBb);

        $context->builder->positionAtEnd($asSBb);
        $asSDouble = $fn->appendBasicBlock('sprintf_as_s_double');
        $asSLong = $fn->appendBasicBlock('sprintf_as_s_long');
        $asSString = $fn->appendBasicBlock('sprintf_as_s_string');
        $asSFallback = $fn->appendBasicBlock('sprintf_as_s_fallback');

        $isDblS = $context->builder->or(
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
        $afterDblS = $fn->appendBasicBlock('sprintf_as_s_after_dbl');
        $context->builder->branchIf($isDblS, $asSDouble, $afterDblS);

        $context->builder->positionAtEnd($afterDblS);
        $isLngS = $context->builder->icmp(
            Builder::INT_EQ,
            $typeKind,
            $i8->constInt(JitVariable::TYPE_NATIVE_LONG, false)
        );
        $afterLngS = $fn->appendBasicBlock('sprintf_as_s_after_lng');
        $context->builder->branchIf($isLngS, $asSLong, $afterLngS);

        $context->builder->positionAtEnd($afterLngS);
        $isStrS = $context->builder->icmp(
            Builder::INT_EQ,
            $typeKind,
            $i8->constInt(JitVariable::TYPE_STRING, false)
        );
        $context->builder->branchIf($isStrS, $asSString, $asSFallback);

        $context->builder->positionAtEnd($asSDouble);
        $dblS = $context->builder->call($context->lookupFunction('__value__readDouble'), $entry);
        $dblStr = ZendDoubleStringRuntime::formatGcvt($context, $dblS);
        // formatGcvt must leave us in this function (ensureStandaloneBodies already ran).
        $dblSep = $context->builder->call($context->lookupFunction('__string__separate'), $dblStr);
        $dblNul = self::nullTerminatedCopy($context, $dblSep);
        $writtenDblS = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $outChar,
            $bufSize,
            $fmtNul,
            $dblNul
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $dblNul);
        $endDblS = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($asSLong);
        $lngS = $context->builder->call($context->lookupFunction('__value__readLong'), $entry);
        $numBuf = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $sizeT->constInt(64, false)
        );
        $numChar = $context->builder->pointerCast($numBuf, $charPtr);
        $lldFmt = $context->builder->pointerCast($context->constantFromString('%lld'), $charPtr);
        $context->builder->call(
            $context->lookupFunction('snprintf'),
            $numChar,
            $sizeT->constInt(64, false),
            $lldFmt,
            $lngS
        );
        $writtenLngS = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $outChar,
            $bufSize,
            $fmtNul,
            $numChar
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $numBuf);
        $endLngS = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($asSString);
        $strS = $context->builder->call($context->lookupFunction('__value__readString'), $entry);
        $strSepS = $context->builder->call($context->lookupFunction('__string__separate'), $strS);
        $strNulS = self::nullTerminatedCopy($context, $strSepS);
        $writtenStrS = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $outChar,
            $bufSize,
            $fmtNul,
            $strNulS
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $strNulS);
        $endStrS = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($asSFallback);
        $emptyNul = $context->builder->pointerCast($context->constantFromString(''), $charPtr);
        $writtenFbS = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $outChar,
            $bufSize,
            $fmtNul,
            $emptyNul
        );
        $endFbS = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($typedBb);
        $doubleBb = $fn->appendBasicBlock('sprintf_snprintf_double');
        $longBb = $fn->appendBasicBlock('sprintf_snprintf_long');
        $stringBb = $fn->appendBasicBlock('sprintf_snprintf_string');
        $fallbackBb = $fn->appendBasicBlock('sprintf_snprintf_fallback');

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

        $context->builder->positionAtEnd($doubleBb);
        $dbl = $context->builder->call($context->lookupFunction('__value__readDouble'), $entry);
        // Default to zend_gcvt stringify. Only pass a double to libc when the conversion
        // is clearly floating (fFeEgGaA) — %s + double is SIGSEGV (#33010).
        $fmt0 = $context->builder->load($fmtNul);
        $pScan = $context->builder->alloca($charPtr);
        $context->builder->store($context->builder->gep($fmtNul, $i64->constInt(1, false)), $pScan);
        $findConv = $fn->appendBasicBlock('sprintf_dbl_find_conv');
        $isFloatConvBb = $fn->appendBasicBlock('sprintf_dbl_float_conv');
        $dblToS = $fn->appendBasicBlock('sprintf_typed_dbl_as_s');
        $dblRaw = $fn->appendBasicBlock('sprintf_typed_dbl_raw');
        $isPct0 = $context->builder->icmp(Builder::INT_EQ, $fmt0, $i8->constInt(ord('%'), false));
        $context->builder->branchIf($isPct0, $findConv, $dblToS);

        $context->builder->positionAtEnd($findConv);
        $sp = $context->builder->load($pScan);
        $sc = $context->builder->load($sp);
        $scNul = $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(0, false));
        $scCheck = $fn->appendBasicBlock('sprintf_dbl_conv_check');
        $context->builder->branchIf($scNul, $dblToS, $scCheck);
        $context->builder->positionAtEnd($scCheck);
        $isFloatCh = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('f'), false)),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('F'), false)),
                $context->builder->or(
                    $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('e'), false)),
                    $context->builder->or(
                        $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('E'), false)),
                        $context->builder->or(
                            $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('g'), false)),
                            $context->builder->or(
                                $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('G'), false)),
                                $context->builder->or(
                                    $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('a'), false)),
                                    $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('A'), false))
                                )
                            )
                        )
                    )
                )
            )
        );
        $isSkipCh = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('#'), false)),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('0'), false)),
                $context->builder->or(
                    $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('-'), false)),
                    $context->builder->or(
                        $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('+'), false)),
                        $context->builder->or(
                            $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord(' '), false)),
                            $context->builder->or(
                                $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('.'), false)),
                                $context->builder->or(
                                    $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('*'), false)),
                                    $context->builder->and(
                                        $context->builder->icmp(Builder::INT_SGE, $sc, $i8->constInt(ord('0'), false)),
                                        $context->builder->icmp(Builder::INT_SLE, $sc, $i8->constInt(ord('9'), false))
                                    )
                                )
                            )
                        )
                    )
                )
            )
        );
        $skipAdv = $fn->appendBasicBlock('sprintf_dbl_skip_adv');
        $context->builder->branchIf($isFloatCh, $dblRaw, $isFloatConvBb);
        $context->builder->positionAtEnd($isFloatConvBb);
        $context->builder->branchIf($isSkipCh, $skipAdv, $dblToS);
        $context->builder->positionAtEnd($skipAdv);
        $context->builder->store($context->builder->gep($sp, $i64->constInt(1, false)), $pScan);
        $context->builder->branch($findConv);

        $context->builder->positionAtEnd($dblToS);
        $dblStr2 = ZendDoubleStringRuntime::formatGcvt($context, $dbl);
        $dblSep2 = $context->builder->call($context->lookupFunction('__string__separate'), $dblStr2);
        $dblNul2 = self::nullTerminatedCopy($context, $dblSep2);
        $writtenDs2 = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $outChar,
            $bufSize,
            $fmtNul,
            $dblNul2
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $dblNul2);
        $endDs2 = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($dblRaw);
        $writtenD = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $outChar,
            $bufSize,
            $fmtNul,
            $dbl
        );
        $endD = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($longBb);
        $lng = $context->builder->call($context->lookupFunction('__value__readLong'), $entry);
        $writtenL = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $outChar,
            $bufSize,
            $fmtNul,
            $lng
        );
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
        $endS = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($fallbackBb);
        // Never call snprintf with a conversion and zero args — %s with missing arg is UB (#33010).
        $emptyFb = $context->builder->pointerCast($context->constantFromString(''), $charPtr);
        $writtenF = $context->builder->call(
            $context->lookupFunction('snprintf'),
            $outChar,
            $bufSize,
            $fmtNul,
            $emptyFb
        );
        $endF = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $i32 = $context->getTypeFromString('int32');
        $writtenPhi = $context->builder->phi($i32);
        $writtenPhi->addIncoming($writtenDblS, $endDblS);
        $writtenPhi->addIncoming($writtenLngS, $endLngS);
        $writtenPhi->addIncoming($writtenStrS, $endStrS);
        $writtenPhi->addIncoming($writtenFbS, $endFbS);
        $writtenPhi->addIncoming($writtenDs2, $endDs2);
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
        self::ensureFormatIsStringConv($context);
    }

    /**
     * True when the first conversion specifier is %s / %S (php-src php_formatted_print).
     * Used so numeric argv is stringified before libc snprintf (#33010).
     */
    private static function ensureFormatIsStringConv(Context $context): void
    {
        $probe = $context->module->getNamedFunction(self::FMT_IS_S_ABI);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction(self::FMT_IS_S_ABI, $probe);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        $i1 = $context->getTypeFromString('bool');
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $charPtr = $context->getTypeFromString('char*');
        $ft = $context->context->functionType($i1, false, $charPtr);
        $fn = null !== $probe ? $probe : $context->module->addFunction(self::FMT_IS_S_ABI, $ft);
        $one = $i64->constInt(1, false);

        $entry = $fn->appendBasicBlock('fmt_is_s_entry');
        $loop = $fn->appendBasicBlock('fmt_is_s_loop');
        $sawPct = $fn->appendBasicBlock('fmt_is_s_pct');
        $scan = $fn->appendBasicBlock('fmt_is_s_scan');
        $retTrue = $fn->appendBasicBlock('fmt_is_s_true');
        $retFalse = $fn->appendBasicBlock('fmt_is_s_false');

        $context->builder->positionAtEnd($entry);
        $pAlloca = $context->builder->alloca($charPtr);
        $context->builder->store($fn->getParam(0), $pAlloca);
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $p = $context->builder->load($pAlloca);
        $ch = $context->builder->load($p);
        $isNul = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(0, false));
        $notNul = $fn->appendBasicBlock('fmt_is_s_not_nul');
        $context->builder->branchIf($isNul, $retFalse, $notNul);

        $context->builder->positionAtEnd($notNul);
        $isPct = $context->builder->icmp(Builder::INT_EQ, $ch, $i8->constInt(ord('%'), false));
        $advance = $fn->appendBasicBlock('fmt_is_s_advance');
        $context->builder->branchIf($isPct, $sawPct, $advance);

        $context->builder->positionAtEnd($advance);
        $context->builder->store($context->builder->gep($p, $one), $pAlloca);
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($sawPct);
        $p1 = $context->builder->gep($p, $one);
        $ch2 = $context->builder->load($p1);
        $isPct2 = $context->builder->icmp(Builder::INT_EQ, $ch2, $i8->constInt(ord('%'), false));
        $afterEsc = $fn->appendBasicBlock('fmt_is_s_after_esc');
        $context->builder->branchIf($isPct2, $afterEsc, $scan);
        $context->builder->positionAtEnd($afterEsc);
        $context->builder->store($context->builder->gep($p1, $one), $pAlloca);
        $context->builder->branch($loop);

        // Skip flags / width / precision / length; stop on conversion char.
        $context->builder->positionAtEnd($scan);
        $context->builder->store($p1, $pAlloca);
        $scanLoop = $fn->appendBasicBlock('fmt_is_s_scan_loop');
        $context->builder->branch($scanLoop);

        $context->builder->positionAtEnd($scanLoop);
        $sp = $context->builder->load($pAlloca);
        $sc = $context->builder->load($sp);
        $scNul = $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(0, false));
        $scBody = $fn->appendBasicBlock('fmt_is_s_scan_body');
        $context->builder->branchIf($scNul, $retFalse, $scBody);

        $context->builder->positionAtEnd($scBody);
        $isSkip = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('#'), false)),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('0'), false)),
                $context->builder->or(
                    $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('-'), false)),
                    $context->builder->or(
                        $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('+'), false)),
                        $context->builder->or(
                            $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord(' '), false)),
                            $context->builder->or(
                                $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord("'"), false)),
                                $context->builder->or(
                                    $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('*'), false)),
                                    $context->builder->or(
                                        $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('.'), false)),
                                        $context->builder->or(
                                            $context->builder->and(
                                                $context->builder->icmp(Builder::INT_SGE, $sc, $i8->constInt(ord('0'), false)),
                                                $context->builder->icmp(Builder::INT_SLE, $sc, $i8->constInt(ord('9'), false))
                                            ),
                                            $context->builder->or(
                                                $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('h'), false)),
                                                $context->builder->or(
                                                    $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('l'), false)),
                                                    $context->builder->or(
                                                        $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('L'), false)),
                                                        $context->builder->or(
                                                            $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('z'), false)),
                                                            $context->builder->or(
                                                                $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('j'), false)),
                                                                $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('t'), false))
                                                            )
                                                        )
                                                    )
                                                )
                                            )
                                        )
                                    )
                                )
                            )
                        )
                    )
                )
            )
        );
        $skipNext = $fn->appendBasicBlock('fmt_is_s_skip_next');
        $checkS = $fn->appendBasicBlock('fmt_is_s_check');
        $context->builder->branchIf($isSkip, $skipNext, $checkS);
        $context->builder->positionAtEnd($skipNext);
        $context->builder->store($context->builder->gep($sp, $one), $pAlloca);
        $context->builder->branch($scanLoop);

        $context->builder->positionAtEnd($checkS);
        $isS = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('s'), false)),
            $context->builder->icmp(Builder::INT_EQ, $sc, $i8->constInt(ord('S'), false))
        );
        $context->builder->branchIf($isS, $retTrue, $retFalse);

        $context->builder->positionAtEnd($retTrue);
        $context->builder->returnValue($i1->constInt(1, false));
        $context->builder->positionAtEnd($retFalse);
        $context->builder->returnValue($i1->constInt(0, false));

        $context->registerFunction(self::FMT_IS_S_ABI, $fn);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        }
    }
}
