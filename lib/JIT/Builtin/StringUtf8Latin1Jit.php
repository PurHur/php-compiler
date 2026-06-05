<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM bodies for utf8_encode()/utf8_decode() (former phpc_utf8_latin1.c, #5279).
 *
 * php-src: ext/standard/basic_functions.c — parity with ext/standard/VmString.php.
 */
final class StringUtf8Latin1Jit
{
    public static function implement(Context $context): void
    {
        self::implementIfMissing($context, '__compiler_utf8_encode', self::implementEncode(...));
        self::implementIfMissing($context, '__compiler_utf8_decode', self::implementDecode(...));
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
        $asciiMax = $i8->constInt(0x80, false);

        $src = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        $length = $context->builder->load($context->builder->structGep($src, $map['length']));
        $srcChars = $context->builder->structGep($src, $map['value']);

        $emptyBb = $fn->appendBasicBlock('u8enc_empty');
        $workBb = $fn->appendBasicBlock('u8enc_work');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $length, $zero);
        $context->builder->branchIf($isEmpty, $emptyBb, $workBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue(self::emptyString($context, $zero));

        $context->builder->positionAtEnd($workBb);
        $cap = $context->builder->mul($length, $two);
        $buf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->truncOrBitCast($cap, $sizeT)
        );
        $bufChar = $context->builder->pointerCast($buf, $i8p);

        $iSlot = $context->builder->alloca($i64, 1);
        $posSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $iSlot);
        $context->builder->store($zero, $posSlot);

        $head = $fn->appendBasicBlock('u8enc_head');
        $body = $fn->appendBasicBlock('u8enc_body');
        $asciiBb = $fn->appendBasicBlock('u8enc_ascii');
        $multiBb = $fn->appendBasicBlock('u8enc_multi');
        $incBb = $fn->appendBasicBlock('u8enc_inc');
        $done = $fn->appendBasicBlock('u8enc_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $length);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $c = $context->builder->load($context->builder->gep($srcChars, $i));
        $isAscii = $context->builder->icmp(Builder::INT_ULT, $c, $asciiMax);
        $context->builder->branchIf($isAscii, $asciiBb, $multiBb);

        $context->builder->positionAtEnd($asciiBb);
        $pos = $context->builder->load($posSlot);
        $context->builder->store($c, $context->builder->gep($bufChar, $context->builder->trunc($pos, $context->getTypeFromString('int32'))));
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->branch($incBb);

        $context->builder->positionAtEnd($multiBb);
        $pos = $context->builder->load($posSlot);
        $pos32 = $context->builder->trunc($pos, $context->getTypeFromString('int32'));
        $cI64 = $context->builder->zExt($c, $i64);
        $hi = $context->builder->trunc(
            $context->builder->or(
                $i8->constInt(0xC0, false),
                $context->builder->trunc($context->builder->lShr($cI64, $i64->constInt(6, false)), $i8)
            ),
            $i8
        );
        $lo = $context->builder->trunc(
            $context->builder->or(
                $i8->constInt(0x80, false),
                $context->builder->trunc(
                    $context->builder->and($cI64, $i64->constInt(0x3F, false)),
                    $i8
                )
            ),
            $i8
        );
        $context->builder->store($hi, $context->builder->gep($bufChar, $pos32));
        $context->builder->store($lo, $context->builder->gep($bufChar, $context->builder->add($pos32, $context->getTypeFromString('int32')->constInt(1, false))));
        $context->builder->store($context->builder->addNoSignedWrap($pos, $two), $posSlot);
        $context->builder->branch($incBb);

        $context->builder->positionAtEnd($incBb);
        $context->builder->store($context->builder->addNoSignedWrap($context->builder->load($iSlot), $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $outLen = $context->builder->load($posSlot);
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
        $str = $fn->getParam(0);
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $three = $i64->constInt(3, false);
        $four = $i64->constInt(4, false);
        $qmark = $i8->constInt(0x3F, false);
        $asciiMax = $i8->constInt(0x80, false);

        $src = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        $length = $context->builder->load($context->builder->structGep($src, $map['length']));
        $srcChars = $context->builder->structGep($src, $map['value']);

        $emptyBb = $fn->appendBasicBlock('u8dec_empty');
        $workBb = $fn->appendBasicBlock('u8dec_work');
        $isEmpty = $context->builder->icmp(Builder::INT_EQ, $length, $zero);
        $context->builder->branchIf($isEmpty, $emptyBb, $workBb);

        $context->builder->positionAtEnd($emptyBb);
        $context->builder->returnValue(self::emptyString($context, $zero));

        $context->builder->positionAtEnd($workBb);
        $buf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->truncOrBitCast($length, $sizeT)
        );
        $bufChar = $context->builder->pointerCast($buf, $i8p);

        $iSlot = $context->builder->alloca($i64, 1);
        $posSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $iSlot);
        $context->builder->store($zero, $posSlot);

        $head = $fn->appendBasicBlock('u8dec_head');
        $body = $fn->appendBasicBlock('u8dec_body');
        $done = $fn->appendBasicBlock('u8dec_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $length);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $c = $context->builder->load($context->builder->gep($srcChars, $i));
        $cI64 = $context->builder->zExt($c, $i64);
        $pos = $context->builder->load($posSlot);
        $pos32 = $context->builder->trunc($pos, $i32);

        $isAscii = $context->builder->icmp(Builder::INT_ULT, $c, $asciiMax);
        $isC0 = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->and($cI64, $i64->constInt(0xE0, false)),
            $i64->constInt(0xC0, false)
        );
        $isE0 = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->and($cI64, $i64->constInt(0xF0, false)),
            $i64->constInt(0xE0, false)
        );
        $isF0 = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->and($cI64, $i64->constInt(0xF8, false)),
            $i64->constInt(0xF0, false)
        );

        $asciiBb = $fn->appendBasicBlock('u8dec_ascii');
        $c0Bb = $fn->appendBasicBlock('u8dec_c0');
        $c0Body = $fn->appendBasicBlock('u8dec_c0_body');
        $c0Bad = $fn->appendBasicBlock('u8dec_c0_bad');
        $c0Good = $fn->appendBasicBlock('u8dec_c0_good');
        $e0Bb = $fn->appendBasicBlock('u8dec_e0');
        $e0Body = $fn->appendBasicBlock('u8dec_e0_body');
        $e0Bad = $fn->appendBasicBlock('u8dec_e0_bad');
        $e0Good = $fn->appendBasicBlock('u8dec_e0_good');
        $f0Bb = $fn->appendBasicBlock('u8dec_f0');
        $f0Body = $fn->appendBasicBlock('u8dec_f0_body');
        $f0Bad = $fn->appendBasicBlock('u8dec_f0_bad');
        $f0Good = $fn->appendBasicBlock('u8dec_f0_good');
        $badBb = $fn->appendBasicBlock('u8dec_bad');
        $mergeBb = $fn->appendBasicBlock('u8dec_merge');

        $context->builder->branchIf($isAscii, $asciiBb, $c0Bb);

        $context->builder->positionAtEnd($asciiBb);
        $context->builder->store($c, $context->builder->gep($bufChar, $pos32));
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($c0Bb);
        $context->builder->branchIf($isC0, $c0Body, $e0Bb);

        $context->builder->positionAtEnd($c0Body);
        $c0Min = $context->builder->icmp(Builder::INT_ULT, $c, $i8->constInt(0xC2, false));
        $hasNext = $context->builder->icmp(Builder::INT_SLT, $context->builder->addNoSignedWrap($i, $one), $length);
        $nextByte = $context->builder->load(
            $context->builder->gep($srcChars, $context->builder->addNoSignedWrap($i, $one))
        );
        $nextCont = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->and($context->builder->zExt($nextByte, $i64), $i64->constInt(0xC0, false)),
            $i64->constInt(0x80, false)
        );
        $c0Invalid = $context->builder->or($c0Min, $context->builder->or(
            $context->builder->not($hasNext),
            $context->builder->not($nextCont)
        ));
        $context->builder->branchIf($c0Invalid, $c0Bad, $c0Good);

        $context->builder->positionAtEnd($c0Bad);
        $context->builder->store($qmark, $context->builder->gep($bufChar, $pos32));
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($c0Good);
        $cp = $context->builder->or(
            $context->builder->shl($context->builder->and($cI64, $i64->constInt(0x1F, false)), $i64->constInt(6, false)),
            $context->builder->and($context->builder->zExt($nextByte, $i64), $i64->constInt(0x3F, false))
        );
        $outCh = $context->builder->trunc(
            $context->builder->select(
                $context->builder->icmp(Builder::INT_ULE, $cp, $i64->constInt(0xFF, false)),
                $cp,
                $i64->constInt(0x3F, false)
            ),
            $i8
        );
        $context->builder->store($outCh, $context->builder->gep($bufChar, $pos32));
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $two), $iSlot);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($e0Bb);
        $context->builder->branchIf($isE0, $e0Body, $f0Bb);

        $context->builder->positionAtEnd($e0Body);
        $b1 = $context->builder->load($context->builder->gep($srcChars, $context->builder->addNoSignedWrap($i, $one)));
        $b2 = $context->builder->load($context->builder->gep($srcChars, $context->builder->addNoSignedWrap($i, $two)));
        $hasTwo = $context->builder->icmp(Builder::INT_SLT, $context->builder->addNoSignedWrap($i, $two), $length);
        $b1Cont = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->and($context->builder->zExt($b1, $i64), $i64->constInt(0xC0, false)),
            $i64->constInt(0x80, false)
        );
        $b2Cont = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->and($context->builder->zExt($b2, $i64), $i64->constInt(0xC0, false)),
            $i64->constInt(0x80, false)
        );
        $e0Invalid = $context->builder->or(
            $context->builder->not($hasTwo),
            $context->builder->or($context->builder->not($b1Cont), $context->builder->not($b2Cont))
        );
        $context->builder->branchIf($e0Invalid, $e0Bad, $e0Good);

        $context->builder->positionAtEnd($e0Bad);
        $context->builder->store($qmark, $context->builder->gep($bufChar, $pos32));
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($e0Good);
        $cp = $context->builder->or(
            $context->builder->or(
                $context->builder->shl($context->builder->and($cI64, $i64->constInt(0x0F, false)), $i64->constInt(12, false)),
                $context->builder->shl($context->builder->and($context->builder->zExt($b1, $i64), $i64->constInt(0x3F, false)), $i64->constInt(6, false))
            ),
            $context->builder->and($context->builder->zExt($b2, $i64), $i64->constInt(0x3F, false))
        );
        $inRange = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $cp, $i64->constInt(0x800, false)),
            $context->builder->icmp(Builder::INT_SLE, $cp, $i64->constInt(0xFF, false))
        );
        $outCh = $context->builder->trunc(
            $context->builder->select($inRange, $cp, $i64->constInt(0x3F, false)),
            $i8
        );
        $context->builder->store($outCh, $context->builder->gep($bufChar, $pos32));
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $three), $iSlot);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($f0Bb);
        $context->builder->branchIf($isF0, $f0Body, $badBb);

        $context->builder->positionAtEnd($f0Body);
        $b1 = $context->builder->load($context->builder->gep($srcChars, $context->builder->addNoSignedWrap($i, $one)));
        $b2 = $context->builder->load($context->builder->gep($srcChars, $context->builder->addNoSignedWrap($i, $two)));
        $b3 = $context->builder->load($context->builder->gep($srcChars, $context->builder->addNoSignedWrap($i, $three)));
        $hasThree = $context->builder->icmp(Builder::INT_SLT, $context->builder->addNoSignedWrap($i, $three), $length);
        $b1Cont = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->and($context->builder->zExt($b1, $i64), $i64->constInt(0xC0, false)),
            $i64->constInt(0x80, false)
        );
        $b2Cont = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->and($context->builder->zExt($b2, $i64), $i64->constInt(0xC0, false)),
            $i64->constInt(0x80, false)
        );
        $b3Cont = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->and($context->builder->zExt($b3, $i64), $i64->constInt(0xC0, false)),
            $i64->constInt(0x80, false)
        );
        $f0Invalid = $context->builder->or(
            $context->builder->not($hasThree),
            $context->builder->or($context->builder->or($context->builder->not($b1Cont), $context->builder->not($b2Cont)), $context->builder->not($b3Cont))
        );
        $context->builder->branchIf($f0Invalid, $f0Bad, $f0Good);

        $context->builder->positionAtEnd($f0Bad);
        $context->builder->store($qmark, $context->builder->gep($bufChar, $pos32));
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($f0Good);
        $context->builder->store($qmark, $context->builder->gep($bufChar, $pos32));
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $four), $iSlot);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($badBb);
        $context->builder->store($qmark, $context->builder->gep($bufChar, $pos32));
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $outLen = $context->builder->load($posSlot);
        $result = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $outLen,
            $bufChar
        );
        $context->builder->call($context->lookupFunction('free'), $buf);
        $context->builder->returnValue($result);
    }

    private static function emptyString(Context $context, Value $zero): Value
    {
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $zero,
            $context->builder->pointerCast($context->constantFromString(''), $i8p)
        );
    }
}
