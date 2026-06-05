<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT lowering for soundex() — mirrors ext/standard/VmString::soundex().
 *
 * php-src: ext/standard/string.c — PHP_FUNCTION(soundex)
 */
final class JitSoundex
{
    /** @var list<int> PHP 8 soundex_table[26] — 0 = vowel/H/W, else ASCII digit */
    private const TABLE = [
        0, 49, 50, 51, 0, 49, 50, 0, 0, 50, 50, 52, 53, 53, 0, 49, 50, 54, 50, 51, 0, 49, 0, 50, 0, 50,
    ];

    private static int $blockSerial = 0;

    public static function invoke(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i1 = $context->getTypeFromString('int1');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        $four = $i64->constInt(4, false);
        $ordA = $i64->constInt(65, false);
        $ordZ = $i64->constInt(90, false);
        $ord_a = $i64->constInt(97, false);
        $ord_z = $i64->constInt(122, false);
        $digitZero = $i8->constInt(0, false);

        $src = $context->builder->call($context->lookupFunction('__string__separate'), $str);
        $len = $context->builder->load($context->builder->structGep($src, $map['length']));
        $data = $context->builder->structGep($src, $map['value']);

        $code = $context->builder->alloca($i8->arrayType(5), 1, 'soundex_code');
        $codePtr = $context->builder->pointerCast($code, $i8p);
        foreach ([0, 1, 2, 3] as $idx) {
            $context->builder->store(
                $i8->constInt(48, false),
                $context->builder->inBoundsGEP($codePtr, $i64->constInt($idx, false))
            );
        }
        $context->builder->store($digitZero, $context->builder->inBoundsGEP($codePtr, $four));

        $posSlot = $context->builder->alloca($i64, 1, 'soundex_pos');
        $startedSlot = $context->builder->alloca($i1, 1, 'soundex_started');
        $lastSlot = $context->builder->alloca($i8, 1, 'soundex_last');
        $iSlot = $context->builder->alloca($i64, 1, 'soundex_i');
        $context->builder->store($zero, $posSlot);
        $context->builder->store($i1->constInt(0, false), $startedSlot);
        $context->builder->store($digitZero, $lastSlot);
        $context->builder->store($zero, $iSlot);

        $id = (string) (++self::$blockSerial);
        $head = BasicBlockHelper::append($context, 'soundex_head_'.$id);
        $body = BasicBlockHelper::append($context, 'soundex_body_'.$id);
        $skip = BasicBlockHelper::append($context, 'soundex_skip_'.$id);
        $first = BasicBlockHelper::append($context, 'soundex_first_'.$id);
        $next = BasicBlockHelper::append($context, 'soundex_next_'.$id);
        $same = BasicBlockHelper::append($context, 'soundex_same_'.$id);
        $diff = BasicBlockHelper::append($context, 'soundex_diff_'.$id);
        $write = BasicBlockHelper::append($context, 'soundex_write_'.$id);
        $setLast = BasicBlockHelper::append($context, 'soundex_set_last_'.$id);
        $loopEnd = BasicBlockHelper::append($context, 'soundex_loop_end_'.$id);
        $done = BasicBlockHelper::append($context, 'soundex_done_'.$id);

        $context->builder->branch($head);

        $context->builder->positionAtEnd($head);
        $i = $context->builder->load($iSlot);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $i, $len);
        $context->builder->branchIf($atEnd, $done, $body);

        $context->builder->positionAtEnd($body);
        $ch = $context->builder->load($context->builder->inBoundsGEP($data, $i));
        $chI64 = $context->builder->zExt($ch, $i64);
        $isLower = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $chI64, $ord_a),
            $context->builder->icmp(Builder::INT_SLE, $chI64, $ord_z)
        );
        $upperI64 = $context->builder->select(
            $isLower,
            $context->builder->subNoSignedWrap($chI64, $i64->constInt(32, false)),
            $chI64
        );
        $upper = $context->builder->trunc($upperI64, $i8);
        $isAlpha = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $upperI64, $ordA),
            $context->builder->icmp(Builder::INT_SLE, $upperI64, $ordZ)
        );
        $context->builder->branchIf($isAlpha, $first, $skip);

        $context->builder->positionAtEnd($skip);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($first);
        $started = $context->builder->load($startedSlot);
        $notStarted = $context->builder->not($started);
        $context->builder->branchIf($notStarted, $next, $diff);

        $context->builder->positionAtEnd($next);
        $idx = $context->builder->subNoSignedWrap($upperI64, $ordA);
        $digit = self::tableLookup($context, $idx, $i8);
        $context->builder->store($upper, $context->builder->inBoundsGEP($codePtr, $zero));
        $context->builder->store($one, $posSlot);
        $context->builder->store($digit, $lastSlot);
        $context->builder->store($i1->constInt(1, false), $startedSlot);
        $context->builder->branch($loopEnd);

        $context->builder->positionAtEnd($diff);
        $idx = $context->builder->subNoSignedWrap($upperI64, $ordA);
        $digit = self::tableLookup($context, $idx, $i8);
        $last = $context->builder->load($lastSlot);
        $digitDiff = $context->builder->icmp(Builder::INT_NE, $digit, $last);
        $context->builder->branchIf($digitDiff, $write, $same);

        $context->builder->positionAtEnd($same);
        $context->builder->branch($loopEnd);

        $context->builder->positionAtEnd($write);
        $pos = $context->builder->load($posSlot);
        $digitNonZero = $context->builder->icmp(Builder::INT_NE, $digit, $digitZero);
        $posLt4 = $context->builder->icmp(Builder::INT_SLT, $pos, $four);
        $canWrite = $context->builder->and($digitNonZero, $posLt4);
        $doWrite = BasicBlockHelper::append($context, 'soundex_do_write_'.$id);
        $context->builder->branchIf($canWrite, $doWrite, $setLast);

        $context->builder->positionAtEnd($doWrite);
        $context->builder->store($digit, $context->builder->inBoundsGEP($codePtr, $pos));
        $context->builder->store($context->builder->addNoSignedWrap($pos, $one), $posSlot);
        $context->builder->branch($setLast);

        $context->builder->positionAtEnd($setLast);
        $context->builder->store($digit, $lastSlot);
        $context->builder->branch($loopEnd);

        $context->builder->positionAtEnd($loopEnd);
        $context->builder->store($context->builder->addNoSignedWrap($i, $one), $iSlot);
        $context->builder->branch($head);

        $context->builder->positionAtEnd($done);

        return $context->builder->call(
            $context->lookupFunction('__string__init'),
            $four,
            $codePtr
        );
    }

    private static function tableLookup(Context $context, Value $index, $i8): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i8->constInt(0, false);
        $value = $zero;
        foreach (self::TABLE as $i => $ord) {
            $value = $context->builder->select(
                $context->builder->icmp(Builder::INT_EQ, $index, $i64->constInt($i, false)),
                $i8->constInt($ord, false),
                $value
            );
        }

        return $value;
    }
}
