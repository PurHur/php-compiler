<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ObOutputRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM lowering for ob_get_clean() (issue #118, #1056). */
final class JitObGetClean
{
    /** @return Value */
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 0) {
            throw new \ArgumentCountError(
                'ob_get_clean() expects exactly 0 arguments, '.$argc.' given'
            );
        }
        ObOutputRuntime::ensureObStackLinked($context);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__phpc_ob_get_clean'),
            $ptr
        );

        return $ptr;
    }
}
