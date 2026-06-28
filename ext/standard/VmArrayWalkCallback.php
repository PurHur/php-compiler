<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\Func\PHP;
use PHPCompiler\JIT\ArrayMapCallbackPolicy;
use PHPCompiler\VM\Variable;

/**
 * array_walk() / array_walk_recursive() callback resolution (#13319, ext/standard/array.c).
 */
final class VmArrayWalkCallback
{
    /**
     * @return array{0: ?Internal, 1: ?PHP}
     */
    public static function resolveString(Frame $frame, string $name): array
    {
        try {
            return [VmInternalCall::resolveStringCallback($name), null];
        } catch (\LogicException) {
            // Not a registered string builtin — try a user-defined function.
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('array_walk() requires VM context in this compiler build');
        }
        try {
            return [null, VmUserCall::resolveStringCallback($frame->vmContext, $name)];
        } catch (\LogicException) {
            throw new \TypeError(VmCallable::invalidStringCallbackTypeError($name));
        }
    }

    public static function invokeWalkCallback(
        Frame $frame,
        ?Internal $internal,
        ?PHP $userFn,
        Variable $value,
        Variable $key,
        ?Variable $userdata
    ): Variable {
        if (null !== $internal) {
            return VmInternalCall::invoke($internal, $value, $key);
        }
        if (null === $frame->vmContext || null === $userFn) {
            throw new \LogicException('array_walk() requires VM context in this compiler build');
        }
        if (null !== $userdata) {
            $userdataCopy = new Variable();
            $userdataCopy->copyFrom($userdata);

            return VmUserCall::invokeDirect($frame->vmContext, $userFn, $value, $key, $userdataCopy);
        }

        return VmUserCall::invokeDirect($frame->vmContext, $userFn, $value, $key);
    }

    public static function callbackFailed(Variable $result): bool
    {
        $result = $result->resolveIndirect();
        if (Variable::TYPE_BOOLEAN === $result->type) {
            return !$result->toBool();
        }

        return false;
    }
}
