<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\ArrayPushRuntime;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** Minimal repro for #8560: same $values positional then spread in one function. */
final class array_push_spread_repro extends Internal
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        $array = $args[0];
        $values = \array_slice($args, 1);
        JitArrayPush::pushWithValueBoxGuard(
            $context,
            $array,
            $values,
            function (Context $context, JITVariable $array, array $values): Value {
                return ArrayPushRuntime::push($context, $array, ...$values);
            }
        );

        return ArrayPushRuntime::push($context, $array, ...$values);
    }
}
