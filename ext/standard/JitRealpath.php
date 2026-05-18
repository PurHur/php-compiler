<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for realpath() via libc realpath(3).
 *
 * Failure returns an empty string; PHP's empty string compares equal to false with ==.
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

final class JitRealpath
{
    public static function resolve(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];
        $pathPtr = $context->builder->structGep($str, $map['value']);
        $charPtr = $context->getTypeFromString('char*');
        $null = $charPtr->constNull();
        $resolved = $context->builder->call(
            $context->lookupFunction('realpath'),
            $pathPtr,
            $null
        );
        $isNull = $context->builder->icmp(Builder::INT_EQ, $resolved, $null);

        $failBlock = BasicBlockHelper::append($context, 'realpath_fail');
        $okBlock = BasicBlockHelper::append($context, 'realpath_ok');
        $mergeBlock = BasicBlockHelper::append($context, 'realpath_merge');
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $emptyStr = $context->builder->load($context->constantStringFromString(''));
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($okBlock);
        $i64 = $context->getTypeFromString('int64');
        $len = $context->builder->call(
            $context->lookupFunction('strlen'),
            $resolved
        );
        $lenI64 = $context->builder->zExt($len, $i64);
        $resultStr = $context->builder->call(
            $context->lookupFunction('__string__init'),
            $lenI64,
            $resolved
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $resolved);
        $context->builder->branch($mergeBlock);

        $context->builder->positionAtEnd($mergeBlock);
        $phi = $context->builder->phi($str->typeOf());
        $phi->addIncoming($emptyStr, $failBlock);
        $phi->addIncoming($resultStr, $okBlock);

        return $phi;
    }
}
