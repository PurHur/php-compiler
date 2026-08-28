<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\JIT\Builtin\SessionStartOptionsRuntime;

/** JIT lowering for session_start($options) (#18457 / #33945). */
final class JitSessionStartOptions
{
    public static function invoke(Context $context, JITVariable $options): \PHPLLVM\Value
    {
        if ($options->compileTimeEmptyArrayLiteral) {
            return JitSessionStart::invoke($context);
        }

        SessionStartOptionsRuntime::applyOptionsAtCallSite($context, $options);

        return JitSessionStart::invoke($context);
    }
}
