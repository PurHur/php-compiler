<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM __compiler_iconv — CharsetEngine subset without host ext/iconv (#6009, #6251).
 *
 * php-src: ext/iconv/iconv.c
 */
final class IconvRuntime
{
    private const ENC_UNKNOWN = 0;

    private const ENC_UTF8 = 1;

    private const ENC_ISO88591 = 2;

    private const ENC_ASCII = 3;

    /** @var list<string> */
    private const UTF8_ALIASES = ['UTF-8', 'UTF8'];

    /** @var list<string> */
    private const ISO88591_ALIASES = ['ISO-8859-1', 'ISO8859-1', 'LATIN1', 'LATIN-1'];

    /** @var list<string> */
    private const ASCII_ALIASES = ['ASCII', 'US-ASCII', 'USASCII'];

    public static function implement(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_iconv');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__compiler_iconv', $probe);

            return;
        }

        self::ensureMemcmp($context);

        $strPtr = $context->getTypeFromString('__string__*');
        $ft = $context->context->functionType(
            $strPtr,
            false,
            $strPtr,
            $strPtr,
            $strPtr
        );
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__compiler_iconv', $ft);

        $saved = $context->builder;
        $context->builder = $context->context->builderCreate();
        try {
            self::implementCompilerIconv($context, $fn);
        } finally {
            $context->builder->clearInsertionPosition();
            $context->builder = $saved;
        }

        $context->registerFunction('__compiler_iconv', $fn);
    }

    private static function implementCompilerIconv(Context $context, Value $fn): void
    {
        $entry = $fn->appendBasicBlock('iconv_entry');
        $context->builder->positionAtEnd($entry);

        $from = $fn->getParam(0);
        $to = $fn->getParam(1);
        $input = $fn->getParam(2);
        $strPtr = $context->getTypeFromString('__string__*');
        $nullStr = $strPtr->constNull();
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');

        $fromId = self::resolveEncoding($context, $fn, $from, 'iconv_from');
        $toId = self::resolveEncoding($context, $fn, $to, 'iconv_to');

        $fromOk = $context->builder->icmp(Builder::INT_NE, $fromId, $i32->constInt(self::ENC_UNKNOWN, false));
        $toOk = $context->builder->icmp(Builder::INT_NE, $toId, $i32->constInt(self::ENC_UNKNOWN, false));
        $encOk = $context->builder->and($fromOk, $toOk);

        $convertBb = $fn->appendBasicBlock('iconv_convert');
        $fromErrBb = $fn->appendBasicBlock('iconv_from_err');
        $toErrBb = $fn->appendBasicBlock('iconv_to_err');
        $fromCheckBb = $fn->appendBasicBlock('iconv_from_check');

        $context->builder->branchIf($fromOk, $fromCheckBb, $fromErrBb);
        $context->builder->positionAtEnd($fromCheckBb);
        $context->builder->branchIf($toOk, $convertBb, $toErrBb);

        $context->builder->positionAtEnd($fromErrBb);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($toErrBb);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($convertBb);
        $sameEnc = $context->builder->icmp(Builder::INT_EQ, $fromId, $toId);
        $identityBb = $fn->appendBasicBlock('iconv_identity');
        $matrixBb = $fn->appendBasicBlock('iconv_matrix');
        $context->builder->branchIf($sameEnc, $identityBb, $matrixBb);

        $context->builder->positionAtEnd($identityBb);
        $copy = $context->builder->call($context->lookupFunction('__string__separate'), $input);
        $context->builder->returnValue($copy);

        $context->builder->positionAtEnd($matrixBb);
        self::convertMatrix($context, $fn, $fromId, $toId, $input, $nullStr);
    }

    private static function convertMatrix(
        Context $context,
        Value $fn,
        Value $fromId,
        Value $toId,
        Value $input,
        Value $nullStr
    ): void {
        $i32 = $context->getTypeFromString('int32');
        $utf8 = $i32->constInt(self::ENC_UTF8, false);
        $latin1 = $i32->constInt(self::ENC_ISO88591, false);
        $ascii = $i32->constInt(self::ENC_ASCII, false);

        $isLatin1ToUtf8 = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $fromId, $latin1),
            $context->builder->icmp(Builder::INT_EQ, $toId, $utf8)
        );
        $isUtf8ToLatin1 = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $fromId, $utf8),
            $context->builder->icmp(Builder::INT_EQ, $toId, $latin1)
        );
        $isAsciiToUtf8 = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $fromId, $ascii),
            $context->builder->icmp(Builder::INT_EQ, $toId, $utf8)
        );
        $isUtf8ToAscii = $context->builder->and(
            $context->builder->icmp(Builder::INT_EQ, $fromId, $utf8),
            $context->builder->icmp(Builder::INT_EQ, $toId, $ascii)
        );

        $l1u8Bb = $fn->appendBasicBlock('iconv_l1_u8');
        $u8l1Bb = $fn->appendBasicBlock('iconv_u8_l1');
        $au8Bb = $fn->appendBasicBlock('iconv_a_u8');
        $u8aBb = $fn->appendBasicBlock('iconv_u8_a');
        $failBb = $fn->appendBasicBlock('iconv_fail');
        $tryU8l1 = $fn->appendBasicBlock('iconv_try_u8_l1');
        $tryAu8 = $fn->appendBasicBlock('iconv_try_a_u8');
        $tryU8a = $fn->appendBasicBlock('iconv_try_u8_a');

        $context->builder->branchIf($isLatin1ToUtf8, $l1u8Bb, $tryU8l1);

        $context->builder->positionAtEnd($l1u8Bb);
        $context->builder->returnValue(self::latin1ToUtf8($context, $fn, $input));

        $context->builder->positionAtEnd($tryU8l1);
        $context->builder->branchIf($isUtf8ToLatin1, $u8l1Bb, $tryAu8);

        $context->builder->positionAtEnd($u8l1Bb);
        $context->builder->returnValue(self::utf8ToLatin1($context, $fn, $input, $nullStr));

        $context->builder->positionAtEnd($tryAu8);
        $context->builder->branchIf($isAsciiToUtf8, $au8Bb, $tryU8a);

        $context->builder->positionAtEnd($au8Bb);
        $context->builder->returnValue(self::asciiToUtf8($context, $fn, $input, $nullStr));

        $context->builder->positionAtEnd($tryU8a);
        $context->builder->branchIf($isUtf8ToAscii, $u8aBb, $failBb);

        $context->builder->positionAtEnd($u8aBb);
        $context->builder->returnValue(self::utf8ToAscii($context, $fn, $input, $nullStr));

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullStr);
    }

    private static function resolveEncoding(Context $context, Value $fn, Value $str, string $prefix): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $unknown = $i32->constInt(self::ENC_UNKNOWN, false);
        $check = $fn->appendBasicBlock($prefix.'_head');
        $context->builder->positionAtEnd($check);

        $hit = $unknown;
        foreach (
            [
                [self::UTF8_ALIASES, self::ENC_UTF8],
                [self::ISO88591_ALIASES, self::ENC_ISO88591],
                [self::ASCII_ALIASES, self::ENC_ASCII],
            ] as [$aliases, $id]
        ) {
            foreach ($aliases as $idx => $alias) {
                $match = self::identicalToAsciiLiteral($context, $fn, $str, $alias, $prefix.'_'.$id.'_'.$idx);
                $isMatch = $context->builder->icmp(
                    Builder::INT_EQ,
                    $match,
                    $context->getTypeFromString('int1')->constInt(1, false)
                );
                $hit = $context->builder->select(
                    $isMatch,
                    $i32->constInt($id, false),
                    $hit
                );
            }
        }

        return $hit;
    }

    private static function latin1ToUtf8(Context $context, Value $fn, Value $input): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $highBit = $i8->constInt(0x80, false);

        $src = $context->builder->call($context->lookupFunction('__string__separate'), $input);
        $len = $context->builder->load($context->builder->structGep($src, $map['length']));
        $srcChars = $context->builder->structGep($src, $map['value']);

        $outLenSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $outLenSlot);
        $iSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $iSlot);

        self::scanLatin1ToUtf8Len($context, $fn, $srcChars, $len, $iSlot, $outLenSlot, $i64, $i8, $zero, $one, $two, $highBit);

        $outLen = $context->builder->load($outLenSlot);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $outLen);
        $context->builder->store($outLen, $context->builder->structGep($dest, $map['length']));
        $destChars = $context->builder->structGep($dest, $map['value']);

        $posSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $posSlot);
        $context->builder->store($zero, $iSlot);

        self::writeLatin1ToUtf8($context, $fn, $srcChars, $len, $destChars, $iSlot, $posSlot, $i64, $i8, $zero, $one, $two, $highBit);

        return $dest;
    }

    private static function scanLatin1ToUtf8Len(
        Context $context,
        Value $fn,
        Value $srcChars,
        Value $len,
        Value $iSlot,
        Value $outLenSlot,
        $i64,
        $i8,
        Value $zero,
        Value $one,
        Value $two,
        Value $highBit
    ): void {
        $head = $fn->appendBasicBlock('l1u8_len_head');
        $body = $fn->appendBasicBlock('l1u8_len_body');
        $done = $fn->appendBasicBlock('l1u8_len_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($srcChars, $i));
        $isAscii = $context->builder->icmp(Builder::INT_ULT, $ch, $highBit);
        $add = $context->builder->select($isAscii, $one, $two);
        $outLen = $context->builder->load($outLenSlot);
        $context->builder->store($context->builder->addNoSignedWrap($outLen, $add), $outLenSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function writeLatin1ToUtf8(
        Context $context,
        Value $fn,
        Value $srcChars,
        Value $len,
        Value $destChars,
        Value $iSlot,
        Value $posSlot,
        $i64,
        $i8,
        Value $zero,
        Value $one,
        Value $two,
        Value $highBit
    ): void {
        $charPtr = $context->getTypeFromString('char*');
        $head = $fn->appendBasicBlock('l1u8_wr_head');
        $body = $fn->appendBasicBlock('l1u8_wr_body');
        $done = $fn->appendBasicBlock('l1u8_wr_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($srcChars, $i));
        $pos = $context->builder->load($posSlot);
        $isAscii = $context->builder->icmp(Builder::INT_ULT, $ch, $highBit);
        $asciiBb = $fn->appendBasicBlock('l1u8_wr_ascii');
        $multiBb = $fn->appendBasicBlock('l1u8_wr_multi');
        $mergeBb = $fn->appendBasicBlock('l1u8_wr_merge');
        $context->builder->branchIf($isAscii, $asciiBb, $multiBb);

        $context->builder->positionAtEnd($asciiBb);
        $context->builder->store($ch, $context->builder->gep($destChars, $pos));
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($multiBb);
        $pos2 = $context->builder->load($posSlot);
        $b0 = $context->builder->or(
            $i8->constInt(0xC0, false),
            $context->builder->lShr($ch, $i8->constInt(6, false))
        );
        $b1 = $context->builder->or(
            $i8->constInt(0x80, false),
            $context->builder->and($ch, $i8->constInt(0x3F, false))
        );
        $context->builder->store($b0, $context->builder->gep($destChars, $pos2));
        $context->builder->store($b1, $context->builder->gep($destChars, $context->builder->addNoSignedWrap($pos2, $one)));
        $context->builder->store($context->builder->addNoSignedWrap($pos2, $two), $posSlot);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function utf8ToLatin1(Context $context, Value $fn, Value $input, Value $nullStr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);

        $src = $context->builder->call($context->lookupFunction('__string__separate'), $input);
        $len = $context->builder->load($context->builder->structGep($src, $map['length']));
        $srcChars = $context->builder->structGep($src, $map['value']);

        $outLenSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $outLenSlot);
        $iSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $iSlot);
        $okSlot = $context->builder->alloca($context->getTypeFromString('int1'), 1);
        $context->builder->store($context->getTypeFromString('int1')->constInt(1, false), $okSlot);

        self::scanUtf8ToLatin1Len($context, $fn, $srcChars, $len, $iSlot, $outLenSlot, $okSlot, $i64, $i8, $zero, $one, $two);

        $ok = $context->builder->load($okSlot);
        $failBb = $fn->appendBasicBlock('u8l1_fail');
        $okBb = $fn->appendBasicBlock('u8l1_ok');
        $context->builder->branchIf($ok, $okBb, $failBb);
        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($okBb);
        $outLen = $context->builder->load($outLenSlot);
        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $outLen);
        $context->builder->store($outLen, $context->builder->structGep($dest, $map['length']));
        $destChars = $context->builder->structGep($dest, $map['value']);
        $context->builder->store($zero, $iSlot);
        $posSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $posSlot);
        self::writeUtf8ToLatin1($context, $fn, $srcChars, $len, $destChars, $iSlot, $posSlot, $i64, $i8, $zero, $one, $two);

        return $dest;
    }

    private static function scanUtf8ToLatin1Len(
        Context $context,
        Value $fn,
        Value $srcChars,
        Value $len,
        Value $iSlot,
        Value $outLenSlot,
        Value $okSlot,
        $i64,
        $i8,
        Value $zero,
        Value $one,
        Value $two
    ): void {
        $highBit = $i8->constInt(0x80, false);
        $c0mask = $i8->constInt(0xE0, false);
        $c0tag = $i8->constInt(0xC0, false);
        $contMask = $i8->constInt(0xC0, false);
        $contTag = $i8->constInt(0x80, false);
        $ff = $i8->constInt(0xFF, false);

        $head = $fn->appendBasicBlock('u8l1_len_head');
        $body = $fn->appendBasicBlock('u8l1_len_body');
        $done = $fn->appendBasicBlock('u8l1_len_done');
        $fail = $fn->appendBasicBlock('u8l1_len_fail');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $ok = $context->builder->load($okSlot);
        $context->builder->branchIf($ok, $body, $fail);
        $context->builder->positionAtEnd($body);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $step = $fn->appendBasicBlock('u8l1_len_step');
        $context->builder->branchIf($atEnd, $done, $step);

        $context->builder->positionAtEnd($step);
        $ch = $context->builder->load($context->builder->gep($srcChars, $i));
        $isAscii = $context->builder->icmp(Builder::INT_ULT, $ch, $highBit);
        $twoByteBb = $fn->appendBasicBlock('u8l1_len_two');
        $asciiBb = $fn->appendBasicBlock('u8l1_len_ascii');
        $context->builder->branchIf($isAscii, $asciiBb, $twoByteBb);

        $context->builder->positionAtEnd($asciiBb);
        $outLen = $context->builder->load($outLenSlot);
        $context->builder->store($context->builder->addNoSignedWrap($outLen, $one), $outLenSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($twoByteBb);
        $hasSecond = $context->builder->icmp(Builder::INT_SLT, $context->builder->addNoSignedWrap($i, $one), $len);
        $twoOkBb = $fn->appendBasicBlock('u8l1_len_two_ok');
        $context->builder->branchIf($hasSecond, $twoOkBb, $fail);

        $context->builder->positionAtEnd($twoOkBb);
        $isTwo = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->and($ch, $c0mask),
            $c0tag
        );
        $twoBodyBb = $fn->appendBasicBlock('u8l1_len_two_body');
        $context->builder->branchIf($isTwo, $twoBodyBb, $fail);

        $context->builder->positionAtEnd($twoBodyBb);
        $ch2 = $context->builder->load($context->builder->gep($srcChars, $context->builder->addNoSignedWrap($i, $one)));
        $contOk = $context->builder->icmp(Builder::INT_EQ, $context->builder->and($ch2, $contMask), $contTag);
        $contBb = $fn->appendBasicBlock('u8l1_len_cont');
        $context->builder->branchIf($contOk, $contBb, $fail);

        $context->builder->positionAtEnd($contBb);
        $cp = $context->builder->or(
            $context->builder->shl($context->builder->and($ch, $i8->constInt(0x1F, false)), $i8->constInt(6, false)),
            $context->builder->and($ch2, $i8->constInt(0x3F, false))
        );
        $fits = $context->builder->icmp(Builder::INT_ULE, $cp, $ff);
        $fitsBb = $fn->appendBasicBlock('u8l1_len_fits');
        $context->builder->branchIf($fits, $fitsBb, $fail);

        $context->builder->positionAtEnd($fitsBb);
        $outLen2 = $context->builder->load($outLenSlot);
        $context->builder->store($context->builder->addNoSignedWrap($outLen2, $one), $outLenSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $two), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($fail);
        $context->builder->store($context->getTypeFromString('int1')->constInt(0, false), $okSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
    }

    private static function writeUtf8ToLatin1(
        Context $context,
        Value $fn,
        Value $srcChars,
        Value $len,
        Value $destChars,
        Value $iSlot,
        Value $posSlot,
        $i64,
        $i8,
        Value $zero,
        Value $one,
        Value $two
    ): void {
        $highBit = $i8->constInt(0x80, false);
        $c0mask = $i8->constInt(0xE0, false);
        $c0tag = $i8->constInt(0xC0, false);
        $contMask = $i8->constInt(0xC0, false);
        $contTag = $i8->constInt(0x80, false);

        $head = $fn->appendBasicBlock('u8l1_wr_head');
        $body = $fn->appendBasicBlock('u8l1_wr_body');
        $done = $fn->appendBasicBlock('u8l1_wr_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($srcChars, $i));
        $pos = $context->builder->load($posSlot);
        $isAscii = $context->builder->icmp(Builder::INT_ULT, $ch, $highBit);
        $asciiBb = $fn->appendBasicBlock('u8l1_wr_ascii');
        $twoBb = $fn->appendBasicBlock('u8l1_wr_two');
        $mergeBb = $fn->appendBasicBlock('u8l1_wr_merge');
        $context->builder->branchIf($isAscii, $asciiBb, $twoBb);

        $context->builder->positionAtEnd($asciiBb);
        $context->builder->store($ch, $context->builder->gep($destChars, $pos));
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($twoBb);
        $ch2 = $context->builder->load($context->builder->gep($srcChars, $context->builder->addNoSignedWrap($i, $one)));
        $cp = $context->builder->or(
            $context->builder->shl($context->builder->and($ch, $i8->constInt(0x1F, false)), $i8->constInt(6, false)),
            $context->builder->and($ch2, $i8->constInt(0x3F, false))
        );
        $context->builder->store($cp, $context->builder->gep($destChars, $pos));
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $two), $iSlot);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
    }

    private static function asciiToUtf8(Context $context, Value $fn, Value $input, Value $nullStr): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $high = $i8->constInt(0x7F, false);

        $src = $context->builder->call($context->lookupFunction('__string__separate'), $input);
        $len = $context->builder->load($context->builder->structGep($src, $map['length']));
        $srcChars = $context->builder->structGep($src, $map['value']);

        $okSlot = $context->builder->alloca($context->getTypeFromString('int1'), 1);
        $context->builder->store($context->getTypeFromString('int1')->constInt(1, false), $okSlot);
        $iSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $iSlot);

        $head = $fn->appendBasicBlock('au8_chk_head');
        $body = $fn->appendBasicBlock('au8_chk_body');
        $done = $fn->appendBasicBlock('au8_chk_done');
        $fail = $fn->appendBasicBlock('au8_chk_fail');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $ok = $context->builder->load($okSlot);
        $context->builder->branchIf($ok, $body, $fail);
        $context->builder->positionAtEnd($body);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $step = $fn->appendBasicBlock('au8_chk_step');
        $context->builder->branchIf($atEnd, $done, $step);

        $context->builder->positionAtEnd($step);
        $ch = $context->builder->load($context->builder->gep($srcChars, $i));
        $valid = $context->builder->icmp(Builder::INT_ULE, $ch, $high);
        $validBb = $fn->appendBasicBlock('au8_chk_valid');
        $context->builder->branchIf($valid, $validBb, $fail);
        $context->builder->positionAtEnd($validBb);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($fail);
        $context->builder->store($context->getTypeFromString('int1')->constInt(0, false), $okSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $okFinal = $context->builder->load($okSlot);
        $failBb = $fn->appendBasicBlock('au8_fail');
        $okBb = $fn->appendBasicBlock('au8_ok');
        $context->builder->branchIf($okFinal, $okBb, $failBb);
        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullStr);
        $context->builder->positionAtEnd($okBb);

        return $context->builder->call($context->lookupFunction('__string__separate'), $input);
    }

    private static function utf8ToAscii(Context $context, Value $fn, Value $input, Value $nullStr): Value
    {
        $latin1 = self::utf8ToLatin1($context, $fn, $input, $nullStr);
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $latin1,
            $context->getTypeFromString('__string__*')->constNull()
        );
        $failBb = $fn->appendBasicBlock('u8a_fail');
        $okBb = $fn->appendBasicBlock('u8a_ok');
        $context->builder->branchIf($isNull, $failBb, $okBb);
        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($nullStr);

        $context->builder->positionAtEnd($okBb);
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $high = $i8->constInt(0x7F, false);

        $len = $context->builder->load($context->builder->structGep($latin1, $map['length']));
        $chars = $context->builder->structGep($latin1, $map['value']);
        $okSlot = $context->builder->alloca($context->getTypeFromString('int1'), 1);
        $context->builder->store($context->getTypeFromString('int1')->constInt(1, false), $okSlot);
        $iSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $iSlot);

        $head = $fn->appendBasicBlock('u8a_chk_head');
        $body = $fn->appendBasicBlock('u8a_chk_body');
        $done = $fn->appendBasicBlock('u8a_chk_done');
        $fail = $fn->appendBasicBlock('u8a_chk_fail');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $ok = $context->builder->load($okSlot);
        $context->builder->branchIf($ok, $body, $fail);
        $context->builder->positionAtEnd($body);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $step = $fn->appendBasicBlock('u8a_chk_s');
        $context->builder->branchIf($atEnd, $done, $step);

        $context->builder->positionAtEnd($step);
        $ch = $context->builder->load($context->builder->gep($chars, $i));
        $valid = $context->builder->icmp(Builder::INT_ULE, $ch, $high);
        $validBb = $fn->appendBasicBlock('u8a_chk_v');
        $context->builder->branchIf($valid, $validBb, $fail);
        $context->builder->positionAtEnd($validBb);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($fail);
        $context->builder->store($context->getTypeFromString('int1')->constInt(0, false), $okSlot);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);
        $okFinal = $context->builder->load($okSlot);
        $fail2 = $fn->appendBasicBlock('u8a_fail2');
        $ok2 = $fn->appendBasicBlock('u8a_ok2');
        $context->builder->branchIf($okFinal, $ok2, $fail2);
        $context->builder->positionAtEnd($fail2);
        $context->builder->returnValue($nullStr);
        $context->builder->positionAtEnd($ok2);

        return $latin1;
    }

    private static function identicalToAsciiLiteral(
        Context $context,
        Value $fn,
        Value $name,
        string $literal,
        string $suffix
    ): Value {
        $map = $context->structFieldMap['__string__'];
        $nameLen = $context->builder->load(
            $context->builder->structGep($name, $map['length'])
        );
        $litLen = $context->getTypeFromString('int64')->constInt(\strlen($literal), false);
        $lenEq = $context->builder->icmp(Builder::INT_EQ, $nameLen, $litLen);
        $i1 = $context->getTypeFromString('int1');
        $falseVal = $i1->constInt(0, false);

        $lenOk = $fn->appendBasicBlock('iconv_lit_len_ok_'.$suffix);
        $lenBad = $fn->appendBasicBlock('iconv_lit_len_bad_'.$suffix);
        $merge = $fn->appendBasicBlock('iconv_lit_done_'.$suffix);
        $context->builder->branchIf($lenEq, $lenOk, $lenBad);

        $context->builder->positionAtEnd($lenBad);
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($lenOk);
        $sizeT = $context->getTypeFromString('size_t');
        $len = $context->builder->zExt($nameLen, $sizeT);
        $i8p = $context->getTypeFromString('int8*');
        $litGlobal = $context->constantFromString($literal);
        $litPtr = $context->builder->pointerCast($litGlobal, $i8p);
        $nameValPtr = $context->builder->structGep($name, $map['value']);
        $namePtr = $context->builder->pointerCast($nameValPtr, $i8p);
        $cmp = $context->builder->call(
            $context->lookupFunction('memcmp'),
            $namePtr,
            $litPtr,
            $len
        );
        $strEq = $context->builder->icmp(
            Builder::INT_EQ,
            $cmp,
            $cmp->typeOf()->constInt(0, false)
        );
        $context->builder->branch($merge);

        $context->builder->positionAtEnd($merge);
        $phi = $context->builder->phi($i1);
        $phi->addIncoming($falseVal, $lenBad);
        $phi->addIncoming($strEq, $lenOk);

        return $phi;
    }

    private static function ensureMemcmp(Context $context): void
    {
        try {
            $context->lookupFunction('memcmp');
        } catch (\Throwable) {
            $i32 = $context->getTypeFromString('int32');
            $i8p = $context->getTypeFromString('int8')->pointerTo();
            $sizeT = $context->getTypeFromString('size_t');
            $context->module->addFunction(
                'memcmp',
                $context->context->functionType($i32, false, $i8p, $i8p, $sizeT)
            );
        }
    }
}
