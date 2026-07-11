<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\JIT\UsortCallbackPolicy;
use PHPCompiler\VM\Variable;

/** usort/uasort/uksort and array_u* null callback → TypeError (ext/standard/array.c; #10624, #10799, #10785). */
final class VmArraySortCallback
{
    /**
     * Reject undefined string callbacks before strcmp/strcasecmp fast-path deferral (#13273).
     */
    public static function rejectInvalidStringCallback(
        Frame $frame,
        Variable $callback,
        string $function,
        int $argNum = 2
    ): void {
        $callback = $callback->resolveIndirect();
        if (Variable::TYPE_STRING !== $callback->type) {
            return;
        }
        $name = $callback->toString();
        if (UsortCallbackPolicy::isVmSupportedName($name)) {
            return;
        }
        if (null === $frame->vmContext) {
            throw new \LogicException($function.'() requires VM context in this compiler build');
        }
        if (!VmCallable::isCallable($frame->vmContext, $callback)) {
            throw new \TypeError(self::invalidStringCallbackTypeError($function, $argNum, $name));
        }
    }

    public static function invalidStringCallbackTypeError(string $function, int $argNum, string $name): string
    {
        return \sprintf(
            '%s(): Argument #%d ($callback) must be a valid callback, function "%s" not found or invalid function name',
            $function,
            $argNum,
            $name
        );
    }

    public static function requireCallback(
        Variable $callback,
        string $function,
        int $argNum = 2,
        ?string $paramName = 'callback'
    ): void {
        if (Variable::TYPE_NULL === $callback->resolveIndirect()->type) {
            $paramPart = null !== $paramName ? ' ($'.$paramName.')' : '';
            throw new \TypeError(
                $function.'(): Argument #'.$argNum.$paramPart.' must be a valid callback, no array or string given'
            );
        }
    }

    /**
     * array_diff_uassoc()/array_intersect_uassoc() missing comparator (#10785, php-src array.c).
     */
    public static function requireUassocCallback(Variable $callback, string $function, int $argNum): void
    {
        $callback = $callback->resolveIndirect();
        if (Variable::TYPE_NULL === $callback->type) {
            throw new \TypeError(self::uassocInvalidCallbackTypeError($function, $argNum));
        }
        if (Variable::TYPE_ARRAY === $callback->type) {
            self::rejectUassocArrayCallback($callback, $function, $argNum);
        }
        if (Variable::TYPE_STRING === $callback->type || VmClosureCall::isClosure($callback)) {
            return;
        }
        if (Variable::TYPE_OBJECT === $callback->type) {
            return;
        }

        throw new \TypeError(self::uassocInvalidCallbackTypeError($function, $argNum));
    }

    private static function uassocInvalidCallbackTypeError(string $function, int $argNum): string
    {
        return $function.'(): Argument #'.$argNum.' must be a valid callback, no array or string given';
    }

    private static function uassocInvalidArrayCallbackTypeError(string $function, int $argNum): string
    {
        return $function.'(): Argument #'.$argNum.' must be a valid callback, array callback must have exactly two members';
    }

    private static function rejectUassocArrayCallback(Variable $callback, string $function, int $argNum): void
    {
        $table = $callback->toArray();
        $idx0 = new Variable(Variable::TYPE_INTEGER);
        $idx0->int(0);
        $idx1 = new Variable(Variable::TYPE_INTEGER);
        $idx1->int(1);
        if (!$table->keyExists($idx0) || !$table->keyExists($idx1)) {
            throw new \TypeError(self::uassocInvalidArrayCallbackTypeError($function, $argNum));
        }

        throw new \TypeError(self::uassocInvalidCallbackTypeError($function, $argNum));
    }

    /**
     * JIT compile-time guard when array_u*() is called with fewer than three args (#12643).
     */
    public static function requireUassocCallbackJitArg(
        \PHPCompiler\JIT\Variable $callback,
        string $function,
        int $argNum
    ): void {
        if (\PHPCompiler\JIT\Variable::TYPE_NULL === $callback->type || $callback->isNullConstant) {
            throw new \TypeError(self::uassocInvalidCallbackTypeError($function, $argNum));
        }
        if (\PHPCompiler\JIT\Variable::TYPE_HASHTABLE === $callback->type
            || ($callback->type & \PHPCompiler\JIT\Variable::IS_NATIVE_ARRAY)) {
            throw new \TypeError(self::uassocInvalidArrayCallbackTypeError($function, $argNum));
        }
        if (\PHPCompiler\JIT\Variable::TYPE_STRING === $callback->type) {
            return;
        }
        if (\PHPCompiler\JIT\Variable::TYPE_OBJECT === $callback->type) {
            return;
        }
        if (null !== $callback->closureCall) {
            return;
        }

        throw new \TypeError(self::uassocInvalidCallbackTypeError($function, $argNum));
    }
}
