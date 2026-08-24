<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\StreamLifecycleRuntime;
use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/** LLVM lowering for feof() via __compiler_feof (issue #1188; ensureLinked #34439). */
final class JitFeof
{
    /** @return Value
     * true at end-of-file */
    public static function invoke(Context $context, Value $handleLong): Value
    {
        // Type::initialize no longer eagerly StreamLifecycle::ensureLinked (#34439).
        StreamLifecycleRuntime::ensureLinkedForUserScriptLowering($context);

        $ret = $context->builder->call($context->lookupFunction('__compiler_feof'), $handleLong);
        $i32 = $context->getTypeFromString('int32');

        return $context->builder->icmp(Builder::INT_EQ, $ret, $i32->constInt(1, false));
    }
}
