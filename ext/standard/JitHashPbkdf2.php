<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering guards for hash_pbkdf2() iterations/length (ext/hash/hash_pbkdf2.c; #12230). */
final class JitHashPbkdf2
{
    private const ITERATIONS_ERROR = 'hash_pbkdf2(): Argument #4 ($iterations) must be greater than 0';

    private const LENGTH_ERROR = 'hash_pbkdf2(): Argument #5 ($length) must be greater than or equal to 0';

    public static function emitRuntimeIterationsGuard(Context $context, Value $iterations): void
    {
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $invalid = $context->builder->icmp(Builder::INT_SLT, $iterations, $one);
        $okBlock = BasicBlockHelper::append($context, 'hash_pbkdf2_iter_ok');
        $errBlock = BasicBlockHelper::append($context, 'hash_pbkdf2_iter_err');
        $context->builder->branchIf($invalid, $errBlock, $okBlock);
        $context->builder->positionAtEnd($errBlock);
        ExceptionBridge::emitValueErrorAndAbort($context, self::ITERATIONS_ERROR);
        $context->builder->positionAtEnd($okBlock);
    }

    public static function emitRuntimeLengthGuard(Context $context, Value $length): void
    {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $invalid = $context->builder->icmp(Builder::INT_SLT, $length, $zero);
        $okBlock = BasicBlockHelper::append($context, 'hash_pbkdf2_len_ok');
        $errBlock = BasicBlockHelper::append($context, 'hash_pbkdf2_len_err');
        $context->builder->branchIf($invalid, $errBlock, $okBlock);
        $context->builder->positionAtEnd($errBlock);
        ExceptionBridge::emitValueErrorAndAbort($context, self::LENGTH_ERROR);
        $context->builder->positionAtEnd($okBlock);
    }
}
