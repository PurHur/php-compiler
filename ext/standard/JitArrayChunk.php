<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM JIT helper for array_chunk() length validation (issue #4090). */
final class JitArrayChunk
{
    private const LENGTH_ERROR = 'array_chunk(): Argument #2 ($length) must be greater than 0';

    /**
     * Runtime guard for non-constant size (php-src ext/standard/array.c php_array_chunk).
     */
    public static function emitRuntimeLengthGuard(Context $context, Value $chunkLen): void
    {
        $i64 = $context->getTypeFromString('int64');
        $one = $i64->constInt(1, false);
        $invalid = $context->builder->icmp(Builder::INT_SLT, $chunkLen, $one);
        $okBlock = BasicBlockHelper::append($context, 'arraychunk_len_ok');
        $errBlock = BasicBlockHelper::append($context, 'arraychunk_len_err');
        $context->builder->branchIf($invalid, $errBlock, $okBlock);
        $context->builder->positionAtEnd($errBlock);
        TypeErrorRaise::registerDeclarations($context);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise($context, self::LENGTH_ERROR);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($okBlock);
    }
}
