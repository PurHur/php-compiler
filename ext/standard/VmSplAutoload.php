<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Func\PHP as PhpFunc;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\Variable;

/**
 * spl_autoload_register() stack and callback invocation (issue #1369).
 */
final class VmSplAutoload
{
    public static function register(
        Context $ctx,
        ?Variable $callback,
        bool $prepend
    ): bool {
        if (null === $callback) {
            throw new \LogicException(
                'spl_autoload_register() without a callback is not supported in this compiler build'
            );
        }
        $runner = self::bindCallback($ctx, $callback);
        if ($prepend) {
            array_unshift($ctx->splAutoloadCallbacks, $runner);
        } else {
            $ctx->splAutoloadCallbacks[] = $runner;
        }

        return true;
    }

    public static function runStack(Context $ctx, string $className): bool
    {
        $lc = strtolower($className);
        if (isset($ctx->classes[$lc])) {
            return true;
        }
        foreach ($ctx->splAutoloadCallbacks as $runner) {
            $runner($ctx, $className);
            if (isset($ctx->classes[$lc])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return callable(Context, string): void
     */
    private static function bindCallback(Context $ctx, Variable $callback): callable
    {
        $callback = $callback->resolveIndirect();
        if (Variable::TYPE_STRING === $callback->type) {
            $name = $callback->toString();
            if (str_contains($name, '::')) {
                return self::bindStaticName($ctx, $name);
            }

            return self::bindFunction($ctx, $name);
        }
        if (Variable::TYPE_ARRAY === $callback->type) {
            return self::bindArrayCallable($ctx, $callback);
        }
        throw new \LogicException(
            'spl_autoload_register() callback must be a string or array callable in this compiler build'
        );
    }

    /**
     * @return callable(Context, string): void
     */
    private static function bindFunction(Context $ctx, string $name): callable
    {
        $func = VmUserCall::resolveStringCallback($ctx, $name);

        return static function (Context $ctx, string $className) use ($func): void {
            self::invokeFunction($ctx, $func, $className);
        };
    }

    /**
     * @return callable(Context, string): void
     */
    private static function bindStaticName(Context $ctx, string $callable): callable
    {
        [$className, $methodName] = explode('::', $callable, 2);
        $func = self::resolveStaticMethod($ctx, $className, $methodName);

        return static function (Context $ctx, string $className) use ($func): void {
            self::invokeFunction($ctx, $func, $className);
        };
    }

    /**
     * @return callable(Context, string): void
     */
    private static function bindArrayCallable(Context $ctx, Variable $callable): callable
    {
        $table = $callable->toArray();
        $idx0 = new Variable(Variable::TYPE_INTEGER);
        $idx0->int(0);
        $idx1 = new Variable(Variable::TYPE_INTEGER);
        $idx1->int(1);
        if (!$table->keyExists($idx0) || !$table->keyExists($idx1)) {
            throw new \LogicException('spl_autoload_register() array callback must have two elements');
        }
        $target = $table->findVariable($idx0)->resolveIndirect();
        $methodName = $table->findVariable($idx1)->resolveIndirect();
        if (Variable::TYPE_STRING !== $methodName->type) {
            throw new \LogicException('spl_autoload_register() method name must be a string');
        }
        $method = $methodName->toString();
        if (Variable::TYPE_STRING === $target->type) {
            $func = self::resolveStaticMethod($ctx, $target->toString(), $method);

            return static function (Context $ctx, string $className) use ($func): void {
                self::invokeFunction($ctx, $func, $className);
            };
        }
        if (Variable::TYPE_OBJECT === $target->type) {
            $class = $target->toObject()->class;
            $methodLc = strtolower($method);
            if (!isset($class->methods[$methodLc])) {
                throw new \LogicException("spl_autoload_register() undefined method {$class->name}::{$method}()");
            }
            $func = $class->methods[$methodLc];

            return static function (Context $ctx, string $className) use ($func, $target): void {
                self::invokeInstanceMethod($ctx, $func, $target->toObject(), $className);
            };
        }
        throw new \LogicException(
            'spl_autoload_register() array callback first element must be a class name string or object'
        );
    }

    private static function resolveStaticMethod(Context $ctx, string $className, string $methodName): PhpFunc
    {
        $lcClass = strtolower($className);
        if (!isset($ctx->classes[$lcClass])) {
            $ctx->autoloadClass($className);
        }
        if (!isset($ctx->classes[$lcClass])) {
            throw new \LogicException("spl_autoload_register() unknown class {$className}");
        }
        $methodLc = strtolower($methodName);
        $class = $ctx->classes[$lcClass];
        if (!isset($class->methods[$methodLc])) {
            throw new \LogicException("spl_autoload_register() undefined static method {$className}::{$methodName}()");
        }
        $func = $class->methods[$methodLc];
        if (!$func instanceof PhpFunc) {
            throw new \LogicException("spl_autoload_register() {$className}::{$methodName} must be a user method");
        }

        return $func;
    }

    private static function invokeFunction(Context $ctx, PhpFunc $func, string $className): void
    {
        $arg = new Variable();
        $arg->string($className);
        $ctx->runtime->vm->invokePhpFunction($func, $arg);
    }

    private static function invokeInstanceMethod(
        Context $ctx,
        PhpFunc $func,
        \PHPCompiler\VM\ObjectEntry $receiver,
        string $className
    ): void {
        $recv = new Variable();
        $recv->object($receiver);
        $arg = new Variable();
        $arg->string($className);
        $ctx->runtime->vm->invokePhpFunction($func, $recv, $arg);
    }
}
