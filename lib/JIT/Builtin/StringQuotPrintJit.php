<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM bodies for quoted_printable_encode/decode (former phpc_quot_print.c, #5225).
 *
 * php-src: ext/standard/quot_print.c — parity with ext/standard/VmString.php.
 */
final class StringQuotPrintJit
{
    private const QPRINT_MAXL = 75;

    public static function implement(Context $context): void
    {
        self::implementIfMissing($context, '__compiler_quoted_printable_encode', self::implementEncode(...));
        self::implementIfMissing($context, '__compiler_quoted_printable_decode', self::implementDecode(...));
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
        $fn = $context->lookupFunction($name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementEncode(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $str = $fn->getParam(0);
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $three = $i64->constInt(3, false);
        $maxl = $i64->constInt(self::QPRINT_MAXL, false);
        $divisor = $i64->constInt(self::QPRINT_MAXL - 9, false);

        $src = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        $length = $context->builder->load($context->builder->structGep($src, $map['length']));
        $srcChars = $context->builder->structGep($src, $map['value']);

        $emptyBb = $fn->appendBasicBlock('qp_enc_empty');
        $workBb = $fn->appendBasicBlock('qp_enc_work');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $length, $zero);
        $context->builder->branchIf($isEmpty, $emptyBb, $workBb);

        $context->builder->positionAtEnd($emptyBb);
        $emptyRet = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $zero,
            $context->builder->pointerCast($context->constantFromString(''), $i8p)
        );
        $context->builder->returnValue($emptyRet);

        $context->builder->positionAtEnd($workBb);
        $cap = $context->builder->add(
            $length,
            $context->builder->add(
                $context->builder->unsignedDiv(
                    $context->builder->mul($length, $three),
                    $divisor
                ),
                $i64->constInt(4, false)
            )
        );
        $buf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->truncOrBitCast($cap, $sizeT)
        );
        $bufChar = $context->builder->pointerCast($buf, $i8p);
        $hexTable = $context->builder->pointerCast($context->constantFromString('0123456789ABCDEF'), $i8p);

        $iSlot = $context->builder->alloca($i64, 1);
        $posSlot = $context->builder->alloca($i64, 1);
        $lpSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $iSlot);
        $context->builder->store($zero, $posSlot);
        $context->builder->store($zero, $lpSlot);

        $head = $fn->appendBasicBlock('qp_enc_head');
        $body = $fn->appendBasicBlock('qp_enc_body');
        $done = $fn->appendBasicBlock('qp_enc_done');
        $context->builder->branch($head);
        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $length);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $c = $context->builder->load($context->builder->gep($srcChars, $i));
        $cI64 = $context->builder->zExt($c, $i64);
        $pos = $context->builder->load($posSlot);
        $lp = $context->builder->load($lpSlot);

        $hasNext = $context->builder->icmp(Builder::INT_SLT, $context->builder->addNoSignedWrap($i, $one), $length);
        $nextIsCr = $context->builder->and(
            $hasNext,
            $context->builder->icmp(
                Builder::INT_EQ,
                $context->builder->load($context->builder->gep($srcChars, $context->builder->addNoSignedWrap($i, $one))),
                $i8->constInt(13, false)
            )
        );
        $isCrLf = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $c, $i8->constInt(13, false)),
            $context->builder->and(
                $hasNext,
                $context->builder->icmp(
                    Builder::INT_EQ,
                    $context->builder->load($context->builder->gep($srcChars, $context->builder->addNoSignedWrap($i, $one))),
                    $i8->constInt(10, false)
                )
            )
        );

        $crlfBb = $fn->appendBasicBlock('qp_enc_crlf');
        $checkBb = $fn->appendBasicBlock('qp_enc_check');
        $context->builder->branchIf($isCrLf, $crlfBb, $checkBb);

        $context->builder->positionAtEnd($crlfBb);
        $context->builder->store($i8->constInt(13, false), $context->builder->gep($bufChar, $pos));
        $context->builder->store($i8->constInt(10, false), $context->builder->gep($bufChar, $context->builder->addNoSignedWrap($pos, $one)));
        $context->builder->store($context->builder->addNoSignedWrap($pos, $two), $posSlot);
        $context->builder->store($zero, $lpSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $two), $iSlot);
        $afterCrlf = $fn->appendBasicBlock('qp_enc_after_crlf');
        $context->builder->branch($afterCrlf);
        $context->builder->positionAtEnd($afterCrlf);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($checkBb);
        $needEncode = $context->builder->or(
            $context->builder->or(
                $context->builder->or(
                    $context->builder->icmp(Builder::INT_SLT, $c, $i8->constInt(32, false)),
                    $context->builder->icmp(Builder::INT_EQ, $c, $i8->constInt(127, false))
                ),
                $context->builder->icmp(Builder::INT_NE, $context->builder->and($c, $i8->constInt(0x80, false)), $i8->constInt(0, false))
            ),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $c, $i8->constInt(61, false)),
                $context->builder->and(
                    $context->builder->icmp(Builder::INT_EQ, $c, $i8->constInt(32, false)),
                    $nextIsCr
                )
            )
        );

        $encodedBb = $fn->appendBasicBlock('qp_enc_encoded');
        $plainBb = $fn->appendBasicBlock('qp_enc_plain');
        $afterBb = $fn->appendBasicBlock('qp_enc_after');
        $context->builder->branchIf($needEncode, $encodedBb, $plainBb);

        $context->builder->positionAtEnd($encodedBb);
        $lpPlus3 = $context->builder->addNoSignedWrap($lp, $three);
        $soft1 = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGT, $lpPlus3, $maxl),
            $context->builder->icmp(Builder::INT_SLE, $c, $i8->constInt(0x7f, false))
        );
        $soft2 = $context->builder->and(
            $context->builder->and(
                $context->builder->icmp(Builder::INT_SGT, $c, $i8->constInt(0x7f, false)),
                $context->builder->icmp(Builder::INT_SLE, $c, $i8->constInt(0xdf, false))
            ),
            $context->builder->icmp(Builder::INT_SGT, $context->builder->addNoSignedWrap($lp, $three), $maxl)
        );
        $soft3 = $context->builder->and(
            $context->builder->and(
                $context->builder->icmp(Builder::INT_SGT, $c, $i8->constInt(0xdf, false)),
                $context->builder->icmp(Builder::INT_SLE, $c, $i8->constInt(0xef, false))
            ),
            $context->builder->icmp(Builder::INT_SGT, $context->builder->addNoSignedWrap($lp, $i64->constInt(6, false)), $maxl)
        );
        $soft4 = $context->builder->and(
            $context->builder->and(
                $context->builder->icmp(Builder::INT_SGT, $c, $i8->constInt(0xef, false)),
                $context->builder->icmp(Builder::INT_SLE, $c, $i8->constInt(0xf4, false))
            ),
            $context->builder->icmp(Builder::INT_SGT, $context->builder->addNoSignedWrap($lp, $i64->constInt(9, false)), $maxl)
        );
        $needSoft = $context->builder->or($context->builder->or($soft1, $soft2), $context->builder->or($soft3, $soft4));
        $softBb = $fn->appendBasicBlock('qp_enc_soft');
        $noSoftBb = $fn->appendBasicBlock('qp_enc_nosoft');
        $context->builder->branchIf($needSoft, $softBb, $noSoftBb);

        $context->builder->positionAtEnd($softBb);
        $posSoft = $context->builder->load($posSlot);
        $context->builder->store($i8->constInt(61, false), $context->builder->gep($bufChar, $posSoft));
        $context->builder->store($i8->constInt(13, false), $context->builder->gep($bufChar, $context->builder->addNoSignedWrap($posSoft, $one)));
        $context->builder->store($i8->constInt(10, false), $context->builder->gep($bufChar, $context->builder->addNoSignedWrap($posSoft, $two)));
        $posAfterSoft = $context->builder->addNoSignedWrap($posSoft, $three);
        $context->builder->store($posAfterSoft, $posSlot);
        $context->builder->store($three, $lpSlot);
        $context->builder->branch($noSoftBb);

        $context->builder->positionAtEnd($noSoftBb);
        $posEnc = $context->builder->load($posSlot);
        $hi = $context->builder->lShr($cI64, $i64->constInt(4, false));
        $lo = $context->builder->and($cI64, $i64->constInt(0xf, false));
        $context->builder->store($i8->constInt(61, false), $context->builder->gep($bufChar, $posEnc));
        $context->builder->store(
            $context->builder->load($context->builder->gep($hexTable, $hi)),
            $context->builder->gep($bufChar, $context->builder->addNoSignedWrap($posEnc, $one))
        );
        $context->builder->store(
            $context->builder->load($context->builder->gep($hexTable, $lo)),
            $context->builder->gep($bufChar, $context->builder->addNoSignedWrap($posEnc, $two))
        );
        $context->builder->store($context->builder->addNoSignedWrap($posEnc, $three), $posSlot);
        $context->builder->store($context->builder->addNoSignedWrap($lp, $three), $lpSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($afterBb);

        $context->builder->positionAtEnd($plainBb);
        $lpInc = $context->builder->addNoSignedWrap($lp, $one);
        $plainSoft = $context->builder->icmp(Builder::INT_SGT, $lpInc, $maxl);
        $plainSoftBb = $fn->appendBasicBlock('qp_enc_plain_soft');
        $plainNoSoftBb = $fn->appendBasicBlock('qp_enc_plain_nosoft');
        $plainWriteBb = $fn->appendBasicBlock('qp_enc_plain_write');
        $context->builder->branchIf($plainSoft, $plainSoftBb, $plainNoSoftBb);

        $context->builder->positionAtEnd($plainSoftBb);
        $posPs = $context->builder->load($posSlot);
        $context->builder->store($i8->constInt(61, false), $context->builder->gep($bufChar, $posPs));
        $context->builder->store($i8->constInt(13, false), $context->builder->gep($bufChar, $context->builder->addNoSignedWrap($posPs, $one)));
        $context->builder->store($i8->constInt(10, false), $context->builder->gep($bufChar, $context->builder->addNoSignedWrap($posPs, $two)));
        $context->builder->store($context->builder->addNoSignedWrap($posPs, $three), $posSlot);
        $context->builder->store($one, $lpSlot);
        $context->builder->branch($plainWriteBb);

        $context->builder->positionAtEnd($plainNoSoftBb);
        $context->builder->store($lpInc, $lpSlot);
        $context->builder->branch($plainWriteBb);

        $context->builder->positionAtEnd($plainWriteBb);
        $posPw = $context->builder->load($posSlot);
        $context->builder->store($c, $context->builder->gep($bufChar, $posPw));
        $context->builder->store($context->builder->addNoSignedWrap($posPw, $one), $posSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($afterBb);

        $context->builder->positionAtEnd($afterBb);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $outLen = $context->builder->load($posSlot);
        $context->builder->store($i8->constInt(0, false), $context->builder->gep($bufChar, $outLen));
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $outLen,
            $bufChar
        );
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->returnValue($result);
    }

    private static function implementDecode(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $arg = $fn->getParam(0);
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $three = $i64->constInt(3, false);
        $eq = $i8->constInt(61, false);

        $src = $context->builder->call($context->lookupFunction('__string__separate'), $arg);
        $inLen = $context->builder->load($context->builder->structGep($src, $map['length']));
        $srcChars = $context->builder->structGep($src, $map['value']);

        $emptyBb = $fn->appendBasicBlock('qp_dec_empty');
        $workBb = $fn->appendBasicBlock('qp_dec_work');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $inLen, $zero);
        $context->builder->branchIf($isEmpty, $emptyBb, $workBb);

        $context->builder->positionAtEnd($emptyBb);
        $emptyRet = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $zero,
            $context->builder->pointerCast($context->constantFromString(''), $i8p)
        );
        $context->builder->returnValue($emptyRet);

        $context->builder->positionAtEnd($workBb);
        $buf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->truncOrBitCast($context->builder->addNoSignedWrap($inLen, $one), $sizeT)
        );
        $bufChar = $context->builder->pointerCast($buf, $i8p);
        $iSlot = $context->builder->alloca($i64, 1);
        $jSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $iSlot);
        $context->builder->store($zero, $jSlot);

        $head = $fn->appendBasicBlock('qp_dec_head');
        $body = $fn->appendBasicBlock('qp_dec_body');
        $done = $fn->appendBasicBlock('qp_dec_done');
        $context->builder->branch($head);
        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $inLen);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($srcChars, $i));
        $isEq = $context->builder->icmp(Builder::INT_EQ, $ch, $eq);
        $eqBb = $fn->appendBasicBlock('qp_dec_eq');
        $plainBb = $fn->appendBasicBlock('qp_dec_plain');
        $afterBb = $fn->appendBasicBlock('qp_dec_after');
        $context->builder->branchIf($isEq, $eqBb, $plainBb);

        $context->builder->positionAtEnd($eqBb);
        $hasRoom = $context->builder->icmp(Builder::INT_SLT, $context->builder->addNoSignedWrap($i, $two), $inLen);
        $hiCh = $context->builder->load($context->builder->gep($srcChars, $context->builder->addNoSignedWrap($i, $one)));
        $loCh = $context->builder->load($context->builder->gep($srcChars, $context->builder->addNoSignedWrap($i, $two)));
        $hasHex = $context->builder->and(
            $hasRoom,
            $context->builder->and(self::isHexDigit($context, $hiCh), self::isHexDigit($context, $loCh))
        );
        $hexBb = $fn->appendBasicBlock('qp_dec_hex');
        $softBb = $fn->appendBasicBlock('qp_dec_soft');
        $context->builder->branchIf($hasHex, $hexBb, $softBb);

        $context->builder->positionAtEnd($hexBb);
        $hiVal = self::hex2int($context, $hiCh);
        $loVal = self::hex2int($context, $loCh);
        $byte = $context->builder->trunc(
            $context->builder->add(
                $context->builder->shl($context->builder->zExt($hiVal, $i64), $i64->constInt(4, false)),
                $context->builder->zExt($loVal, $i64)
            ),
            $i8
        );
        $jHex = $context->builder->load($jSlot);
        $context->builder->store($byte, $context->builder->gep($bufChar, $jHex));
        $context->builder->store($context->builder->addNoSignedWrap($jHex, $one), $jSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $three), $iSlot);
        $context->builder->branch($afterBb);

        $context->builder->positionAtEnd($softBb);
        $kSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($one, $kSlot);
        $skipHead = $fn->appendBasicBlock('qp_dec_skip_head');
        $skipBody = $fn->appendBasicBlock('qp_dec_skip_body');
        $skipDone = $fn->appendBasicBlock('qp_dec_skip_done');
        $context->builder->branch($skipHead);
        $context->builder->positionAtEnd($skipHead);
        $k = $context->builder->load($kSlot);
        $skipIdx = $context->builder->addNoSignedWrap($i, $k);
        $skipPast = $context->builder->icmp(Builder::INT_SGE, $skipIdx, $inLen);
        $context->builder->branchIf($skipPast, $skipDone, $skipBody);
        $context->builder->positionAtEnd($skipBody);
        $sk = $context->builder->load($context->builder->gep($srcChars, $skipIdx));
        $isWs = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $sk, $i8->constInt(32, false)),
            $context->builder->icmp(Builder::INT_EQ, $sk, $i8->constInt(9, false))
        );
        $skipStop = $fn->appendBasicBlock('qp_dec_skip_stop');
        $skipCont = $fn->appendBasicBlock('qp_dec_skip_cont');
        $context->builder->branchIf($isWs, $skipCont, $skipStop);
        $context->builder->positionAtEnd($skipCont);
        $context->builder->store($context->builder->addNoSignedWrap($k, $one), $kSlot);
        $context->builder->branch($skipHead);
        $context->builder->positionAtEnd($skipStop);
        $context->builder->branch($skipDone);
        $context->builder->positionAtEnd($skipDone);
        $kFinal = $context->builder->load($kSlot);
        $pastIn = $context->builder->icmp(Builder::INT_SGE, $context->builder->addNoSignedWrap($i, $kFinal), $inLen);
        $endBb = $fn->appendBasicBlock('qp_dec_end_input');
        $crlfBb = $fn->appendBasicBlock('qp_dec_crlf');
        $crlfTake = $fn->appendBasicBlock('qp_dec_crlf_take');
        $crBb = $fn->appendBasicBlock('qp_dec_cr');
        $crTake = $fn->appendBasicBlock('qp_dec_cr_take');
        $copyBb = $fn->appendBasicBlock('qp_dec_copy_eq');
        $context->builder->branchIf($pastIn, $endBb, $crlfBb);

        $context->builder->positionAtEnd($endBb);
        $context->builder->store($context->builder->addNoSignedWrap($i, $kFinal), $iSlot);
        $context->builder->branch($afterBb);

        $context->builder->positionAtEnd($crlfBb);
        $kIdx = $context->builder->addNoSignedWrap($i, $kFinal);
        $hasCrlf = $context->builder->and(
            $context->builder->icmp(Builder::INT_SLT, $context->builder->addNoSignedWrap($kIdx, $one), $inLen),
            $context->builder->and(
                $context->builder->icmp(Builder::INT_EQ, $context->builder->load($context->builder->gep($srcChars, $kIdx)), $i8->constInt(13, false)),
                $context->builder->icmp(
                    Builder::INT_EQ,
                    $context->builder->load($context->builder->gep($srcChars, $context->builder->addNoSignedWrap($kIdx, $one))),
                    $i8->constInt(10, false)
                )
            )
        );
        $context->builder->branchIf($hasCrlf, $crlfTake, $crBb);

        $context->builder->positionAtEnd($crlfTake);
        $context->builder->store($context->builder->addNoSignedWrap($i, $context->builder->addNoSignedWrap($kFinal, $two)), $iSlot);
        $context->builder->branch($afterBb);

        $context->builder->positionAtEnd($crBb);
        $hasCr = $context->builder->and(
            $context->builder->icmp(Builder::INT_SLT, $kIdx, $inLen),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $context->builder->load($context->builder->gep($srcChars, $kIdx)), $i8->constInt(13, false)),
                $context->builder->icmp(Builder::INT_EQ, $context->builder->load($context->builder->gep($srcChars, $kIdx)), $i8->constInt(10, false))
            )
        );
        $context->builder->branchIf($hasCr, $crTake, $copyBb);

        $context->builder->positionAtEnd($crTake);
        $context->builder->store($context->builder->addNoSignedWrap($i, $context->builder->addNoSignedWrap($kFinal, $one)), $iSlot);
        $context->builder->branch($afterBb);

        $context->builder->positionAtEnd($copyBb);
        $jCopy = $context->builder->load($jSlot);
        $context->builder->store($ch, $context->builder->gep($bufChar, $jCopy));
        $context->builder->store($context->builder->addNoSignedWrap($jCopy, $one), $jSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($afterBb);

        $context->builder->positionAtEnd($plainBb);
        $jPlain = $context->builder->load($jSlot);
        $context->builder->store($ch, $context->builder->gep($bufChar, $jPlain));
        $context->builder->store($context->builder->addNoSignedWrap($jPlain, $one), $jSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($afterBb);

        $context->builder->positionAtEnd($afterBb);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $outLen = $context->builder->load($jSlot);
        $context->builder->store($i8->constInt(0, false), $context->builder->gep($bufChar, $outLen));
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $outLen,
            $bufChar
        );
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->returnValue($result);
    }

    private static function isHexDigit(Context $context, Value $ch): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $c = $context->builder->zExt($ch, $i64);
        $isDigit = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $c, $i64->constInt(48, false)),
            $context->builder->icmp(Builder::INT_SLE, $c, $i64->constInt(57, false))
        );
        $isUpper = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $c, $i64->constInt(65, false)),
            $context->builder->icmp(Builder::INT_SLE, $c, $i64->constInt(70, false))
        );
        $isLower = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $c, $i64->constInt(97, false)),
            $context->builder->icmp(Builder::INT_SLE, $c, $i64->constInt(102, false))
        );

        return $context->builder->or($context->builder->or($isDigit, $isUpper), $isLower);
    }

    private static function hex2int(Context $context, Value $ch): Value
    {
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $c = $context->builder->zExt($ch, $i64);
        $isDigit = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $c, $i64->constInt(48, false)),
            $context->builder->icmp(Builder::INT_SLE, $c, $i64->constInt(57, false))
        );
        $isUpper = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $c, $i64->constInt(65, false)),
            $context->builder->icmp(Builder::INT_SLE, $c, $i64->constInt(70, false))
        );
        $digitVal = $context->builder->sub($c, $i64->constInt(48, false));
        $upperVal = $context->builder->sub($c, $i64->constInt(55, false));
        $lowerVal = $context->builder->sub($c, $i64->constInt(87, false));
        $fromDigit = $context->builder->select($isDigit, $digitVal, $upperVal);
        $fromUpper = $context->builder->select($isUpper, $fromDigit, $lowerVal);

        return $context->builder->trunc($fromUpper, $i8);
    }
}
