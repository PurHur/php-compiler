<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StreamReadRuntime;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for rewind() via __compiler_fseek(SEEK_SET) (issue #3579). */
final class JitRewind
{
    /** @return Value
     * true when rewind succeeds */
    public static function invoke(Context $context, Value $handleLong): Value
    {
        StreamReadRuntime::ensureLinked($context);
        $i64 = $context->getTypeFromString('int64');
        $result = $context->builder->call(
            $context->lookupFunction('__compiler_fseek'),
            $handleLong,
            $i64->constInt(0, false),
            $i64->constInt(\SEEK_SET, false)
        );
        return $context->builder->icmp(Builder::INT_EQ, $result, $i64->constInt(0, false));
    }
}
