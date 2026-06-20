<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Func\PHP as PhpFunc;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * is_callable() / call_user_func* shared dispatch (issue #3132).
 *
 * php-src: ext/standard/basic_functions.c — zend_is_callable / call_user_func*
 */
final class VmCallable
{
    /**
     * @param-out string|null $callableName
     */
    public static function isCallable(
        Context $ctx,
        Variable $var,
        bool $syntaxOnly = false,
        ?Variable $callableNameOut = null
    ): bool {
        $var = $var->resolveIndirect();
        $name = null;
        $ok = self::probeCallable($ctx, $var, $syntaxOnly, $name);
        if (null !== $callableNameOut && null !== $name) {
            self::writeCallableName($callableNameOut, $name);
        }

        return $ok;
    }

    public static function invoke(Context $ctx, Variable $callback, Variable ...$args): Variable
    {
        $callback = $callback->resolveIndirect();
        if (VmClosureCall::isClosure($callback)) {
            return VmClosureCall::invoke($ctx, VmClosureCall::resolve($callback), ...$args);
        }
        if (Variable::TYPE_STRING === $callback->type) {
            return self::invokeStringCallable($ctx, $callback->toString(), ...$args);
        }
        if (Variable::TYPE_ARRAY === $callback->type) {
            return self::invokeArrayCallable($ctx, $callback, ...$args);
        }
        if (Variable::TYPE_OBJECT === $callback->type) {
            $object = $callback->toObject();
            if (null !== $object->closureState) {
                throw new \TypeError(self::invalidCallbackTypeError());
            }

            return $ctx->runtime->vm->invokeInstanceMethod($object, '__invoke', ...$args);
        }

        throw new \TypeError(self::invalidCallbackTypeError());
    }

    /**
     * @param list<Variable> $params
     */
    public static function invokeArrayParams(Context $ctx, Variable $callback, array $params): Variable
    {
        $copies = [];
        foreach ($params as $param) {
            $copy = new Variable();
            $copy->copyFrom($param->resolveIndirect());
            $copies[] = $copy;
        }

        return self::invoke($ctx, $callback, ...$copies);
    }

    public static function invalidCallbackTypeError(): string
    {
        return 'call_user_func(): Argument #1 ($callback) must be a valid callback, no array or string given';
    }

    public static function invalidStringCallbackTypeError(string $name): string
    {
        return sprintf(
            'call_user_func(): Argument #1 ($callback) must be a valid callback, function "%s" not found or invalid function name',
            $name
        );
    }

    /**
     * @param-out string|null $callableName
     */
    private static function probeCallable(
        Context $ctx,
        Variable $var,
        bool $syntaxOnly,
        ?string &$callableName
    ): bool {
        if (VmClosureCall::isClosure($var)) {
            $callableName = '{closure}';

            return true;
        }
        if (Variable::TYPE_STRING === $var->type) {
            return self::probeStringCallable($ctx, $var->toString(), $syntaxOnly, $callableName);
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            return self::probeArrayCallable($ctx, $var, $syntaxOnly, $callableName);
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            $object = $var->toObject();
            if (null !== $object->closureState) {
                $callableName = '{closure}';

                return true;
            }
            $callableName = $object->class->name.'::__invoke';
            if ($ctx->runtime->vm->hasInstanceMethod($object->class, '__invoke')) {
                return true;
            }

            return false;
        }
        if (Variable::TYPE_INTEGER === $var->type) {
            $callableName = (string) $var->toInt();

            return false;
        }
        if (Variable::TYPE_FLOAT === $var->type) {
            $callableName = (string) $var->toFloat();

            return false;
        }
        if (Variable::TYPE_BOOLEAN === $var->type) {
            $callableName = $var->toBool() ? '1' : '';

            return false;
        }
        if (Variable::TYPE_NULL === $var->type) {
            $callableName = '';

            return false;
        }

        return false;
    }

    /**
     * @param-out string|null $callableName
     */
    private static function probeStringCallable(
        Context $ctx,
        string $name,
        bool $syntaxOnly,
        ?string &$callableName
    ): bool {
        if ('' === $name) {
            $callableName = '';

            return false;
        }
        if (str_contains($name, '::')) {
            [$class, $method] = explode('::', $name, 2);
            if ('' === $class || '' === $method || !self::isValidMethodName($method)) {
                return false;
            }
            $callableName = $name;
            if ($syntaxOnly) {
                return true;
            }

            return VmReflection::classMethExists($ctx, $class, $method);
        }
        $callableName = $name;
        if (!self::isValidFunctionName($name)) {
            return false;
        }
        if ($syntaxOnly) {
            return true;
        }

        return VmReflection::functionExists($ctx, $name);
    }

    /**
     * @param-out string|null $callableName
     */
    private static function probeArrayCallable(
        Context $ctx,
        Variable $callback,
        bool $syntaxOnly,
        ?string &$callableName
    ): bool {
        $table = $callback->toArray();
        $idx0 = new Variable(Variable::TYPE_INTEGER);
        $idx0->int(0);
        $idx1 = new Variable(Variable::TYPE_INTEGER);
        $idx1->int(1);
        if (!$table->keyExists($idx0) || !$table->keyExists($idx1)) {
            $callableName = 'Array';

            return false;
        }
        $target = $table->findVariable($idx0, false)->resolveIndirect();
        $methodVar = $table->findVariable($idx1, false)->resolveIndirect();
        if (Variable::TYPE_STRING !== $methodVar->type) {
            return false;
        }
        $method = $methodVar->toString();
        if ('' === $method || !self::isValidMethodName($method)) {
            return false;
        }
        if (Variable::TYPE_OBJECT === $target->type) {
            $className = $target->toObject()->class->name;
            $callableName = $className.'::'.$method;
            if ($syntaxOnly) {
                return true;
            }

            return $ctx->runtime->vm->hasInstanceMethod($target->toObject()->class, $method);
        }
        if (Variable::TYPE_STRING === $target->type) {
            $class = $target->toString();
            if ('' === $class) {
                return false;
            }
            $callableName = $class.'::'.$method;
            if ($syntaxOnly) {
                return true;
            }

            return VmReflection::classMethExists($ctx, $class, $method);
        }

        return false;
    }

    private static function invokeStringCallable(Context $ctx, string $name, Variable ...$args): Variable
    {
        if (str_contains($name, '::')) {
            [$class, $method] = explode('::', $name, 2);
            if ('' === $class || '' === $method) {
                throw new \TypeError(self::invalidCallbackTypeError());
            }

            return $ctx->runtime->vm->invokeStaticWithCalledScope($class, $method, ...$args);
        }
        try {
            $internal = VmInternalCall::resolveStringCallback($name);

            return VmInternalCall::invoke($internal, ...$args);
        } catch (\LogicException) {
            // Not a registered string builtin — try a user-defined function.
        }
        try {
            $fn = VmUserCall::resolveStringCallback($ctx, $name);
        } catch (\LogicException) {
            throw new \TypeError(self::invalidStringCallbackTypeError($name));
        }

        return $ctx->runtime->vm->invokePhpFunction($fn, ...$args);
    }

    private static function invokeArrayCallable(Context $ctx, Variable $callback, Variable ...$args): Variable
    {
        $table = $callback->toArray();
        $idx0 = new Variable(Variable::TYPE_INTEGER);
        $idx0->int(0);
        $idx1 = new Variable(Variable::TYPE_INTEGER);
        $idx1->int(1);
        if (!$table->keyExists($idx0) || !$table->keyExists($idx1)) {
            throw new \TypeError(self::invalidCallbackTypeError());
        }
        $target = $table->findVariable($idx0, false)->resolveIndirect();
        $methodName = $table->findVariable($idx1, false)->resolveIndirect()->toString();
        if ('' === $methodName) {
            throw new \TypeError(self::invalidCallbackTypeError());
        }
        if (Variable::TYPE_OBJECT === $target->type) {
            return $ctx->runtime->vm->invokeInstanceMethod($target->toObject(), $methodName, ...$args);
        }
        if (Variable::TYPE_STRING === $target->type) {
            $class = $target->toString();
            if ('' === $class) {
                throw new \TypeError(self::invalidCallbackTypeError());
            }

            return $ctx->runtime->vm->invokeStaticWithCalledScope($class, $methodName, ...$args);
        }

        throw new \TypeError(self::invalidCallbackTypeError());
    }

    private static function writeCallableName(Variable $ref, string $name): void
    {
        $ref->resolveIndirect()->string($name);
    }

    private static function isValidFunctionName(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $name);
    }

    private static function isValidMethodName(string $name): bool
    {
        return (bool) preg_match('/^[a-zA-Z_\x80-\xff][a-zA-Z0-9_\x80-\xff]*$/', $name);
    }
}
