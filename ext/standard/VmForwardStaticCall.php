<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\PHP as PhpFunc;
use PHPCompiler\MethodVisibility;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
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
        $callable = $callable->resolveIndirect();
        // php-src basic_functions.c — zend_is_callable() before class-scope guard (#14788).
        if (!VmCallableInvoke::isInvokable($callable)) {
            throw new \TypeError(
                \sprintf(
                    '%s(): Argument #1 ($callback) must be a valid callback, no array or string given',
                    $builtinName
                )
            );
        }
        // Magic parent/self/static TypeError at global scope precedes the no-scope Error (#10361).
        self::validateCallbackAtGlobalScope($frame, $callable, $builtinName);
        if ('forward_static_call' === $builtinName && !self::hasActiveClassScope($frame)) {
            throw new \Error("Cannot call {$builtinName}() when no class scope is active");
        }
        if (self::isPlainFunctionNameCallable($callable)) {
            return VmCallable::invoke($frame->vmContext, $callable, ...$extraArgs);
        }
        // Caller late-static class (EG called_scope). Method lookup uses the callable class;
        // LSB is forwarded only when caller LSB instanceof callable calling_scope (#20251, #27140).
        $callerLsb = self::callerLateStaticClass($frame, $builtinName, $callable);
        $methodName = self::parseMethodName($callable, $builtinName);
        $methodOwner = self::resolveMethodOwnerClass($frame, $callable, $callerLsb, $builtinName);
        $calledScope = self::resolveForwardStaticCalledScope(
            $frame->vmContext,
            $callerLsb,
            $methodOwner
        );
        self::assertForwardStaticCallable(
            $frame->vmContext,
            $builtinName,
            $methodOwner,
            $callerLsb,
            $methodName
        );
        $vm = $frame->vmContext->runtime->vm;
        try {
            [, , $func] = self::locateStaticMethod($frame->vmContext, $methodOwner, $methodName);
            VmCallable::warnPhpFuncByRefValueArgs(
                $frame->vmContext,
                $frame,
                $func,
                $extraArgs
            );
        } catch (\Throwable) {
            // locate already validated by assertForwardStaticCallable; ignore warn failures.
        }

        return $vm->invokeDeclaredStaticWithCalledScope(
            $methodOwner,
            $calledScope,
            $methodName,
            ...$extraArgs
        );
    }

    /**
     * Class that owns the method body named by the callable (after parent/self/static resolution).
     *
     * Distinct from late-static called-scope: forward_static_call(['A','f']) from B runs A::f with scope B.
     */
    public static function resolveMethodOwnerClass(
        Frame $frame,
        Variable $callable,
        string $calledScope,
        string $builtinName
    ): string {
        $explicit = self::parseExplicitClassFromCallable($callable, $builtinName);
        if (null === $explicit || '' === $explicit) {
            return $calledScope;
        }
        $lc = strtolower($explicit);
        if ('static' === $lc) {
            return $calledScope;
        }
        if ('self' === $lc) {
            try {
                return VmReflection::zeroArgGetClassName($frame);
            } catch (\Error) {
                return $calledScope;
            }
        }
        if ('parent' === $lc) {
            try {
                $defining = VmReflection::zeroArgGetClassName($frame);
            } catch (\Error) {
                $defining = $calledScope;
            }
            $ctx = $frame->vmContext;
            if (null === $ctx) {
                throw new \LogicException(
                    "{$builtinName}() requires VM context in this compiler build"
                );
            }
            $lcDefining = strtolower($defining);
            if (!isset($ctx->classes[$lcDefining])) {
                $ctx->autoloadClass($defining);
            }
            if (!isset($ctx->classes[$lcDefining]) || null === $ctx->classes[$lcDefining]->parentLc) {
                throw new \TypeError(
                    \sprintf(
                        '%s(): Argument #1 ($callback) must be a valid callback, cannot access "parent" when current class scope has no parent',
                        $builtinName
                    )
                );
            }
            $parentLc = $ctx->classes[$lcDefining]->parentLc;

            return $ctx->classes[$parentLc]->name;
        }

        return $explicit;
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
        foreach ($ht->iterate(false) as $value) {
            // Preserve TYPE_INDIRECT so [&$x] writeback matches call_user_func_array (#28793).
            if (Variable::TYPE_INDIRECT === $value->type) {
                $args[] = $value;
                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($value);
            $args[] = $copy;
        }

        return $args;
    }

    /**
     * php-src zif_forward_static_call_array — plain function name strings dispatch like call_user_func().
     */
    private static function isPlainFunctionNameCallable(Variable $callable): bool
    {
        if (Variable::TYPE_STRING !== $callable->type) {
            return false;
        }

        return !str_contains($callable->toString(), '::');
    }

    /**
     * php-src ext/standard/basic_functions.c — zend_is_callable() rejects parent/self/static at global scope.
     */
    private static function validateCallbackAtGlobalScope(Frame $frame, Variable $callable, string $builtinName): void
    {
        if (self::hasActiveClassScope($frame)) {
            return;
        }
        $magic = self::magicClassKeywordFromCallable($callable);
        if (null === $magic) {
            return;
        }
        throw new \TypeError(
            \sprintf(
                '%s(): Argument #1 ($callback) must be a valid callback, cannot access "%s" when no class scope is active',
                $builtinName,
                $magic
            )
        );
    }

    private static function hasActiveClassScope(Frame $frame): bool
    {
        try {
            VmReflection::getCalledClass($frame);

            return true;
        } catch (\Error) {
            return false;
        }
    }

    private static function magicClassKeywordFromCallable(Variable $callable): ?string
    {
        $callable = $callable->resolveIndirect();
        if (Variable::TYPE_STRING === $callable->type) {
            $name = $callable->toString();
            if (!str_contains($name, '::')) {
                return null;
            }
            [$class] = explode('::', $name, 2);
            $class = strtolower($class);
            if (\in_array($class, ['parent', 'self', 'static'], true)) {
                return $class;
            }

            return null;
        }
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
        $class = strtolower($target->toString());
        if (\in_array($class, ['parent', 'self', 'static'], true)) {
            return $class;
        }

        return null;
    }

    /**
     * Caller's late-static class for forward_static_call* (zend_get_called_scope).
     *
     * Visibility checks always use this frame scope. Effective EG called_scope for the
     * invoked method may differ — see {@see resolveForwardStaticCalledScope()} (#27140).
     */
    public static function calledScopeClass(Frame $frame, string $builtinName, Variable $callable): string
    {
        return self::callerLateStaticClass($frame, $builtinName, $callable);
    }

    private static function callerLateStaticClass(Frame $frame, string $builtinName, Variable $callable): string
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
     * php-src basic_functions.c forward_static_call*:
     *   if (called_scope && calling_scope && instanceof_function(called_scope, calling_scope))
     *       fci_cache.called_scope = called_scope;
     * otherwise keep the callable's calling_scope (named / resolved class) as called_scope (#27140).
     */
    public static function resolveForwardStaticCalledScope(
        Context $ctx,
        string $callerLsb,
        string $callingScope
    ): string {
        if (self::isSameOrSubclassOf($ctx, strtolower($callerLsb), strtolower($callingScope))) {
            return $callerLsb;
        }

        return $callingScope;
    }

    /**
     * Explicit class from [ClassName::class, 'method'] at global scope (#10664).
     */
    public static function parseExplicitClassFromCallable(Variable $callable, string $builtinName): ?string
    {
        $callable = $callable->resolveIndirect();
        if (Variable::TYPE_STRING === $callable->type) {
            $name = $callable->toString();
            if (!str_contains($name, '::')) {
                return null;
            }
            [$class, $method] = explode('::', $name, 2);
            if ('' === $class || '' === $method) {
                return null;
            }

            return $class;
        }
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
        Context $ctx,
        string $calledScopeClass,
        string $methodName
    ): PhpFunc {
        return self::locateStaticMethod($ctx, $calledScopeClass, $methodName)[2];
    }

    /**
     * php-src zend_is_callable — forward_static_call* rejects inaccessible inherited private static (#11919).
     *
     * Lookup walks from {@see $methodOwnerClass}; accessibility is checked from {@see $callerScopeClass} (LSB).
     */
    private static function assertForwardStaticCallable(
        Context $ctx,
        string $builtinName,
        string $methodOwnerClass,
        string $callerScopeClass,
        string $methodName
    ): void {
        [$declaringClass, $methodLc] = self::locateStaticMethod($ctx, $methodOwnerClass, $methodName);
        $vis = $declaringClass->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
        $callerClassLc = strtolower($callerScopeClass);
        $declaringClassLc = strtolower($declaringClass->name);
        $declaredName = $declaringClass->methodNames[$methodLc] ?? $methodName;
        try {
            MethodVisibility::assertCallable(
                $vis,
                $callerClassLc,
                $declaringClassLc,
                $declaringClass->name,
                $declaredName,
                false,
                fn (string $classLc, string $ancestorLc): bool => self::isSameOrSubclassOf($ctx, $classLc, $ancestorLc),
                $callerScopeClass
            );
        } catch (\LogicException) {
            $kind = ($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0 ? 'private' : 'protected';
            throw new \TypeError(
                \sprintf(
                    '%s(): Argument #1 ($callback) must be a valid callback, cannot access %s method %s::%s()',
                    $builtinName,
                    $kind,
                    $declaringClass->name,
                    $declaredName
                )
            );
        }
    }

    /**
     * @return array{0: ClassEntry, 1: string, 2: PhpFunc}
     */
    private static function locateStaticMethod(
        Context $ctx,
        string $calledScopeClass,
        string $methodName
    ): array {
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

                return [$class, $methodLc, $func];
            }
            if (null === $class->parentLc) {
                break;
            }
            $lcClass = $class->parentLc;
        }

        // Zend zend_execute_API.c — same wording as a direct static miss (#27921).
        throw new \LogicException(
            "Call to undefined method {$calledScopeClass}::{$methodName}()"
        );
    }

    private static function isSameOrSubclassOf(Context $ctx, string $classLc, string $ancestorLc): bool
    {
        $current = $classLc;
        while (true) {
            if ($current === $ancestorLc) {
                return true;
            }
            if (!isset($ctx->classes[$current])) {
                return false;
            }
            $parentLc = $ctx->classes[$current]->parentLc;
            if (null === $parentLc) {
                return false;
            }
            $current = $parentLc;
        }
    }
}
