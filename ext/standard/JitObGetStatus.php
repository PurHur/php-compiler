<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ObStatusRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitBoolArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for ob_get_status() (issue #3647, #5609). */
final class JitObGetStatus
{
    /** @return Value */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 1) {
            throw new \ArgumentCountError(
                'ob_get_status() expects at most 1 argument, '.$argc.' given'
            );
        }
        ObStatusRuntime::ensureLinked($context);

        $full = isset($args[0])
            ? JitBoolArg::lowerCoerceZParamBool($context, $args[0], 'ob_get_status', 'full_status', 1)
            : $context->constantFromBool(false);

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i32 = $context->getTypeFromString('int32');
        $ht = $context->builder->call(
            $context->lookupFunction('__phpc_ob_get_status_ht'),
            $context->builder->zExt($full, $i32)
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $ht
        );

        return $ptr;
    }
}
