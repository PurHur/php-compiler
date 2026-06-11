<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Func;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * Invoke VM callables from stdlib builtins (preg_replace_callback, etc.; issue #4442).
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

    public static function invokeOne(Context $context, Variable $callback, Variable $arg): Variable
    {
        $callback = $callback->resolveIndirect();
        $copy = new Variable();
        $copy->copyFrom($arg);

        if (VmClosureCall::isClosure($callback)) {
            return VmClosureCall::invokeOne($context, VmClosureCall::resolve($callback), $copy);
        }
        if (Variable::TYPE_STRING === $callback->type) {
            $name = $callback->toString();
            if (str_contains($name, '::')) {
                [$class, $method] = explode('::', $name, 2);
                if ('' === $class || '' === $method) {
                    throw new \TypeError(
                        'preg_replace_callback(): Argument #2 ($callback) must be a valid callback'
                    );
                }

                return $context->runtime->vm->invokeStaticWithCalledScope($class, $method, $copy);
            }
            $fn = VmUserCall::resolveStringCallback($context, $name);

            return VmUserCall::invokeOne($context, $fn, $copy);
        }
        if (Variable::TYPE_ARRAY === $callback->type) {
            return self::invokeArrayCallable($context, $callback, $copy);
        }
        if (Variable::TYPE_OBJECT === $callback->type) {
            return $context->runtime->vm->invokeInstanceMethod(
                $callback->toObject(),
                '__invoke',
                $copy
            );
        }

        throw new \TypeError(
            'preg_replace_callback(): Argument #2 ($callback) must be a valid callback'
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

    private static function invokeArrayCallable(Context $context, Variable $callback, Variable $arg): Variable
    {
        $table = $callback->toArray();
        $idx0 = new Variable(Variable::TYPE_INTEGER);
        $idx0->int(0);
        $idx1 = new Variable(Variable::TYPE_INTEGER);
        $idx1->int(1);
        if (!$table->keyExists($idx0) || !$table->keyExists($idx1)) {
            throw new \TypeError(
                'preg_replace_callback(): Argument #2 ($callback) must be a valid callback'
            );
        }
        $target = $table->findVariable($idx0, false)->resolveIndirect();
        $methodName = $table->findVariable($idx1, false)->resolveIndirect()->toString();
        if (Variable::TYPE_OBJECT === $target->type) {
            return $context->runtime->vm->invokeInstanceMethod(
                $target->toObject(),
                $methodName,
                $arg
            );
        }
        if (Variable::TYPE_STRING === $target->type) {
            $class = $target->toString();
            if ('' === $class) {
                throw new \TypeError(
                    'preg_replace_callback(): Argument #2 ($callback) must be a valid callback'
                );
            }

            return $context->runtime->vm->invokeStaticWithCalledScope($class, $methodName, $arg);
        }

        throw new \TypeError(
            'preg_replace_callback(): Argument #2 ($callback) must be a valid callback'
        );
    }
}
