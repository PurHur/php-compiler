<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * Invoke VM callables from stdlib builtins (preg_replace_callback, etc.; issue #4442, #25735).
 */
final class VmCallableInvoke
{
    public static function isInvokable(Variable $callback): bool
    {
        $callback = $callback->resolveIndirect();
        if (VmClosureCall::isClosure($callback)) {
            return true;
        }
        if (Variable::TYPE_STRING === $callback->type) {
            return true;
        }
        if (Variable::TYPE_ARRAY === $callback->type) {
            return self::isArrayCallable($callback);
        }
        if (Variable::TYPE_OBJECT === $callback->type) {
            return null === $callback->toObject()->closureState;
        }

        return false;
    }

    /**
     * Validate callback before iteration — Zend FCC check once (#25735).
     */
    public static function requireCallable(
        Context $context,
        Variable $callback,
        string $function = 'preg_replace_callback',
        int $argNum = 2,
        ?Frame $scopeFrame = null
    ): void {
        $callback = $callback->resolveIndirect();
        if (!self::isInvokable($callback)) {
            throw new \TypeError(
                sprintf(
                    '%s(): Argument #%d ($callback) must be a valid callback, no array or string given',
                    $function,
                    $argNum
                )
            );
        }
        if (Variable::TYPE_ARRAY !== $callback->type) {
            return;
        }
        if (VmCallable::isCallable($context, $callback, false, null, $scopeFrame)) {
            return;
        }
        VmCallable::throwIfInaccessibleMethodCallback(
            $context,
            $callback,
            $function,
            $argNum,
            $scopeFrame,
            false
        );
        throw new \TypeError(
            sprintf(
                '%s(): Argument #%d ($callback) must be a valid callback, no array or string given',
                $function,
                $argNum
            )
        );
    }

    public static function invokeOne(
        Context $context,
        Variable $callback,
        Variable $arg,
        string $function = 'preg_replace_callback',
        ?Frame $scopeFrame = null
    ): Variable {
        $callback = $callback->resolveIndirect();
        $copy = new Variable();
        $copy->copyFrom($arg);

        if (VmClosureCall::isClosure($callback)) {
            return VmClosureCall::invokeOne($context, VmClosureCall::resolve($callback), $copy);
        }
        if (Variable::TYPE_STRING === $callback->type || Variable::TYPE_ARRAY === $callback->type
            || Variable::TYPE_OBJECT === $callback->type) {
            return VmCallable::invokeAsWithScope($function, $context, $scopeFrame, $callback, $copy);
        }

        throw new \TypeError(
            sprintf(
                '%s(): Argument #2 ($callback) must be a valid callback',
                $function
            )
        );
    }

    private static function isArrayCallable(Variable $callback): bool
    {
        $table = $callback->toArray();
        $idx0 = new Variable(Variable::TYPE_INTEGER);
        $idx0->int(0);
        $idx1 = new Variable(Variable::TYPE_INTEGER);
        $idx1->int(1);
        if (!$table->keyExists($idx0) || !$table->keyExists($idx1)) {
            return false;
        }
        $target = $table->findVariable($idx0, false)->resolveIndirect();
        $method = $table->findVariable($idx1, false)->resolveIndirect();
        if (Variable::TYPE_STRING !== $method->type || '' === $method->toString()) {
            return false;
        }

        return Variable::TYPE_OBJECT === $target->type || Variable::TYPE_STRING === $target->type;
    }
}
