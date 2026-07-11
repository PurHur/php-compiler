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

    public static function invokeTwo(Context $context, Func\PHP $func, Variable $a, Variable $b): Variable
    {
        $argA = new Variable();
        $argA->copyFrom($a);
        $argB = new Variable();
        $argB->copyFrom($b);

        return $context->runtime->vm->invokePhpFunction($func, $argA, $argB);
    }

    public static function invokeArgs(Context $context, Func\PHP $func, Variable ...$args): Variable
    {
        $copies = [];
        foreach ($args as $arg) {
            if ($arg->isIndirect()) {
                $copies[] = $arg;

                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($arg);
            $copies[] = $copy;
        }

        return $context->runtime->vm->invokePhpFunction($func, ...$copies);
    }

    /**
     * Invoke without copying arguments (array_walk &$value; issue #13319).
     */
    public static function invokeDirect(Context $context, Func\PHP $func, Variable ...$args): Variable
    {
        return $context->runtime->vm->invokePhpFunction($func, ...$args);
    }
}
