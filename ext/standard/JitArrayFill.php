<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM JIT helper for array_fill() count validation (php-src ext/standard/array.c php_array_fill). */
final class JitArrayFill
{
    private const COUNT_ERROR = 'array_fill(): Argument #2 ($count) must be greater than or equal to 0';

    /**
     * Runtime guard for non-constant count (php-src ext/standard/array.c php_array_fill).
     */
    public static function emitRuntimeCountGuard(Context $context, Value $count): void
    {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $invalid = $context->builder->icmp(Builder::INT_SLT, $count, $zero);
        $okBlock = BasicBlockHelper::append($context, 'arrayfill_count_ok');
        $errBlock = BasicBlockHelper::append($context, 'arrayfill_count_err');
        $context->builder->branchIf($invalid, $errBlock, $okBlock);
        $context->builder->positionAtEnd($errBlock);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitValueError($context, self::COUNT_ERROR);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBlock);
    }
}
