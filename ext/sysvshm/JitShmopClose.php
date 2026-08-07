<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sysvshm;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for shmop_close() (#27408). php-src close is a NOP. */
final class JitShmopClose
{
    public static function invoke(Context $context, JITVariable $arg): Value
    {
        $handle = JitShmopHandle::fromArg($context, $arg, 'shmop_close');
        JitShmopHandle::ensureLinked($context);
        $context->builder->call(
            $context->lookupFunction('__compiler_shmop_close'),
            $handle
        );

        return JitShmopHandle::nullResult($context);
    }

    public static function emitArgumentCountError(Context $context, int $argc): Value
    {
        return JitShmopHandle::emitArgumentCountError(
            $context,
            'shmop_close() expects exactly 1 argument, '.$argc.' given'
        );
    }
}
