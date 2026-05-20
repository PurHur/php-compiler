<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM helpers for hash() / hash_hmac() — AOT runtime in lib/AOT/runtime/hash_crypto.c. */
final class JitHash
{
    private static int $blockSerial = 0;

    public static function hash(Context $context, Value $algo, Value $data, Value $raw): Value
    {
        return self::digestToString($context, $context->builder->call(
            $context->lookupFunction('__compiler_hash'),
            $algo,
            $data,
            $raw
        ));
    }

    public static function hashHmac(Context $context, Value $algo, Value $data, Value $key, Value $raw): Value
    {
        return self::digestToString($context, $context->builder->call(
            $context->lookupFunction('__compiler_hash_hmac'),
            $algo,
            $data,
            $key,
            $raw
        ));
    }

    private static function digestToString(Context $context, Value $digest): Value
    {
        $id = (string) (++self::$blockSerial);
        $strPtr = $context->getTypeFromString('__string__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $digest, $strPtr->constNull());

        $failBlock = BasicBlockHelper::append($context, 'hash_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'hash_ok_'.$id);
        $mergeBlock = BasicBlockHelper::append($context, 'hash_merge_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $emptyStr = $context->builder->load($context->constantStringFromString(''));
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($strPtr);
        $phi->addIncoming($emptyStr, $failBlock);
        $phi->addIncoming($digest, $okBlock);

        return $phi;
    }
}
