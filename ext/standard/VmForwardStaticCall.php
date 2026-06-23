<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\PHP as PhpFunc;
use PHPCompiler\VM\Variable;

/**
 * forward_static_call() / forward_static_call_array() (issue #3197).
 *
 * php-src: ext/standard/basic_functions.c — PHP_FUNCTION(forward_static_call*)
 */
final class VmForwardStaticCall
{
    public static function invoke(Frame $frame, string $builtinName, Variable $callable, Variable ...$extraArgs): Variable
    {
        if (null === $frame->vmContext) {
            throw new \LogicException(
                "{$builtinName}() requires VM context in this compiler build"
            );
        }
        $calledScope = self::calledScopeClass($frame, $builtinName, $callable);
        $methodName = self::parseMethodName($callable, $builtinName);
        $vm = $frame->vmContext->runtime->vm;

        return $vm->invokeStaticWithCalledScope($calledScope, $methodName, ...$extraArgs);
    }

    /**
     * @return list<Variable>
     */
    public static function unpackParamsArray(Variable $params, string $builtinName): array
    {
        $params = $params->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $params->type) {
            throw new \LogicException(
                "{$builtinName}() argument #2 ($parameters) must be of type array"
            );
        }
        $ht = $params->toArray();
        $args = [];
        foreach ($ht->iterate(true) as $value) {
            $copy = new Variable();
            $copy->copyFrom($value);
            $args[] = $copy;
        }

        return $args;
    }

    public static function calledScopeClass(Frame $frame, string $builtinName, Variable $callable): string
    {
        try {
            return VmReflection::getCalledClass($frame);
        } catch (\Error) {
            // php-src: forward_static_call() rejects global scope; forward_static_call_array() does not.
            if ('forward_static_call' === $builtinName) {
                throw new \Error("Cannot call {$builtinName}() when no class scope is active");
            }
            $explicitClass = self::parseExplicitClassFromCallable($callable, $builtinName);
            if (null !== $explicitClass) {
                return $explicitClass;
            }

            throw new \Error("Cannot call {$builtinName}() when no class scope is active");
        }
    }

    /**
     * Explicit class from [ClassName::class, 'method'] at global scope (#10664).
     */
    public static function parseExplicitClassFromCallable(Variable $callable, string $builtinName): ?string
    {
        $callable = $callable->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $callable->type) {
            return null;
        }
        $table = $callable->toArray();
        $idx0 = new Variable(Variable::TYPE_INTEGER);
        $idx0->int(0);
        if (!$table->keyExists($idx0)) {
            return null;
        }
        $target = $table->findVariable($idx0, false)->resolveIndirect();
        if (Variable::TYPE_STRING !== $target->type) {
            return null;
        }
        $class = $target->toString();
        if ('' === $class) {
            return null;
        }

        return $class;
    }

    public static function parseMethodName(Variable $callable, string $builtinName): string
    {
        $callable = $callable->resolveIndirect();
        if (Variable::TYPE_STRING === $callable->type) {
            $name = $callable->toString();
            if (!str_contains($name, '::')) {
                throw new \LogicException(
                    "{$builtinName}() string callable must be Class::method"
                );
            }
            [, $method] = explode('::', $name, 2);
            if ('' === $method) {
                throw new \LogicException(
                    "{$builtinName}() string callable must name a method"
                );
            }

            return $method;
        }
        if (Variable::TYPE_ARRAY === $callable->type) {
            return self::parseArrayCallableMethod($callable, $builtinName);
        }
        throw new \LogicException(
            "{$builtinName}() callback must be a string or array callable in this compiler build"
        );
    }

    private static function parseArrayCallableMethod(Variable $callable, string $builtinName): string
    {
        $table = $callable->toArray();
        $idx0 = new Variable(Variable::TYPE_INTEGER);
        $idx0->int(0);
        $idx1 = new Variable(Variable::TYPE_INTEGER);
        $idx1->int(1);
        if (!$table->keyExists($idx0) || !$table->keyExists($idx1)) {
            throw new \LogicException(
                "{$builtinName}() array callback must have two elements"
            );
        }
        $methodVar = $table->findVariable($idx1, false)->resolveIndirect();
        if (Variable::TYPE_STRING !== $methodVar->type) {
            throw new \LogicException(
                "{$builtinName}() method name must be a string"
            );
        }
        $method = $methodVar->toString();
        if ('' === $method) {
            throw new \LogicException(
                "{$builtinName}() array callback must name a method"
            );
        }
        $target = $table->findVariable($idx0, false)->resolveIndirect();
        if (Variable::TYPE_OBJECT === $target->type) {
            throw new \LogicException(
                "{$builtinName}() does not support instance method callables in this compiler build"
            );
        }
        if (Variable::TYPE_STRING !== $target->type) {
            throw new \LogicException(
                "{$builtinName}() array callback class name must be a string"
            );
        }

        return $method;
    }

    public static function resolveStaticMethod(
        \PHPCompiler\VM\Context $ctx,
        string $calledScopeClass,
        string $methodName
    ): PhpFunc {
        $lcCalled = strtolower($calledScopeClass);
        if (!isset($ctx->classes[$lcCalled])) {
            $ctx->autoloadClass($calledScopeClass);
        }
        if (!isset($ctx->classes[$lcCalled])) {
            throw new \LogicException(
                "forward_static_call() call to undefined class {$calledScopeClass}"
            );
        }
        $methodLc = strtolower($methodName);
        $visited = [];
        $lcClass = $lcCalled;
        while (!isset($visited[$lcClass])) {
            $visited[$lcClass] = true;
            if (!isset($ctx->classes[$lcClass])) {
                break;
            }
            $class = $ctx->classes[$lcClass];
            if (isset($class->methods[$methodLc])) {
                $func = $class->methods[$methodLc];
                if (!$func instanceof PhpFunc) {
                    throw new \LogicException(
                        "{$calledScopeClass}::{$methodName}() must be a user method in this compiler build"
                    );
                }
                $decl = $func->block->func;
                if (null === $decl || 0 === (($decl->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC)) {
                    throw new \Error(
                        'Non-static method '.$class->name.'::'.$methodName.'() cannot be called statically'
                    );
                }

                return $func;
            }
            if (null === $class->parentLc) {
                break;
            }
            $lcClass = $class->parentLc;
        }

        throw new \LogicException(
            "Call to undefined static method {$calledScopeClass}::{$methodName}()"
        );
    }
}
