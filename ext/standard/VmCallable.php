<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\BuiltinByRefParams;
use PHPCompiler\BuiltinParamNames;
use PHPCompiler\Frame;
use PHPCompiler\Func\PHP as PhpFunc;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\NamedArgs;
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
        ?Variable $callableNameOut = null,
        ?Frame $scopeFrame = null
    ): bool {
        $var = $var->resolveIndirect();
        $name = null;
        $callerClassLc = null !== $scopeFrame ? VmReflection::callerClassLcFromFrame($scopeFrame) : null;
        $ok = self::probeCallable($ctx, $var, $syntaxOnly, $name, $callerClassLc, $scopeFrame);
        if (null !== $callableNameOut && null !== $name) {
            self::writeCallableName($callableNameOut, $name);
        }

        return $ok;
    }

    public static function invoke(Context $ctx, Variable $callback, Variable ...$args): Variable
    {
        return self::invokeAs('call_user_func', $ctx, $callback, ...$args);
    }

    /**
     * Like {@see invoke()} but TypeError messages name $function (php-src-strict, #19837).
     */
    public static function invokeAs(string $function, Context $ctx, Variable $callback, Variable ...$args): Variable
    {
        return self::invokeAsWithScope($function, $ctx, null, $callback, ...$args);
    }

    /**
     * call_user_func* entry — passes the builtin frame so parent/self/static resolve (#25625).
     */
    public static function invokeAsWithScope(
        string $function,
        Context $ctx,
        ?Frame $scopeFrame,
        Variable $callback,
        Variable ...$args
    ): Variable {
        $callback = $callback->resolveIndirect();
        if (VmClosureCall::isClosure($callback)) {
            $state = VmClosureCall::resolve($callback);
            self::warnPhpFuncByRefValueArgs($ctx, $scopeFrame, $state->func, $args);

            return VmClosureCall::invoke($ctx, $state, ...$args);
        }
        if (Variable::TYPE_STRING === $callback->type) {
            return self::invokeStringCallable($ctx, $callback->toString(), $function, $scopeFrame, ...$args);
        }
        if (Variable::TYPE_ARRAY === $callback->type) {
            return self::invokeArrayCallable($ctx, $callback, $function, $scopeFrame, ...$args);
        }
        if (Variable::TYPE_OBJECT === $callback->type) {
            $object = $callback->toObject();
            if (null !== $object->closureState) {
                throw new \TypeError(self::invalidCallbackTypeError($function));
            }
            self::warnObjectInvokeByRefValueArgs($ctx, $scopeFrame, $object->class, $args);

            return $ctx->runtime->vm->invokeInstanceMethod($object, '__invoke', ...$args);
        }

        throw new \TypeError(self::invalidCallbackTypeError($function));
    }

    /**
     * @param list<array{0: string, 1?: mixed, 2?: Variable}> $entries
     */
    public static function invokeWithArgEntries(
        Context $ctx,
        Variable $callback,
        array $entries,
        string $function = 'call_user_func',
        ?Frame $scopeFrame = null
    ): Variable {
        $callback = $callback->resolveIndirect();
        if (VmClosureCall::isClosure($callback)) {
            $state = VmClosureCall::resolve($callback);
            $resolved = self::resolveEntriesForPhpFunction(
                $state->func->block->paramNames,
                $state->func->block->variadicParamIndex,
                null,
                $entries
            );
            self::warnPhpFuncByRefValueArgs($ctx, $scopeFrame, $state->func, $resolved);

            return VmClosureCall::invoke($ctx, $state, ...$resolved);
        }
        if (Variable::TYPE_STRING === $callback->type) {
            return self::invokeStringCallableWithEntries(
                $ctx,
                $callback->toString(),
                $entries,
                $function,
                $scopeFrame
            );
        }
        if (Variable::TYPE_ARRAY === $callback->type) {
            $resolved = self::resolveEntriesToPositional($entries);

            return self::invokeArrayCallable($ctx, $callback, $function, $scopeFrame, ...$resolved);
        }
        if (Variable::TYPE_OBJECT === $callback->type) {
            $object = $callback->toObject();
            if (null !== $object->closureState) {
                throw new \TypeError(self::invalidCallbackTypeError($function));
            }
            $resolved = self::resolveEntriesToPositional($entries);
            self::warnObjectInvokeByRefValueArgs($ctx, $scopeFrame, $object->class, $resolved);

            return $ctx->runtime->vm->invokeInstanceMethod($object, '__invoke', ...$resolved);
        }

        throw new \TypeError(self::invalidCallbackTypeError($function));
    }

    /**
     * @return list<array{0: string, 1?: mixed, 2?: Variable}>
     */
    public static function arrayVariableToArgEntries(Variable $arrayVar): array
    {
        $entries = [];
        foreach ($arrayVar->toArray()->iterateKeyed(false) as $pair) {
            [$keyVar, $value] = $pair;
            if (Variable::TYPE_INDIRECT === $value->type) {
                $copy = $value;
            } else {
                $copy = new Variable();
                $copy->copyFrom($value);
            }
            $keyResolved = $keyVar->resolveIndirect();
            if (Variable::TYPE_INTEGER === $keyResolved->type) {
                $entries[] = ['p', $copy];
                continue;
            }
            if (Variable::TYPE_STRING === $keyResolved->type) {
                $key = $keyResolved->toString();
                if ('' !== $key && ctype_digit($key)) {
                    $entries[] = ['p', $copy];
                    continue;
                }
                $entries[] = ['n', $key, $copy];
            }
        }

        return $entries;
    }

    /**
     * @param list<Variable> $params
     */
    public static function invokeArrayParams(
        Context $ctx,
        Variable $callback,
        array $params,
        string $function = 'call_user_func'
    ): Variable {
        if (1 === \count($params)) {
            $sole = $params[0]->resolveIndirect();
            if (Variable::TYPE_ARRAY === $sole->type) {
                return self::invokeWithArgEntries(
                    $ctx,
                    $callback,
                    self::arrayVariableToArgEntries($sole),
                    $function
                );
            }
        }

        $copies = [];
        foreach ($params as $param) {
            $copy = new Variable();
            $copy->copyFrom($param->resolveIndirect());
            $copies[] = $copy;
        }

        return self::invokeAs($function, $ctx, $callback, ...$copies);
    }

    /**
     * Zend call_user_func* / shared callable TypeError when callback is not array|string|object (#19837).
     *
     * php-src: Zend/zend_execute_API.c — zend_is_callable_ex / FCC validation
     */
    public static function invalidCallbackTypeError(string $function = 'call_user_func'): string
    {
        return sprintf(
            '%s(): Argument #1 ($callback) must be a valid callback, no array or string given',
            $function
        );
    }

    public static function invalidStringCallbackTypeError(
        string $name,
        string $function = 'call_user_func'
    ): string {
        return sprintf(
            '%s(): Argument #1 ($callback) must be a valid callback, function "%s" not found or invalid function name',
            $function,
            $name
        );
    }

    /**
     * Zend zend_is_callable_ex — class-string form of a non-static method without a compatible $this (#27141).
     *
     * call_user_func* must TypeError (not bare Error) so catch (TypeError) matches php-src.
     */
    public static function nonStaticMethodCallbackTypeError(
        string $function,
        string $className,
        string $methodName
    ): string {
        return sprintf(
            '%s(): Argument #1 ($callback) must be a valid callback, non-static method %s::%s() cannot be called statically',
            $function,
            $className,
            $methodName
        );
    }

    /**
     * Zend zend_is_callable_ex — inaccessible private/protected method wording (#25709, #25712).
     *
     * array_map/array_filter use "a valid callback or null" (#25711); usort/call_user_func do not.
     */
    public static function inaccessibleMethodCallbackTypeError(
        string $function,
        string $kind,
        string $className,
        string $methodName,
        int $argNum = 1,
        bool $nullAllowed = false
    ): string {
        $phrase = $nullAllowed ? 'a valid callback or null' : 'a valid callback';

        return sprintf(
            '%s(): Argument #%d ($callback) must be %s, cannot access %s method %s::%s()',
            $function,
            $argNum,
            $phrase,
            $kind,
            $className,
            $methodName
        );
    }

    /**
     * When a real method exists but is not visible from $scopeFrame, throw Zend's
     * cannot-access TypeError (usort/uasort/uksort Argument #N; #25712).
     * No-op when the callable is merely malformed or the method is missing.
     */
    public static function throwIfInaccessibleMethodCallback(
        Context $ctx,
        Variable $callback,
        string $function,
        int $argNum,
        ?Frame $scopeFrame,
        bool $nullAllowed = false
    ): void {
        $callback = $callback->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $callback->type) {
            return;
        }
        $table = $callback->toArray();
        $idx0 = new Variable(Variable::TYPE_INTEGER);
        $idx0->int(0);
        $idx1 = new Variable(Variable::TYPE_INTEGER);
        $idx1->int(1);
        if (!$table->keyExists($idx0) || !$table->keyExists($idx1)) {
            return;
        }
        $target = $table->findVariable($idx0, false)->resolveIndirect();
        $methodVar = $table->findVariable($idx1, false)->resolveIndirect();
        if (Variable::TYPE_STRING !== $methodVar->type) {
            return;
        }
        $methodName = $methodVar->toString();
        if ('' === $methodName || !self::isValidMethodName($methodName)) {
            return;
        }
        if (Variable::TYPE_OBJECT === $target->type) {
            self::assertInstanceMethodVisibleForInvoke(
                $ctx,
                $target->toObject()->class,
                $methodName,
                $function,
                $scopeFrame,
                $argNum,
                $nullAllowed
            );

            return;
        }
        if (Variable::TYPE_STRING !== $target->type) {
            return;
        }
        $class = $target->toString();
        if ('' === $class) {
            return;
        }
        $resolved = self::resolveScopeKeywordClass($ctx, $class, $scopeFrame, false, $function);
        if (null === $resolved || '' === $resolved) {
            return;
        }
        $located = self::locateCallableMethodSoft($ctx, $resolved, $methodName);
        if (null === $located) {
            return;
        }
        [$declaring, $methodLc] = $located;
        self::assertDeclaringMethodVisibleForInvoke(
            $ctx,
            $declaring,
            $methodLc,
            $methodName,
            $function,
            $scopeFrame,
            $argNum,
            $nullAllowed,
            $resolved
        );
    }

    /**
     * @param-out string|null $callableName
     */
    private static function probeCallable(
        Context $ctx,
        Variable $var,
        bool $syntaxOnly,
        ?string &$callableName,
        ?string $callerClassLc = null,
        ?Frame $scopeFrame = null
    ): bool {
        if (VmClosureCall::isClosure($var)) {
            $callableName = '{closure}';

            return true;
        }
        if (Variable::TYPE_STRING === $var->type) {
            return self::probeStringCallable(
                $ctx,
                $var->toString(),
                $syntaxOnly,
                $callableName,
                $callerClassLc,
                $scopeFrame
            );
        }
        if (Variable::TYPE_ARRAY === $var->type) {
            return self::probeArrayCallable(
                $ctx,
                $var,
                $syntaxOnly,
                $callableName,
                $callerClassLc,
                $scopeFrame
            );
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
        ?string &$callableName,
        ?string $callerClassLc = null,
        ?Frame $scopeFrame = null
    ): bool {
        if ('' === $name) {
            $callableName = '';

            return false;
        }
        if (str_contains($name, '::')) {
            [$class, $method] = explode('::', $name, 2);
            $class = VmReflection::normalizeGlobalIntrospectionName($class);
            if ('' === $class || '' === $method || !self::isValidMethodName($method)) {
                return false;
            }
            $resolved = self::resolveScopeKeywordClass(
                $ctx,
                $class,
                $scopeFrame,
                false,
                'is_callable',
                !$syntaxOnly
            );
            if (null === $resolved) {
                return false;
            }
            $callableName = $resolved.'::'.$method;
            if ($syntaxOnly) {
                return true;
            }

            return VmReflection::isStaticallyCallableMethod(
                $ctx,
                $resolved,
                $method,
                $callerClassLc,
                $scopeFrame
            );
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
        ?string &$callableName,
        ?string $callerClassLc = null,
        ?Frame $scopeFrame = null
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

            return self::isInstanceMethodCallable($ctx, $target->toObject()->class, $method, $callerClassLc);
        }
        if (Variable::TYPE_STRING === $target->type) {
            $class = $target->toString();
            if ('' === $class) {
                return false;
            }
            $resolved = self::resolveScopeKeywordClass(
                $ctx,
                $class,
                $scopeFrame,
                false,
                'is_callable',
                !$syntaxOnly
            );
            if (null === $resolved) {
                return false;
            }
            $callableName = $resolved.'::'.$method;
            if ($syntaxOnly) {
                return true;
            }

            return VmReflection::isStaticallyCallableMethod(
                $ctx,
                $resolved,
                $method,
                $callerClassLc,
                $scopeFrame
            );
        }

        return false;
    }

    /**
     * php-src zend_is_callable — instance method exists and is visible from scope (#9334).
     */
    private static function isInstanceMethodCallable(
        Context $ctx,
        \PHPCompiler\VM\ClassEntry $objectClass,
        string $method,
        ?string $callerClassLc
    ): bool {
        if (!$ctx->runtime->vm->hasInstanceMethod($objectClass, $method)) {
            return self::hasInstanceMagicCall($ctx, $objectClass);
        }
        try {
            [$declaring, $methodLc] = $ctx->runtime->vm->resolveInstanceMethod($objectClass, $method);
        } catch (\LogicException) {
            return self::hasInstanceMagicCall($ctx, $objectClass);
        }
        $vis = $declaring->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
        if (VmReflection::isMethodCallableFromScope(
            $ctx,
            $vis,
            strtolower($declaring->name),
            $callerClassLc
        )) {
            return true;
        }

        // Inaccessible declared method is still callable when __call exists (#25710).
        return self::hasInstanceMagicCall($ctx, $objectClass);
    }

    private static function invokeStringCallable(
        Context $ctx,
        string $name,
        string $function,
        ?Frame $scopeFrame,
        Variable ...$args
    ): Variable {
        if (str_contains($name, '::')) {
            [$class, $method] = explode('::', $name, 2);
            if ('' === $class || '' === $method) {
                throw new \TypeError(self::invalidCallbackTypeError($function));
            }

            return self::invokeClassMethodCallable(
                $ctx,
                $class,
                $method,
                $function,
                $scopeFrame,
                ...$args
            );
        }
        // exit/die are registered Internals but hidden on the 8.2 reference profile (#22796).
        if (!VmReflection::isVisibleToFunctionExists($name)) {
            throw new \TypeError(self::invalidStringCallbackTypeError($name, $function));
        }
        try {
            $internal = VmInternalCall::resolveStringCallback($name);
        } catch (\LogicException) {
            // Not a registered string builtin — try a user-defined function.
            try {
                $fn = VmUserCall::resolveStringCallback($ctx, $name);
            } catch (\LogicException) {
                throw new \TypeError(self::invalidStringCallbackTypeError($name, $function));
            }
            self::warnPhpFuncByRefValueArgs($ctx, $scopeFrame, $fn, $args);

            return $ctx->runtime->vm->invokePhpFunction($fn, ...$args);
        }
        self::warnInternalByRefValueArgs($ctx, $scopeFrame, $internal->getName(), $args);

        return VmInternalCall::invokeInContext($ctx, $internal, ...$args);
    }

    /**
     * @param list<array{0: string, 1?: mixed, 2?: Variable}> $entries
     */
    private static function invokeStringCallableWithEntries(
        Context $ctx,
        string $name,
        array $entries,
        string $function,
        ?Frame $scopeFrame
    ): Variable {
        if (str_contains($name, '::')) {
            $resolved = self::resolveEntriesToPositional($entries);

            return self::invokeStringCallable($ctx, $name, $function, $scopeFrame, ...$resolved);
        }
        if (!VmReflection::isVisibleToFunctionExists($name)) {
            throw new \TypeError(self::invalidStringCallbackTypeError($name, $function));
        }
        try {
            $internal = VmInternalCall::resolveStringCallback($name);
        } catch (\LogicException) {
            // Not a registered string builtin — try a user-defined function.
            try {
                $fn = VmUserCall::resolveStringCallback($ctx, $name);
            } catch (\LogicException) {
                throw new \TypeError(self::invalidStringCallbackTypeError($name, $function));
            }

            $resolved = NamedArgs::resolve(
                $entries,
                $fn->block->paramNames,
                $fn->block->variadicParamIndex,
                $fn->block->func?->name ?? null
            );
            ksort($resolved);
            self::warnPhpFuncByRefValueArgs($ctx, $scopeFrame, $fn, $resolved);

            return $ctx->runtime->vm->invokePhpFunctionWithArgEntries($fn, $entries);
        }
        $paramNames = BuiltinParamNames::forFunction($name) ?? [];
        $variadicIndex = BuiltinParamNames::variadicParamIndexForFunction($name);
        $resolved = NamedArgs::resolve($entries, $paramNames, $variadicIndex, $name);
        ksort($resolved);
        self::warnInternalByRefValueArgs($ctx, $scopeFrame, $name, $resolved);

        return VmInternalCall::invokeInContext($ctx, $internal, ...array_values($resolved));
    }

    /**
     * @param list<string>                                      $paramNames
     * @param list<array{0: string, 1?: mixed, 2?: Variable}> $entries
     *
     * @return list<Variable>
     */
    private static function resolveEntriesForPhpFunction(
        array $paramNames,
        ?int $variadicParamIndex,
        ?string $functionName,
        array $entries
    ): array {
        $resolved = NamedArgs::resolve($entries, $paramNames, $variadicParamIndex, $functionName);
        ksort($resolved);

        return array_values($resolved);
    }

    /**
     * @param list<array{0: string, 1?: mixed, 2?: Variable}> $entries
     *
     * @return list<Variable>
     */
    private static function resolveEntriesToPositional(array $entries): array
    {
        $out = [];
        foreach ($entries as $entry) {
            if ('p' === $entry[0]) {
                // Preserve TYPE_INDIRECT so call_user_func_array([&$x]) writeback works (#28793).
                $out[] = $entry[1];
                continue;
            }
            if ('n' === $entry[0]) {
                $out[] = $entry[2];
            }
        }

        return $out;
    }

    private static function invokeArrayCallable(
        Context $ctx,
        Variable $callback,
        string $function,
        ?Frame $scopeFrame,
        Variable ...$args
    ): Variable {
        $table = $callback->toArray();
        $idx0 = new Variable(Variable::TYPE_INTEGER);
        $idx0->int(0);
        $idx1 = new Variable(Variable::TYPE_INTEGER);
        $idx1->int(1);
        if (!$table->keyExists($idx0) || !$table->keyExists($idx1)) {
            throw new \TypeError(self::invalidCallbackTypeError($function));
        }
        $target = $table->findVariable($idx0, false)->resolveIndirect();
        $methodName = $table->findVariable($idx1, false)->resolveIndirect()->toString();
        if ('' === $methodName) {
            throw new \TypeError(self::invalidCallbackTypeError($function));
        }
            if (Variable::TYPE_OBJECT === $target->type) {
            $object = $target->toObject();
            // Missing method + __call: invokeInstanceMethod does not magic-dispatch (#25747).
            if (
                !$ctx->runtime->vm->hasInstanceMethod($object->class, $methodName)
                && self::hasInstanceMagicCall($ctx, $object->class)
            ) {
                return self::invokeMagicInstanceCall($ctx, $object, $methodName, ...$args);
            }
            // Inaccessible declared method + __call → magic (zend_is_callable_ex / #25710).
            if (self::instanceMethodNeedsMagicCall(
                $ctx,
                $object->class,
                $methodName,
                $function,
                $scopeFrame,
                1
            )) {
                return self::invokeMagicInstanceCall($ctx, $object, $methodName, ...$args);
            }
            self::warnInstanceMethodByRefValueArgs($ctx, $scopeFrame, $object->class, $methodName, $args);

            return $ctx->runtime->vm->invokeInstanceMethod($object, $methodName, ...$args);
        }
        if (Variable::TYPE_STRING === $target->type) {
            $class = $target->toString();
            if ('' === $class) {
                throw new \TypeError(self::invalidCallbackTypeError($function));
            }

            return self::invokeClassMethodCallable(
                $ctx,
                $class,
                $methodName,
                $function,
                $scopeFrame,
                ...$args
            );
        }

        throw new \TypeError(self::invalidCallbackTypeError($function));
    }

    /**
     * call_user_func* instance array callable (#25709 / #25710).
     *
     * Missing methods with `__call` are handled in {@see invokeArrayCallable} (#25747).
     * Inaccessible declared methods: `__call` when present (#25710); otherwise TypeError (#25709).
     *
     * @return bool true when the callee must be invoked via `__call`
     */
    private static function instanceMethodNeedsMagicCall(
        Context $ctx,
        ClassEntry $objectClass,
        string $method,
        string $function,
        ?Frame $scopeFrame,
        int $argNum = 1,
        bool $nullAllowed = false
    ): bool {
        if (!$ctx->runtime->vm->hasInstanceMethod($objectClass, $method)) {
            return false;
        }
        try {
            [$declaring, $methodLc] = $ctx->runtime->vm->resolveInstanceMethod($objectClass, $method);
        } catch (\LogicException) {
            return false;
        }
        $vis = $declaring->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
        $callerClassLc = null !== $scopeFrame
            ? VmReflection::callerClassLcFromFrame($scopeFrame)
            : null;
        if (VmReflection::isMethodCallableFromScope(
            $ctx,
            $vis,
            strtolower($declaring->name),
            $callerClassLc
        )) {
            return false;
        }
        if (self::hasInstanceMagicCall($ctx, $objectClass)) {
            return true;
        }
        $kind = ($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0 ? 'private' : 'protected';
        $declaredName = $declaring->methodNames[$methodLc] ?? $method;
        throw new \TypeError(self::inaccessibleMethodCallbackTypeError(
            $function,
            $kind,
            $declaring->name,
            $declaredName,
            $argNum,
            $nullAllowed
        ));
    }

    /**
     * call_user_func* — reject inaccessible instance methods before invoke (#25709).
     *
     * Soft gate for usort/etc.: inaccessible + `__call` is allowed (zend_is_callable_ex / #25710).
     */
    private static function assertInstanceMethodVisibleForInvoke(
        Context $ctx,
        ClassEntry $objectClass,
        string $method,
        string $function,
        ?Frame $scopeFrame,
        int $argNum = 1,
        bool $nullAllowed = false
    ): void {
        // Throws TypeError when inaccessible without __call; returns true for magic path.
        self::instanceMethodNeedsMagicCall(
            $ctx,
            $objectClass,
            $method,
            $function,
            $scopeFrame,
            $argNum,
            $nullAllowed
        );
    }

    /**
     * Shared visibility gate for static array/`Class::method` callables (#25709 / #25710 / #25712).
     *
     * @return bool true when the callee must be invoked via `__callStatic`
     */
    private static function declaringMethodNeedsStaticMagicCall(
        Context $ctx,
        ClassEntry $declaring,
        string $methodLc,
        string $methodFallback,
        string $function,
        ?Frame $scopeFrame,
        string $resolvedClassName,
        int $argNum = 1,
        bool $nullAllowed = false
    ): bool {
        $vis = $declaring->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
        $callerClassLc = null !== $scopeFrame
            ? VmReflection::callerClassLcFromFrame($scopeFrame)
            : null;
        if (VmReflection::isMethodCallableFromScope(
            $ctx,
            $vis,
            strtolower($declaring->name),
            $callerClassLc
        )) {
            return false;
        }
        if (self::hasStaticMagicCall($ctx, $resolvedClassName)) {
            return true;
        }
        $kind = ($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0 ? 'private' : 'protected';
        $declaredName = $declaring->methodNames[$methodLc] ?? $methodFallback;
        throw new \TypeError(self::inaccessibleMethodCallbackTypeError(
            $function,
            $kind,
            $declaring->name,
            $declaredName,
            $argNum,
            $nullAllowed
        ));
    }

    /**
     * Soft visibility gate for instance + static array/`Class::method` callables (#25709, #25712).
     */
    private static function assertDeclaringMethodVisibleForInvoke(
        Context $ctx,
        ClassEntry $declaring,
        string $methodLc,
        string $methodFallback,
        string $function,
        ?Frame $scopeFrame,
        int $argNum = 1,
        bool $nullAllowed = false,
        ?string $resolvedClassNameForMagic = null
    ): void {
        if (null !== $resolvedClassNameForMagic) {
            self::declaringMethodNeedsStaticMagicCall(
                $ctx,
                $declaring,
                $methodLc,
                $methodFallback,
                $function,
                $scopeFrame,
                $resolvedClassNameForMagic,
                $argNum,
                $nullAllowed
            );

            return;
        }
        $vis = $declaring->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
        $callerClassLc = null !== $scopeFrame
            ? VmReflection::callerClassLcFromFrame($scopeFrame)
            : null;
        if (VmReflection::isMethodCallableFromScope(
            $ctx,
            $vis,
            strtolower($declaring->name),
            $callerClassLc
        )) {
            return;
        }
        $kind = ($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0 ? 'private' : 'protected';
        $declaredName = $declaring->methodNames[$methodLc] ?? $methodFallback;
        throw new \TypeError(self::inaccessibleMethodCallbackTypeError(
            $function,
            $kind,
            $declaring->name,
            $declaredName,
            $argNum,
            $nullAllowed
        ));
    }

    /**
     * Resolve and invoke Class::method / parent::method string callables (zend_execute_API.c, #25625).
     *
     * parent/self/static are scope keywords for call_user_func* / is_callable only — not for $c().
     */
    private static function invokeClassMethodCallable(
        Context $ctx,
        string $className,
        string $methodName,
        string $function,
        ?Frame $scopeFrame,
        Variable ...$args
    ): Variable {
        $rawLc = strtolower(ltrim($className, '\\'));
        $isMagic = \in_array($rawLc, ['parent', 'self', 'static'], true);
        $resolved = self::resolveScopeKeywordClass($ctx, $className, $scopeFrame, true, $function);
        if (null === $resolved || '' === $resolved) {
            throw new \TypeError(self::invalidCallbackTypeError($function));
        }
        $located = self::locateCallableMethod($ctx, $resolved, $methodName, $function);
        if (null === $located) {
            // Missing method + __callStatic — zend_is_callable_ex / call_user_func* (#25747).
            if (self::hasStaticMagicCall($ctx, $resolved)) {
                return self::invokeMagicStaticCall($ctx, $resolved, $methodName, ...$args);
            }
            throw new \TypeError(self::invalidStringCallbackTypeError(
                $className.'::'.$methodName,
                $function
            ));
        }
        [$declaring, $methodLc, $isStatic] = $located;
        $vm = $ctx->runtime->vm;
        if (!$isStatic) {
            $thisVar = null !== $scopeFrame
                ? \PHPCompiler\VM\ClosureSupport::callerThis($scopeFrame)
                : null;
            $methodDisplay = $declaring->methodNames[$methodLc] ?? $methodName;
            if (null === $thisVar) {
                // php-src: zend_is_callable_ex → TypeError via call_user_func* (#27141 / #27144)
                throw new \TypeError(self::nonStaticMethodCallbackTypeError(
                    $function,
                    $declaring->name,
                    $methodDisplay
                ));
            }
            $object = $thisVar->resolveIndirect()->toObject();
            $namedLc = strtolower($resolved);
            $objectLc = strtolower($object->class->name);
            if (!self::objectInHierarchy($ctx, $objectLc, $namedLc)) {
                throw new \TypeError(self::nonStaticMethodCallbackTypeError(
                    $function,
                    $declaring->name,
                    $methodDisplay
                ));
            }
            // Class-string form: inaccessible instance method + object __call (#25710).
            if (self::instanceMethodNeedsMagicCall(
                $ctx,
                $object->class,
                $methodName,
                $function,
                $scopeFrame,
                1
            )) {
                return self::invokeMagicInstanceCall($ctx, $object, $methodName, ...$args);
            }
            $func = $declaring->methods[$methodLc];
            if (!$func instanceof PhpFunc) {
                self::warnInstanceMethodByRefValueArgs($ctx, $scopeFrame, $object->class, $methodName, $args);

                return $vm->invokeInstanceMethod($object, $methodName, ...$args);
            }
            $boundThis = new Variable();
            $boundThis->object($object);
            self::warnPhpFuncByRefValueArgs($ctx, $scopeFrame, $func, $args);

            return $vm->invokePhpFunctionIsolated($func, $boundThis, ...$args);
        }

        $needsMagic = self::declaringMethodNeedsStaticMagicCall(
            $ctx,
            $declaring,
            $methodLc,
            $methodName,
            $function,
            $scopeFrame,
            $resolved,
            1
        );
        $calledScope = $resolved;
        if ($isMagic && null !== $scopeFrame) {
            try {
                $calledScope = VmReflection::getCalledClass($scopeFrame);
            } catch (\Error) {
                $calledScope = $resolved;
            }
        }

        if ($needsMagic) {
            return self::invokeMagicStaticCall($ctx, $resolved, $methodName, ...$args);
        }
        $staticFunc = $declaring->methods[$methodLc] ?? null;
        if ($staticFunc instanceof PhpFunc) {
            self::warnPhpFuncByRefValueArgs($ctx, $scopeFrame, $staticFunc, $args);
        }

        return $vm->invokeDeclaredStaticWithCalledScope(
            $resolved,
            $calledScope,
            $methodName,
            ...$args
        );
    }

    /**
     * php-src zend_is_callable_ex — resolve parent/self/static from the active class scope (#25625).
     *
     * On successful resolve under PROFILE≥8.2, emits E_DEPRECATED for the scope keyword (#27915)
     * unless {@see $emitDeprecation} is false (is_callable syntax-only).
     *
     * @return string|null resolved class name; null when not resolvable (is_callable → false)
     */
    private static function resolveScopeKeywordClass(
        Context $ctx,
        string $className,
        ?Frame $scopeFrame,
        bool $throwOnFailure,
        string $function,
        bool $emitDeprecation = true
    ): ?string {
        $normalized = VmReflection::normalizeGlobalIntrospectionName($className);
        $lc = strtolower($normalized);
        if (!\in_array($lc, ['parent', 'self', 'static'], true)) {
            return $normalized;
        }
        if (null === $scopeFrame) {
            if ($throwOnFailure) {
                throw new \TypeError(\sprintf(
                    '%s(): Argument #1 ($callback) must be a valid callback, cannot access "%s" when no class scope is active',
                    $function,
                    $lc
                ));
            }

            return null;
        }
        if ('static' === $lc) {
            try {
                $resolved = VmReflection::getCalledClass($scopeFrame);
            } catch (\Error) {
                if ($throwOnFailure) {
                    throw new \TypeError(\sprintf(
                        '%s(): Argument #1 ($callback) must be a valid callback, cannot access "%s" when no class scope is active',
                        $function,
                        $lc
                    ));
                }

                return null;
            }
            self::maybeDeprecateScopeKeywordCallable($ctx, $scopeFrame, $lc, $emitDeprecation);

            return $resolved;
        }
        if ('self' === $lc) {
            try {
                $resolved = VmReflection::zeroArgGetClassName($scopeFrame);
            } catch (\Error) {
                if ($throwOnFailure) {
                    throw new \TypeError(\sprintf(
                        '%s(): Argument #1 ($callback) must be a valid callback, cannot access "%s" when no class scope is active',
                        $function,
                        $lc
                    ));
                }

                return null;
            }
            self::maybeDeprecateScopeKeywordCallable($ctx, $scopeFrame, $lc, $emitDeprecation);

            return $resolved;
        }
        // parent
        try {
            $defining = VmReflection::zeroArgGetClassName($scopeFrame);
        } catch (\Error) {
            if ($throwOnFailure) {
                throw new \TypeError(\sprintf(
                    '%s(): Argument #1 ($callback) must be a valid callback, cannot access "%s" when no class scope is active',
                    $function,
                    $lc
                ));
            }

            return null;
        }
        $lcDefining = strtolower($defining);
        if (!isset($ctx->classes[$lcDefining])) {
            $ctx->autoloadClass($defining);
        }
        if (!isset($ctx->classes[$lcDefining]) || null === $ctx->classes[$lcDefining]->parentLc) {
            if ($throwOnFailure) {
                throw new \TypeError(\sprintf(
                    '%s(): Argument #1 ($callback) must be a valid callback, cannot access "parent" when current class scope has no parent',
                    $function
                ));
            }

            return null;
        }
        $parentLc = $ctx->classes[$lcDefining]->parentLc;
        $resolved = $ctx->classes[$parentLc]->name;
        self::maybeDeprecateScopeKeywordCallable($ctx, $scopeFrame, $lc, $emitDeprecation);

        return $resolved;
    }

    /**
     * Zend zend_call_function — E_WARNING when a by-ref parameter receives a non-reference (#28793).
     *
     * call_user_func* / forward_static_call* / FCC paths pass by-value copies; php-src still
     * invokes the callee but warns and does not write back. Real references (TYPE_INDIRECT from
     * `[&$x]`) bind silently.
     *
     * @param array<int, Variable> $args parameter arguments (no $this)
     */
    public static function warnPhpFuncByRefValueArgs(
        Context $ctx,
        ?Frame $scopeFrame,
        PhpFunc $func,
        array $args
    ): void {
        $block = $func->block;
        if ([] === $block->paramByRef) {
            return;
        }
        self::warnByRefParamsGivenByValue(
            $ctx,
            $scopeFrame,
            self::phpCalleeDisplayName($func),
            $block->paramByRef,
            $block->paramNames,
            $args,
            $block->variadicParamIndex
        );
    }

    /**
     * @param array<int, Variable> $args
     */
    public static function warnInternalByRefValueArgs(
        Context $ctx,
        ?Frame $scopeFrame,
        string $functionName,
        array $args
    ): void {
        $indices = BuiltinByRefParams::forFunction($functionName);
        $variadicFrom = BuiltinByRefParams::variadicByRefFromIndex($functionName);
        if ([] === $indices && null === $variadicFrom) {
            return;
        }
        $paramNames = BuiltinParamNames::forFunction($functionName) ?? [];
        $paramByRef = [];
        foreach ($indices as $idx) {
            $paramByRef[(int) $idx] = true;
        }
        self::warnByRefParamsGivenByValue(
            $ctx,
            $scopeFrame,
            $functionName,
            $paramByRef,
            $paramNames,
            $args,
            $variadicFrom
        );
    }

    /**
     * @param array<int, Variable> $args
     */
    private static function warnInstanceMethodByRefValueArgs(
        Context $ctx,
        ?Frame $scopeFrame,
        ClassEntry $class,
        string $methodName,
        array $args
    ): void {
        $methodLc = strtolower($methodName);
        $current = $class;
        $visited = [];
        while (true) {
            $lc = strtolower($current->name);
            if (isset($visited[$lc])) {
                return;
            }
            $visited[$lc] = true;
            if (isset($current->methods[$methodLc])) {
                $func = $current->methods[$methodLc];
                if ($func instanceof PhpFunc) {
                    self::warnPhpFuncByRefValueArgs($ctx, $scopeFrame, $func, $args);
                }

                return;
            }
            if (null === $current->parentLc || !isset($ctx->classes[$current->parentLc])) {
                return;
            }
            $current = $ctx->classes[$current->parentLc];
        }
    }

    /**
     * @param array<int, Variable> $args
     */
    private static function warnObjectInvokeByRefValueArgs(
        Context $ctx,
        ?Frame $scopeFrame,
        ClassEntry $class,
        array $args
    ): void {
        self::warnInstanceMethodByRefValueArgs($ctx, $scopeFrame, $class, '__invoke', $args);
    }

    /**
     * @param array<int, true>     $paramByRef
     * @param list<string>         $paramNames
     * @param array<int, Variable> $args
     */
    private static function warnByRefParamsGivenByValue(
        Context $ctx,
        ?Frame $scopeFrame,
        string $calleeDisplayName,
        array $paramByRef,
        array $paramNames,
        array $args,
        ?int $variadicByRefIdx
    ): void {
        if ([] === $paramByRef && null === $variadicByRefIdx) {
            return;
        }
        $maxArg = -1;
        foreach (array_keys($args) as $key) {
            if ((int) $key > $maxArg) {
                $maxArg = (int) $key;
            }
        }
        foreach ($paramByRef as $paramIdx => $_) {
            $idx = (int) $paramIdx;
            if (null !== $variadicByRefIdx && $idx === $variadicByRefIdx) {
                continue;
            }
            if (!array_key_exists($idx, $args)) {
                continue;
            }
            if (self::argIsCallUserFuncReference($args[$idx])) {
                continue;
            }
            $paramName = $paramNames[$idx] ?? 'param'.$idx;
            self::emitMustBePassedByReferenceWarning(
                $ctx,
                $scopeFrame,
                $calleeDisplayName,
                $idx + 1,
                $paramName
            );
        }
        if (null === $variadicByRefIdx) {
            return;
        }
        $paramName = $paramNames[$variadicByRefIdx] ?? 'param'.$variadicByRefIdx;
        for ($argIndex = $variadicByRefIdx; $argIndex <= $maxArg; ++$argIndex) {
            if (!array_key_exists($argIndex, $args)) {
                continue;
            }
            if (self::argIsCallUserFuncReference($args[$argIndex])) {
                continue;
            }
            self::emitMustBePassedByReferenceWarning(
                $ctx,
                $scopeFrame,
                $calleeDisplayName,
                $argIndex + 1,
                $paramName
            );
        }
    }

    /**
     * call_user_func_array([&$x]) / HT reference elements are TYPE_INDIRECT (#28793).
     */
    private static function argIsCallUserFuncReference(Variable $arg): bool
    {
        return Variable::TYPE_INDIRECT === $arg->type;
    }

    private static function emitMustBePassedByReferenceWarning(
        Context $ctx,
        ?Frame $scopeFrame,
        string $calleeDisplayName,
        int $argNum,
        string $paramName
    ): void {
        $ctx->errors->languageWarning(
            \sprintf(
                '%s(): Argument #%d ($%s) must be passed by reference, value given',
                $calleeDisplayName,
                $argNum,
                $paramName
            ),
            null,
            0,
            $ctx,
            $scopeFrame
        );
    }

    private static function phpCalleeDisplayName(PhpFunc $func): string
    {
        $decl = $func->block->func;
        if (null !== $decl) {
            $name = $decl->name;
            if (is_string($name) && preg_match('/^\{anonymous\}#\d+$/', $name)) {
                return '{closure}';
            }
            if (null !== $decl->class) {
                $className = $decl->class instanceof \PHPCfg\Operand\Literal
                    ? (string) $decl->class->value
                    : (string) $decl->class;

                return $className.'::'.$name;
            }
            if (is_string($name) && '' !== $name) {
                return $name;
            }
        }
        $fallback = $func->getName();
        if (preg_match('/^\{anonymous\}#\d+$/', $fallback) || '{closure}' === $fallback) {
            return '{closure}';
        }

        return $fallback;
    }

    /**
     * php-src zend_is_callable_ex — E_DEPRECATED for self/static/parent in callables (#27915).
     */
    private static function maybeDeprecateScopeKeywordCallable(
        Context $ctx,
        ?Frame $scopeFrame,
        string $keywordLc,
        bool $emitDeprecation
    ): void {
        if (!$emitDeprecation) {
            return;
        }
        if (!\PHPCompiler\CompilerVersion::supportsScopeKeywordCallableDeprecation()) {
            return;
        }
        $ctx->errors->internalDeprecated(
            \sprintf('Use of "%s" in callables is deprecated', $keywordLc),
            $ctx,
            $scopeFrame
        );
    }

    /**
     * @return array{0: \PHPCompiler\VM\ClassEntry, 1: string, 2: bool}|null
     */
    private static function locateCallableMethod(
        Context $ctx,
        string $className,
        string $methodName,
        string $function
    ): ?array {
        $lcClass = strtolower(ltrim($className, '\\'));
        $methodLc = strtolower($methodName);
        if (!isset($ctx->classes[$lcClass])) {
            $ctx->autoloadClass($className);
        }
        if (!isset($ctx->classes[$lcClass])) {
            throw new \TypeError(self::invalidStringCallbackTypeError(
                $className.'::'.$methodName,
                $function
            ));
        }
        $visited = [];
        $walk = $lcClass;
        while (!isset($visited[$walk])) {
            $visited[$walk] = true;
            if (!isset($ctx->classes[$walk])) {
                break;
            }
            $class = $ctx->classes[$walk];
            if (isset($class->methods[$methodLc])) {
                $vis = $class->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
                $isStatic = ($vis & \PHPCfg\Func::FLAG_STATIC) !== 0;
                $func = $class->methods[$methodLc];
                if (!$isStatic && $func instanceof \PHPCompiler\Func\PHP) {
                    $decl = $func->block->func;
                    if (null !== $decl && (($decl->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) !== 0) {
                        $isStatic = true;
                    }
                }

                return [$class, $methodLc, $isStatic];
            }
            if (null === $class->parentLc) {
                break;
            }
            $walk = $class->parentLc;
        }

        return null;
    }

    /**
     * Like {@see locateCallableMethod} but returns null when the class/method is missing
     * (no TypeError) — used for inaccessible-callback diagnostics (#25712).
     *
     * @return array{0: \PHPCompiler\VM\ClassEntry, 1: string}|null
     */
    private static function locateCallableMethodSoft(
        Context $ctx,
        string $className,
        string $methodName
    ): ?array {
        $lcClass = strtolower(ltrim($className, '\\'));
        $methodLc = strtolower($methodName);
        if (!isset($ctx->classes[$lcClass])) {
            $ctx->autoloadClass($className);
        }
        if (!isset($ctx->classes[$lcClass])) {
            return null;
        }
        $visited = [];
        $walk = $lcClass;
        while (!isset($visited[$walk])) {
            $visited[$walk] = true;
            if (!isset($ctx->classes[$walk])) {
                break;
            }
            $class = $ctx->classes[$walk];
            if (isset($class->methods[$methodLc])) {
                return [$class, $methodLc];
            }
            if (null === $class->parentLc) {
                break;
            }
            $walk = $class->parentLc;
        }

        return null;
    }

    private static function objectInHierarchy(Context $ctx, string $objectLc, string $namedLc): bool
    {
        $current = $objectLc;
        while (true) {
            if ($current === $namedLc) {
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

    /**
     * php-src zend_is_callable — __call makes missing instance methods invokable.
     */
    private static function hasInstanceMagicCall(Context $ctx, \PHPCompiler\VM\ClassEntry $class): bool
    {
        $lcClass = strtolower($class->name);
        $visited = [];
        while (!isset($visited[$lcClass])) {
            $visited[$lcClass] = true;
            if (!isset($ctx->classes[$lcClass])) {
                return false;
            }
            $entry = $ctx->classes[$lcClass];
            if (isset($entry->methods['__call'])) {
                return true;
            }
            if (null === $entry->parentLc) {
                return false;
            }
            $lcClass = $entry->parentLc;
        }

        return false;
    }

    /**
     * php-src zend_is_callable — __callStatic makes missing static methods invokable.
     */
    private static function hasStaticMagicCall(Context $ctx, string $className): bool
    {
        $lcClass = strtolower(ltrim($className, '\\'));
        $visited = [];
        while (!isset($visited[$lcClass])) {
            $visited[$lcClass] = true;
            if (!isset($ctx->classes[$lcClass])) {
                return false;
            }
            $entry = $ctx->classes[$lcClass];
            if (isset($entry->methods['__callstatic'])) {
                return true;
            }
            if (null === $entry->parentLc) {
                return false;
            }
            $lcClass = $entry->parentLc;
        }

        return false;
    }

    /**
     * call_user_func* → `__call($name, $arguments)` (zend_std_get_method / #25747).
     *
     * {@see \PHPCompiler\VM::invokeInstanceMethod} does not magic-dispatch; build the
     * Zend ($name, $args) pair and invoke `__call` directly.
     */
    private static function invokeMagicInstanceCall(
        Context $ctx,
        \PHPCompiler\VM\ObjectEntry $object,
        string $methodName,
        Variable ...$args
    ): Variable {
        $nameVar = new Variable(Variable::TYPE_STRING);
        $nameVar->string($methodName);

        return $ctx->runtime->vm->invokeInstanceMethod(
            $object,
            '__call',
            $nameVar,
            self::packMagicCallArguments(...$args)
        );
    }

    /**
     * call_user_func* → `__callStatic($name, $arguments)` (#25747).
     */
    private static function invokeMagicStaticCall(
        Context $ctx,
        string $className,
        string $methodName,
        Variable ...$args
    ): Variable {
        $nameVar = new Variable(Variable::TYPE_STRING);
        $nameVar->string($methodName);

        return $ctx->runtime->vm->invokeDeclaredStaticWithCalledScope(
            $className,
            $className,
            '__callStatic',
            $nameVar,
            self::packMagicCallArguments(...$args)
        );
    }

    /**
     * Pack positional call_user_func* args into `__call` / `__callStatic`'s `$arguments` array.
     */
    private static function packMagicCallArguments(Variable ...$args): Variable
    {
        $argsVar = new Variable();
        $argsVar->newArray();
        $packed = $argsVar->toArray();
        $i = 0;
        foreach ($args as $arg) {
            $copy = new Variable();
            $copy->copyFrom($arg->resolveIndirect());
            $packed->addIndex($i++, $copy);
        }

        return $argsVar;
    }
}
