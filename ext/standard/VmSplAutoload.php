<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Func\PHP as PhpFunc;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * spl_autoload_register() stack and callback invocation (issue #1369).
 */
final class VmSplAutoloadRunner
{
    public function __construct(
        private PhpFunc $func,
        private ?ObjectEntry $receiver = null
    ) {
    }

    public function __invoke(Context $ctx, string $className): void
    {
        VmSplAutoload::dispatch($ctx, $this->func, $this->receiver, $className);
    }
}

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

    private static function bindCallback(Context $ctx, Variable $callback): VmSplAutoloadRunner
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

    private static function bindFunction(Context $ctx, string $name): VmSplAutoloadRunner
    {
        return new VmSplAutoloadRunner(VmUserCall::resolveStringCallback($ctx, $name));
    }

    private static function bindStaticName(Context $ctx, string $callable): VmSplAutoloadRunner
    {
        [$className, $methodName] = explode('::', $callable, 2);

        return new VmSplAutoloadRunner(self::resolveStaticMethod($ctx, $className, $methodName));
    }

    private static function bindArrayCallable(Context $ctx, Variable $callable): VmSplAutoloadRunner
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
            return new VmSplAutoloadRunner(self::resolveStaticMethod($ctx, $target->toString(), $method));
        }
        if (Variable::TYPE_OBJECT === $target->type) {
            $class = $target->toObject()->class;
            $methodLc = strtolower($method);
            if (!isset($class->methods[$methodLc])) {
                throw new \LogicException("spl_autoload_register() undefined method {$class->name}::{$method}()");
            }
            $func = $class->methods[$methodLc];

            return new VmSplAutoloadRunner($func, $target->toObject());
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

    public static function dispatch(
        Context $ctx,
        PhpFunc $func,
        ?ObjectEntry $receiver,
        string $className
    ): void {
        if (null !== $receiver) {
            $recv = new Variable();
            $recv->object($receiver);
            $arg = new Variable();
            $arg->string($className);
            $ctx->runtime->vm->invokePhpFunction($func, $recv, $arg);

            return;
        }
        $arg = new Variable();
        $arg->string($className);
        $ctx->runtime->vm->invokePhpFunction($func, $arg);
    }
}
