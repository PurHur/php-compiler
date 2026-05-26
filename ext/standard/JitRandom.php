<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** CSPRNG uniform index in [0, $upperExclusive) for stdlib builtins (#2321). */
final class JitRandom
{
    private static int $seq = 0;

    public static function indexBelow(Context $context, Value $upperExclusive): Value
    {
        $tag = 'jr'.(string) ++self::$seq;
        $i64 = $context->getTypeFromString('int64');
        $randStr = $context->builder->call(
            $context->lookupFunction('__compiler_random_bytes'),
            $i64->constInt(8, false)
        );
        $randMap = $context->structFieldMap['__string__'];
        $randPtr = $context->builder->structGep($randStr, $randMap['value']);
        $accSlot = $context->builder->alloca($i64, 1, $tag.'_acc');
        $context->builder->store($i64->constInt(0, false), $accSlot);
        $byteSlot = $context->builder->alloca($i64, 1, $tag.'_byte');
        $context->builder->store($i64->constInt(0, false), $byteSlot);

        $byteHead = BasicBlockHelper::append($context, $tag.'_head');
        $byteBody = BasicBlockHelper::append($context, $tag.'_body');
        $byteDone = BasicBlockHelper::append($context, $tag.'_done');
        $context->builder->branch($byteHead);

        $context->builder->positionAtEnd($byteHead);
        $bi = $context->builder->load($byteSlot);
        $byteStop = $context->builder->icmp(
            Builder::INT_SGE,
            $bi,
            $i64->constInt(8, false)
        );
        $context->builder->branchIf($byteStop, $byteDone, $byteBody);

        $context->builder->positionAtEnd($byteBody);
        $acc = $context->builder->load($accSlot);
        $byte = $context->builder->zext(
            $context->builder->load($context->builder->gep($randPtr, $bi)),
            $i64
        );
        $shifted = $context->builder->shl($acc, $i64->constInt(8, false));
        $context->builder->store($context->builder->or($shifted, $byte), $accSlot);
        $context->builder->store(
            $context->builder->addNoSignedWrap($bi, $i64->constInt(1, false)),
            $byteSlot
        );
        $context->builder->branch($byteHead);

        $context->builder->positionAtEnd($byteDone);
        $accVal = $context->builder->load($accSlot);
        $upperI64 = $context->builder->zext($upperExclusive, $i64);
        $rem = $context->builder->unsigendRem($accVal, $upperI64);
        $sizeT = $context->getTypeFromString('size_t');

        return $context->builder->trunc($rem, $sizeT);
    }
}
