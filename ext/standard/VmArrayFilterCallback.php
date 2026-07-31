<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\Func\PHP;
use PHPCompiler\JIT\ArrayFilterCallbackPolicy;
use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\Variable;

/**
 * array_filter() callback validation and resolution (#10782, #25711, ext/standard/array.c).
 */
final class VmArrayFilterCallback
{
    /**
     * @return array{0: ?ClosureState, 1: ?Internal, 2: ?PHP, 3: ?Variable}
     *     Index 3 is a general VmCallable callback (object-array / invokable) when set.
     */
    public static function resolve(Frame $frame, Variable $callback): array
    {
        $callback = $callback->resolveIndirect();
        if (Variable::TYPE_NULL === $callback->type) {
            return [null, null, null, null];
        }
        if (VmClosureCall::isClosure($callback)) {
            return [VmClosureCall::resolve($callback), null, null, null];
        }
        if (Variable::TYPE_STRING === $callback->type) {
            [$c, $i, $u] = self::resolveString($frame, $callback->toString());

            return [$c, $i, $u, null];
        }
        if (Variable::TYPE_ARRAY === $callback->type) {
            self::requireArrayCallable($frame, $callback);

            return [null, null, null, $callback];
        }
        if (Variable::TYPE_OBJECT === $callback->type) {
            // Invokable objects — same VmCallable path as array_map (#16228 / #25711).
            return [null, null, null, $callback];
        }

        throw new \TypeError(ArrayFilterCallbackPolicy::invalidCallbackTypeError());
    }

    /**
     * Object-array callables: accept in-scope private/protected; Zend TypeError out of scope (#25711).
     */
    public static function requireArrayCallable(Frame $frame, Variable $callback): void
    {
        $callback = $callback->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $callback->type) {
            return;
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('array_filter() requires VM context in this compiler build');
        }
        $table = $callback->toArray();
        $idx0 = new Variable(Variable::TYPE_INTEGER);
        $idx0->int(0);
        $idx1 = new Variable(Variable::TYPE_INTEGER);
        $idx1->int(1);
        if (!$table->keyExists($idx0) || !$table->keyExists($idx1)) {
            throw new \TypeError(ArrayFilterCallbackPolicy::invalidArrayCallbackTypeError());
        }
        if (VmCallable::isCallable($frame->vmContext, $callback, false, null, $frame)) {
            return;
        }
        VmCallable::throwIfInaccessibleMethodCallback(
            $frame->vmContext,
            $callback,
            'array_filter',
            2,
            $frame,
            true
        );
        throw new \TypeError(ArrayFilterCallbackPolicy::invalidCallbackTypeError());
    }

    /**
     * @return array{0: null, 1: ?Internal, 2: ?PHP}
     */
    private static function resolveString(Frame $frame, string $name): array
    {
        try {
            return [null, VmInternalCall::resolveStringCallback($name), null];
        } catch (\LogicException) {
            // Not a registered string builtin — try a user-defined function.
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('array_filter() requires VM context in this compiler build');
        }
        try {
            return [null, null, VmUserCall::resolveStringCallback($frame->vmContext, $name)];
        } catch (\LogicException) {
            throw new \TypeError(ArrayFilterCallbackPolicy::invalidStringCallbackTypeError($name));
        }
    }
}
