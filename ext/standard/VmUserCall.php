<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Func;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * Invoke user-defined PHP functions from VM builtins (string callbacks).
 */
final class VmUserCall
{
    public static function resolveStringCallback(Context $context, string $name): Func\PHP
    {
        $lc = strtolower($name);
        if (!isset($context->functions[$lc])) {
            throw new \LogicException(
                "array_reduce() callback '{$name}' is not a defined function in this compiler build"
            );
        }
        $fn = $context->functions[$lc];
        if (!$fn instanceof Func\PHP) {
            throw new \LogicException(
                "array_reduce() callback '{$name}' must be a user-defined function in this compiler build"
            );
        }

        return $fn;
    }

    public static function invoke(Context $context, Func\PHP $func, Variable $carry, Variable $value): Variable
    {
        $carryArg = new Variable();
        $carryArg->copyFrom($carry);
        $valueArg = new Variable();
        $valueArg->copyFrom($value);

        return $context->runtime->vm->invokePhpFunction($func, $carryArg, $valueArg);
    }

    public static function invokeOne(Context $context, Func\PHP $func, Variable $arg): Variable
    {
        $copy = new Variable();
        $copy->copyFrom($arg);

        return $context->runtime->vm->invokePhpFunction($func, $copy);
    }
}
