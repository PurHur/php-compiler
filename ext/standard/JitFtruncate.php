<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for ftruncate() via __compiler_ftruncate (issue #3256). */
final class JitFtruncate
{
    private const SIZE_ERROR = 'ftruncate(): Argument #2 ($size) must be greater than or equal to 0';

    /** @return Value */
    public static function invoke(Context $context, Value $handleLong, Value $sizeLong): Value
    {
        self::emitRuntimeSizeGuard($context, $sizeLong);
        $ret = $context->builder->call(
            $context->lookupFunction('__compiler_ftruncate'),
            $handleLong,
            $sizeLong
        );
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->icmp(Builder::INT_EQ, $ret, $i32->constInt(1, false));
    }

    private static function emitRuntimeSizeGuard(Context $context, Value $size): void
    {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $invalid = $context->builder->icmp(Builder::INT_SLT, $size, $zero);
        $okBlock = BasicBlockHelper::append($context, 'ftruncate_size_ok');
        $errBlock = BasicBlockHelper::append($context, 'ftruncate_size_err');
        $context->builder->branchIf($invalid, $errBlock, $okBlock);
        $context->builder->positionAtEnd($errBlock);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitValueError($context, self::SIZE_ERROR);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBlock);
    }
}
