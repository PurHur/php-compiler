<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\standard\JitAddcslashes;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM bodies for addcslashes/stripcslashes runtime paths (former phpc_string_cslashes.c, #5652).
 */
final class StringCslashesJit
{
    public static function implement(Context $context): void
    {
        self::implementIfMissing($context, '__compiler_addcslashes', self::implementAddcslashes(...));
    }

    public static function ensureStripcslashes(Context $context): void
    {
        self::implementIfMissing($context, '__compiler_stripcslashes', self::implementStripcslashes(...));
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

    private static function implementAddcslashes(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $subject = $fn->getParam(0);
        $charlist = $fn->getParam(1);

        $maskSlot = self::buildMaskFromCharlist($context, $fn, $charlist);
        $result = JitAddcslashes::escapeWithMaskSlot($context, $subject, $maskSlot, $fn);
        $context->builder->returnValue($result);
    }

    private static function buildMaskFromCharlist(Context $context, LlvmFunction $fn, Value $charlist): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $three = $i64->constInt(3, false);
        $four = $i64->constInt(4, false);
        $dot = $i8->constInt(46, false);

        $clSrc = $context->builder->call($context->lookupFunction('__string__separate'), $charlist);
        $clLen = $context->builder->load($context->builder->structGep($clSrc, $map['length']));
        $clChars = $context->builder->structGep($clSrc, $map['value']);

        $buf = $context->builder->call(
            $context->lookupFunction('malloc'),
            $context->builder->truncOrBitCast($context->builder->add($clLen, $one), $sizeT)
        );
        $bufChar = $context->builder->pointerCast($buf, $i8p);
        $posSlot = $context->builder->alloca($i64, 1);
        $iSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $posSlot);
        $context->builder->store($zero, $iSlot);

        $head = $fn->appendBasicBlock('cl_expand_head');
        $body = $fn->appendBasicBlock('cl_expand_body');
        $done = $fn->appendBasicBlock('cl_expand_done');
        $context->builder->branch($head);
        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $clLen);
        $context->builder->branchIf($atEnd, $done, $body);
        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($clChars, $i));
        $pos = $context->builder->load($posSlot);
        $context->builder->store($ch, $context->builder->gep($bufChar, $pos));
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);
        $context->builder->positionAtEnd($done);
        $expandedLen = $context->builder->load($posSlot);

        $maskSlot = $context->builder->alloca($i8, 256);
        $context->intrinsic->memset($maskSlot, $i8->constInt(0, false), $i64->constInt(256, false), false);
        $context->builder->store($zero, $iSlot);

        $mHead = $fn->appendBasicBlock('cl_mask_head');
        $mBody = $fn->appendBasicBlock('cl_mask_body');
        $mDone = $fn->appendBasicBlock('cl_mask_done');
        $context->builder->branch($mHead);
        $context->builder->positionAtEnd($mHead);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $expandedLen);
        $context->builder->branchIf($atEnd, $mDone, $mBody);
        $context->builder->positionAtEnd($mBody);
        $c = $context->builder->load($context->builder->gep($bufChar, $i));
        $cI64 = $context->builder->zExt($c, $i64);
        $hasRange = $context->builder->and(
            $context->builder->icmp(Builder::INT_SLT, $context->builder->addNoSignedWrap($i, $three), $expandedLen),
            $context->builder->and(
                $context->builder->icmp(Builder::INT_EQ, $context->builder->load($context->builder->gep($bufChar, $context->builder->addNoSignedWrap($i, $one))), $dot),
                $context->builder->icmp(Builder::INT_EQ, $context->builder->load($context->builder->gep($bufChar, $context->builder->addNoSignedWrap($i, $two))), $dot)
            )
        );
        $rangeBlock = $fn->appendBasicBlock('cl_mask_range');
        $singleBlock = $fn->appendBasicBlock('cl_mask_single');
        $afterBlock = $fn->appendBasicBlock('cl_mask_after');
        $context->builder->branchIf($hasRange, $rangeBlock, $singleBlock);

        $context->builder->positionAtEnd($rangeBlock);
        $endC = $context->builder->load($context->builder->gep($bufChar, $context->builder->addNoSignedWrap($i, $three)));
        $endI64 = $context->builder->zExt($endC, $i64);
        $valid = $context->builder->icmp(Builder::INT_SGE, $endI64, $cI64);
        $rangeOk = $fn->appendBasicBlock('cl_mask_range_ok');
        $rangeBad = $fn->appendBasicBlock('cl_mask_range_bad');
        $context->builder->branchIf($valid, $rangeOk, $rangeBad);
        $context->builder->positionAtEnd($rangeOk);
        $ordSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($cI64, $ordSlot);
        $rHead = $fn->appendBasicBlock('cl_mask_range_head');
        $rBody = $fn->appendBasicBlock('cl_mask_range_body');
        $rDone = $fn->appendBasicBlock('cl_mask_range_done');
        $context->builder->branch($rHead);
        $context->builder->positionAtEnd($rHead);
        $ord = $context->builder->load($ordSlot);
        $past = $context->builder->icmp(Builder::INT_SGT, $ord, $endI64);
        $context->builder->branchIf($past, $rDone, $rBody);
        $context->builder->positionAtEnd($rBody);
        $context->builder->store($i8->constInt(1, false), $context->builder->gep($maskSlot, $ord));
        $context->builder->store($context->builder->addNoSignedWrap($ord, $one), $ordSlot);
        $context->builder->branch($rHead);
        $context->builder->positionAtEnd($rDone);
        $context->builder->store($context->builder->addNoSignedWrap($i, $four), $iSlot);
        $context->builder->branch($afterBlock);
        $context->builder->positionAtEnd($rangeBad);
        $context->builder->store($i8->constInt(1, false), $context->builder->gep($maskSlot, $cI64));
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($afterBlock);

        $context->builder->positionAtEnd($singleBlock);
        $context->builder->store($i8->constInt(1, false), $context->builder->gep($maskSlot, $cI64));
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($afterBlock);
        $context->builder->positionAtEnd($afterBlock);
        $context->builder->branch($mHead);
        $context->builder->positionAtEnd($mDone);
        $context->builder->call($context->lookupFunction('free'), $buf);

        return $maskSlot;
    }

    private static function implementStripcslashes(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('entry');
        $context->builder->positionAtEnd($entry);
        $subject = $fn->getParam(0);
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $backslash = $i8->constInt(92, false);

        $src = $context->builder->call($context->lookupFunction('__string__separate'), $subject);
        $len = $context->builder->load($context->builder->structGep($src, $map['length']));
        $srcChars = $context->builder->structGep($src, $map['value']);

        $dest = $context->builder->call($context->lookupFunction('__string__alloc'), $len);
        $context->builder->store($len, $context->builder->structGep($dest, $map['length']));
        $destChars = $context->builder->structGep($dest, $map['value']);

        $iSlot = $context->builder->alloca($i64, 1);
        $posSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($zero, $iSlot);
        $context->builder->store($zero, $posSlot);

        $head = $fn->appendBasicBlock('stripcslashes_head');
        $body = $fn->appendBasicBlock('stripcslashes_body');
        $done = $fn->appendBasicBlock('stripcslashes_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->gep($srcChars, $i));
        $pos = $context->builder->load($posSlot);
        $destAt = $context->builder->gep($destChars, $pos);
        $hasNext = $context->builder->icmp(Builder::INT_SLT, $context->builder->addNoSignedWrap($i, $one), $len);
        $isSlash = $context->builder->icmp(Builder::INT_EQ, $ch, $backslash);
        $canEsc = $context->builder->and($isSlash, $hasNext);

        $escBlock = $fn->appendBasicBlock('stripcslashes_esc');
        $plainBlock = $fn->appendBasicBlock('stripcslashes_plain');
        $afterBlock = $fn->appendBasicBlock('stripcslashes_after');
        $context->builder->branchIf($canEsc, $escBlock, $plainBlock);

        $context->builder->positionAtEnd($escBlock);
        [$outCh, $advance] = self::decodeEscape($context, $fn, $srcChars, $len, $i, $ch);
        $context->builder->store($outCh, $destAt);
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $advance), $iSlot);
        $context->builder->branch($afterBlock);

        $context->builder->positionAtEnd($plainBlock);
        $context->builder->store($ch, $destAt);
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($afterBlock);

        $context->builder->positionAtEnd($afterBlock);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);
        $outLen = $context->builder->load($posSlot);
        $context->builder->store($outLen, $context->builder->structGep($dest, $map['length']));
        $context->builder->returnValue($dest);
    }

    /** @return array{0: Value, 1: Value} */
    private static function decodeEscape(
        Context $context,
        LlvmFunction $fn,
        Value $srcChars,
        Value $len,
        Value $i,
        Value $fallbackCh
    ): array {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $three = $i64->constInt(3, false);
        $nextCh = $context->builder->load($context->builder->gep($srcChars, $context->builder->addNoSignedWrap($i, $one)));
        $nextI64 = $context->builder->zExt($nextCh, $i64);

        $doneBlock = $fn->appendBasicBlock('decode_done');
        $context->builder->positionAtEnd($doneBlock);
        $outPhi = $context->builder->phi($i8);
        $advPhi = $context->builder->phi($i64);

        $escapes = [
            110 => 10, 114 => 13, 97 => 7, 116 => 9, 118 => 11,
            98 => 8, 102 => 12, 101 => 27,
        ];
        $nextChain = $context->builder->getInsertBlock();
        foreach ($escapes as $ord => $out) {
            $context->builder->positionAtEnd($nextChain);
            $caseBlock = $fn->appendBasicBlock('decode_case_'.$ord);
            $fallBlock = $fn->appendBasicBlock('decode_fall_'.$ord);
            $match = $context->builder->icmp(Builder::INT_EQ, $nextI64, $i64->constInt($ord, false));
            $context->builder->branchIf($match, $caseBlock, $fallBlock);
            $context->builder->positionAtEnd($caseBlock);
            $context->builder->branch($doneBlock);
            $outPhi->addIncoming($i8->constInt($out, false), $caseBlock);
            $advPhi->addIncoming($two, $caseBlock);
            $nextChain = $fallBlock;
        }

        $context->builder->positionAtEnd($nextChain);
        $isX = $context->builder->icmp(Builder::INT_EQ, $nextI64, $i64->constInt(120, false));
        $xBlock = $fn->appendBasicBlock('decode_x');
        $octBlock = $fn->appendBasicBlock('decode_oct');
        $plainBlock = $fn->appendBasicBlock('decode_plain');
        $context->builder->branchIf($isX, $xBlock, $octBlock);

        $context->builder->positionAtEnd($xBlock);
        $need = $context->builder->add($i, $three);
        $hasHex = $context->builder->icmp(Builder::INT_SLT, $need, $len);
        $hexOk = $fn->appendBasicBlock('decode_hex_ok');
        $hexBad = $fn->appendBasicBlock('decode_hex_bad');
        $context->builder->branchIf($hasHex, $hexOk, $hexBad);
        $context->builder->positionAtEnd($hexOk);
        $h1 = $context->builder->load($context->builder->gep($srcChars, $context->builder->addNoSignedWrap($i, $one)));
        $h2 = $context->builder->load($context->builder->gep($srcChars, $context->builder->addNoSignedWrap($i, $two)));
        $both = $context->builder->and(self::isHex($context, $h1), self::isHex($context, $h2));
        $hexVal = self::hexByte($context, $h1, $h2);
        $hexOut = $context->builder->select($both, $hexVal, $i8->constInt(120, false));
        $hexAdv = $context->builder->select($both, $three, $two);
        $context->builder->branch($doneBlock);
        $outPhi->addIncoming($hexOut, $hexOk);
        $advPhi->addIncoming($hexAdv, $hexOk);
        $context->builder->positionAtEnd($hexBad);
        $context->builder->branch($doneBlock);
        $outPhi->addIncoming($fallbackCh, $hexBad);
        $advPhi->addIncoming($one, $hexBad);

        $context->builder->positionAtEnd($octBlock);
        $zero64 = $i64->constInt(0, false);
        $seven = $i64->constInt(7, false);
        $isOct = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $nextI64, $zero64),
            $context->builder->icmp(Builder::INT_SLE, $nextI64, $seven)
        );
        $octEsc = $fn->appendBasicBlock('decode_oct_esc');
        $octPlain = $fn->appendBasicBlock('decode_oct_plain');
        $context->builder->branchIf($isOct, $octEsc, $octPlain);
        $context->builder->positionAtEnd($octEsc);
        $octVal = self::readOctal($context, $fn, $srcChars, $len, $i, $nextCh);
        $context->builder->branch($doneBlock);
        $outPhi->addIncoming($octVal, $octEsc);
        $advPhi->addIncoming($two, $octEsc);
        $context->builder->positionAtEnd($octPlain);
        $context->builder->branch($doneBlock);
        $outPhi->addIncoming($nextCh, $octPlain);
        $advPhi->addIncoming($two, $octPlain);

        return [$outPhi, $advPhi];
    }

    private static function readOctal(
        Context $context,
        LlvmFunction $fn,
        Value $srcChars,
        Value $len,
        Value $i,
        Value $first
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $one = $i64->constInt(1, false);
        $seven = $i64->constInt(7, false);
        $octSlot = $context->builder->alloca($i64, 1);
        $idxSlot = $context->builder->alloca($i64, 1);
        $digitsSlot = $context->builder->alloca($i64, 1);
        $context->builder->store($context->builder->zExt($first, $i64), $octSlot);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $idxSlot);
        $context->builder->store($one, $digitsSlot);

        $head = $fn->appendBasicBlock('oct_head');
        $body = $fn->appendBasicBlock('oct_body');
        $done = $fn->appendBasicBlock('oct_done');
        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $digits = $context->builder->load($digitsSlot);
        $idx = $context->builder->load($idxSlot);
        $stop = $context->builder->or(
            $context->builder->icmp(Builder::INT_SGE, $digits, $i64->constInt(3, false)),
            $context->builder->icmp(Builder::INT_SGE, $context->builder->addNoSignedWrap($idx, $one), $len)
        );
        $context->builder->branchIf($stop, $done, $body);

        $context->builder->positionAtEnd($body);
        $next = $context->builder->load($context->builder->gep($srcChars, $context->builder->addNoSignedWrap($idx, $one)));
        $ord = $context->builder->zExt($next, $i64);
        $isOct = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $ord, $i64->constInt(0, false)),
            $context->builder->icmp(Builder::INT_SLE, $ord, $seven)
        );
        $takeBlock = $fn->appendBasicBlock('oct_take');
        $skipBlock = $fn->appendBasicBlock('oct_skip');
        $context->builder->branchIf($isOct, $takeBlock, $skipBlock);
        $context->builder->positionAtEnd($takeBlock);
        $oct = $context->builder->load($octSlot);
        $oct = $context->builder->add($context->builder->mul($oct, $i64->constInt(8, false)), $ord);
        $context->builder->store($oct, $octSlot);
        $context->builder->store($context->builder->addNoSignedWrap($idx, $one), $idxSlot);
        $context->builder->store($context->builder->addNoSignedWrap($digits, $one), $digitsSlot);
        $context->builder->branch($head);
        $context->builder->positionAtEnd($skipBlock);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $context->builder->trunc($context->builder->load($octSlot), $i8);
    }

    private static function isHex(Context $context, Value $ch): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $ord = $context->builder->zExt($ch, $i64);

        return $context->builder->or(
            $context->builder->and(
                $context->builder->icmp(Builder::INT_SGE, $ord, $i64->constInt(48, false)),
                $context->builder->icmp(Builder::INT_SLE, $ord, $i64->constInt(57, false))
            ),
            $context->builder->or(
                $context->builder->and(
                    $context->builder->icmp(Builder::INT_SGE, $ord, $i64->constInt(97, false)),
                    $context->builder->icmp(Builder::INT_SLE, $ord, $i64->constInt(102, false))
                ),
                $context->builder->and(
                    $context->builder->icmp(Builder::INT_SGE, $ord, $i64->constInt(65, false)),
                    $context->builder->icmp(Builder::INT_SLE, $ord, $i64->constInt(70, false))
                )
            )
        );
    }

    private static function hexByte(Context $context, Value $h1, Value $h2): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $n1 = self::hexNibble($context, $h1);
        $n2 = self::hexNibble($context, $h2);

        return $context->builder->trunc(
            $context->builder->or(
                $context->builder->shl($n1, $i64->constInt(4, false)),
                $n2
            ),
            $i8
        );
    }

    private static function hexNibble(Context $context, Value $ch): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $ord = $context->builder->zExt($ch, $i64);
        $isDigit = $context->builder->icmp(Builder::INT_SLE, $ord, $i64->constInt(57, false));
        $digit = $context->builder->sub($ord, $i64->constInt(48, false));
        $lower = $context->builder->sub($ord, $i64->constInt(87, false));
        $upper = $context->builder->sub($ord, $i64->constInt(55, false));
        $maybeLower = $context->builder->select($isDigit, $digit, $lower);
        $isUpper = $context->builder->icmp(Builder::INT_SLE, $ord, $i64->constInt(70, false));

        return $context->builder->select($isUpper, $maybeLower, $upper);
    }
}
