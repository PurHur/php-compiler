<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringRandomBytes;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM JIT helpers for random_int() (issue #2330). */
final class JitRandomInt
{
    public static function call(Context $context, Value $min, Value $max): Value
    {
        StringRandomBytes::implement($context);

        $b = $context->builder;
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $ceiling = $b->add($b->sub($max, $min), $one);

        $randStr = $b->call(
            $context->lookupFunction('__compiler_random_bytes'),
            $i64->constInt(8, false)
        );
        $randMap = $context->structFieldMap['__string__'];
        $randPtr = $b->structGep($randStr, $randMap['value']);
        $accSlot = $b->alloca($i64, 1, 'rand_int_acc');
        $b->store($i64->constInt(0, false), $accSlot);
        $byteSlot = $b->alloca($i64, 1, 'rand_int_byte');
        $b->store($i64->constInt(0, false), $byteSlot);

        $byteHead = BasicBlockHelper::append($context, 'rand_int_byte_head');
        $byteBody = BasicBlockHelper::append($context, 'rand_int_byte_body');
        $byteDone = BasicBlockHelper::append($context, 'rand_int_byte_done');
        $b->branch($byteHead);

        $b->positionAtEnd($byteHead);
        $bi = $b->load($byteSlot);
        $byteStop = $b->icmp(Builder::INT_SGE, $bi, $i64->constInt(8, false));
        $b->branchIf($byteStop, $byteDone, $byteBody);

        $b->positionAtEnd($byteBody);
        $acc = $b->load($accSlot);
        $byte = $b->zext($b->load($b->gep($randPtr, $bi)), $i64);
        $b->store($b->or($b->shl($acc, $i64->constInt(8, false)), $byte), $accSlot);
        $b->store($b->addNoSignedWrap($bi, $one), $byteSlot);
        $b->branch($byteHead);

        $b->positionAtEnd($byteDone);
        $accVal = $b->load($accSlot);
        $pick = $b->unsigendRem($accVal, $ceiling);

        return $b->add($min, $pick);
    }
}
