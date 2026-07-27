<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\Frame;
use PHPCompiler\Func;
use PHPCompiler\MethodVisibility;
use PHPCompiler\PseudoClassScope;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\TraitSelfClassScope;

/**
 * Closure::fromCallable / bind / bindTo helpers (issue #3266, #3673, Zend zend_closures.c).
 */
final class ClosureSupport
{
    public static function requireClosureClass(Context $ctx): ClassEntry
    {
        $class = $ctx->classes['closure'] ?? null;
        if (null === $class) {
            throw new \LogicException('Closure is not registered in this compiler build');
        }

        return $class;
    }

    public static function requireClosureState(ObjectEntry $object, string $context): ClosureState
    {
        $state = $object->closureState;
        if (null === $state) {
            throw new \LogicException("{$context} expects a Closure instance");
        }

        return $state;
    }

    public static function wrapState(Context $ctx, ClosureState $state): ObjectEntry
    {
        return $state->wrapObject($ctx);
    }

    /**
     * @return list<Variable>
     */
    public static function callerArgsForVisibility(Frame $frame): array
    {
        if (!empty($frame->callArgs)) {
            return $frame->callArgs;
        }
        if (!empty($frame->calledArgs)) {
            return $frame->calledArgs;
        }

        return [];
    }

    public static function callerClassLc(Frame $frame): ?string
    {
        for ($f = $frame; null !== $f; $f = $f->parent) {
            if (null === $f->block || null === $f->block->func || null === $f->block->func->class) {
                continue;
            }

            return strtolower($f->block->func->class->value);
        }

        return null;
    }

    /**
     * Active $this from the caller's instance frame (skip Internal handler frames).
     * Used by Closure::fromCallable([Class, instanceMethod]) to bind a fake closure
     * when Zend would accept the callback (zend_closures.c / zend_is_callable, #23771).
     */
    public static function callerThis(Frame $frame): ?Variable
    {
        for ($f = $frame; null !== $f; $f = $f->parent) {
            if (null === $f->block || null === $f->block->func) {
                continue;
            }
            if ((($f->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) !== 0) {
                continue;
            }
            $isClosure = (($f->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE) !== 0;
            if (!$isClosure && null === $f->block->func->class) {
                continue;
            }
            $idx = $f->block->slotIndexForVariableName('this');
            if (null !== $idx && isset($f->scope[$idx])) {
                $bound = $f->scope[$idx]->resolveIndirect();
                if (Variable::TYPE_OBJECT === $bound->type) {
                    return $f->scope[$idx];
                }
            }
            $fromScope = $f->block->findVariableByRuntimeName('this', $f);
            if (null !== $fromScope) {
                $bound = $fromScope->resolveIndirect();
                if (Variable::TYPE_OBJECT === $bound->type) {
                    return $fromScope;
                }
            }
            if ($isClosure) {
                $state = $f->closureCall ?? $f->pendingClosureInvoke;
                if (null !== $state && null !== $state->boundThis) {
                    $bound = $state->boundThis->resolveIndirect();
                    if (Variable::TYPE_OBJECT === $bound->type) {
                        return $state->boundThis;
                    }
                }
            }
            if (!empty($f->calledArgs)) {
                $receiver = $f->calledArgs[0]->resolveIndirect();
                if (Variable::TYPE_OBJECT === $receiver->type) {
                    return $f->calledArgs[0];
                }
            }
            if (!empty($f->callArgs)) {
                $receiver = $f->callArgs[0]->resolveIndirect();
                if (Variable::TYPE_OBJECT === $receiver->type) {
                    return $f->callArgs[0];
                }
            }
        }

        return null;
    }

    /**
     * Closure::fromStatic() — static method callable string to closure (#9992, Zend/zend_closures.c).
     */
    public static function fromStatic(Context $ctx, Frame $frame, Variable $callable): ObjectEntry
    {
        $callable = $callable->resolveIndirect();
        if (Variable::TYPE_STRING !== $callable->type) {
            throw new \TypeError(
                'Closure::fromStatic(): Argument #1 ($callable) must be of type string, '
                .self::valueTypeName($callable).' given'
            );
        }
        $name = $callable->toString();
        if (!str_contains($name, '::')) {
            throw new \TypeError(
                'Closure::fromStatic(): Argument #1 ($callable) must be a valid callback'
            );
        }

        return self::wrapState($ctx, self::fromStaticStringCallable($ctx, $frame, $name));
    }

    /**
     * @param bool $fromCallableApi true for Closure::fromCallable() (TypeError); false for FCC `$name(...)` (Error)
     */
    public static function fromCallable(
        Context $ctx,
        Frame $frame,
        Variable $callable,
        bool $parentScope = false,
        bool $fromCallableApi = false
    ): ObjectEntry {
        $callable = $callable->resolveIndirect();
        if (Variable::TYPE_OBJECT === $callable->type) {
            $state = $callable->toObject()->closureState;
            if (null !== $state) {
                return $callable->toObject();
            }
            $invokeState = self::tryFromInvokableObject($ctx, $frame, $callable);
            if (null !== $invokeState) {
                return self::wrapState($ctx, $invokeState);
            }

            throw new \Error(
                'Object of type '.self::valueTypeName($callable).' is not callable'
            );
        }
        if (Variable::TYPE_ENUM_CASE === $callable->type) {
            throw new \Error(
                'Object of type '.self::valueTypeName($callable).' is not callable'
            );
        }
        if (Variable::TYPE_STRING === $callable->type) {
            $name = $callable->toString();
            if (str_starts_with($name, 'new ')) {
                return self::wrapState($ctx, self::fromNewClassCallable($ctx, $frame, substr($name, 4)));
            }
            if (str_contains($name, '::')) {
                return self::wrapState($ctx, self::fromStaticStringCallable($ctx, $frame, $name, $fromCallableApi));
            }

            return self::wrapState($ctx, self::fromFunctionName($ctx, $name, $fromCallableApi));
        }
        if (Variable::TYPE_ARRAY === $callable->type) {
            return self::wrapState($ctx, self::fromArrayCallable($ctx, $frame, $callable, $parentScope, $fromCallableApi));
        }

        throw new \LogicException(
            'Closure::fromCallable(): Argument #1 ($callback) must be a valid callback'
        );
    }

    public static function bindTo(
        Context $ctx,
        ClosureState $state,
        Variable $newThis,
        ?Variable $newScope,
        string $context = 'Closure::bindTo()',
        ?Frame $frame = null
    ): ?ObjectEntry {
        $newThis = self::normalizeNewThis($newThis);
        if (Variable::TYPE_NULL !== $newThis->type && Variable::TYPE_OBJECT !== $newThis->type) {
            $thisArg = 'Closure::bind()' === $context ? '#2 ($newThis)' : '#1 ($newThis)';
            throw new \TypeError(
                "{$context}: Argument {$thisArg} must be of type ?object, "
                .self::valueTypeName($newThis).' given'
            );
        }
        $scopeClass = self::resolveScopeClass($newScope, $newThis, $context);
        if (self::rejectBindForExplicitScopeFailure($ctx, $newScope, $scopeClass, $frame)) {
            return null;
        }
        if (null !== $state->wrappedFunc || null !== $state->methodName) {
            // Fake method closures: unbind warns with the method-specific text (#23421).
            if (
                Variable::TYPE_NULL === $newThis->type
                && $state->isNonStaticMethodFakeClosure()
            ) {
                self::warnCannotUnbindThisOfMethod($ctx, $frame);
            }

            return null;
        }
        if (Variable::TYPE_OBJECT === $newThis->type && $state->isStaticClosure()) {
            self::warnCannotBindInstanceToStaticClosure($ctx, $frame);

            return null;
        }
        // php-src zend_closures.c: reject unbind only when this_ptr is set AND USES_THIS
        // (#23387). Free closures that read $this may still bindTo(null) → unbound Closure.
        if (
            Variable::TYPE_NULL === $newThis->type
            && ClosureBindJitHelper::shouldRejectUnbindThis($state->usesThis(), $state->hasBoundThis())
        ) {
            self::warnCannotUnbindThis($ctx, $frame);

            return null;
        }
        $bound = $state->cloneForBind();
        if (Variable::TYPE_NULL === $newThis->type) {
            $bound->boundThis = null;
        } else {
            $boundThis = new Variable();
            $boundThis->copyFrom($newThis);
            $boundThis = $boundThis->resolveIndirect();
            if (Variable::TYPE_OBJECT === $boundThis->type && isset($boundThis->object)) {
                // Inline `new` call args release temps after bind; keep $this alive (#11857).
                ObjectLifetime::addRef($boundThis->object);
            }
            $stored = new Variable();
            $stored->copyFrom($boundThis);
            $bound->boundThis = $stored;
        }
        $bound->boundScopeClass = $scopeClass;

        return self::wrapState($ctx, $bound);
    }

    /**
     * Zend rejects explicit scope before other bind failures (#18192, zend_closures.c).
     *
     * Applies to user closures and fromCallable wrappers alike.
     */
    private static function rejectBindForExplicitScopeFailure(
        Context $ctx,
        ?Variable $newScope,
        ?string $scopeClass,
        ?Frame $frame
    ): bool {
        if (null !== $scopeClass && self::isExplicitStringScope($newScope)) {
            if (!self::scopeClassExists($ctx, $scopeClass)) {
                self::warnScopeClassNotFound($ctx, $frame, $scopeClass);

                return true;
            }
        }
        if (
            null !== $scopeClass
            && self::isExplicitScope($newScope)
            && self::isInternalScopeClass($ctx, $scopeClass)
        ) {
            self::warnCannotBindInternalScope($ctx, $frame, $scopeClass);

            return true;
        }

        return false;
    }

    private static function isExplicitStringScope(?Variable $newScope): bool
    {
        if (null === $newScope) {
            return false;
        }
        $newScope = $newScope->resolveIndirect();
        if (Variable::TYPE_STRING !== $newScope->type) {
            return false;
        }

        return 'static' !== strtolower($newScope->toString());
    }

    /** True when $newScope was passed explicitly (not omitted / not the static alias). */
    private static function isExplicitScope(?Variable $newScope): bool
    {
        if (null === $newScope) {
            return false;
        }
        $newScope = $newScope->resolveIndirect();
        if (Variable::TYPE_OBJECT === $newScope->type) {
            return true;
        }

        return self::isExplicitStringScope($newScope);
    }

    private static function scopeClassExists(Context $ctx, string $scopeClass): bool
    {
        $lc = strtolower($scopeClass);
        if (!isset($ctx->classes[$lc])) {
            $ctx->autoloadClass($scopeClass);
        }

        return isset($ctx->classes[$lc]);
    }

    private static function isInternalScopeClass(Context $ctx, string $scopeClass): bool
    {
        if (!self::scopeClassExists($ctx, $scopeClass)) {
            return false;
        }

        return $ctx->classes[strtolower($scopeClass)]->isInternal;
    }

    private static function warnScopeClassNotFound(
        Context $ctx,
        ?Frame $frame,
        string $scopeClass
    ): void {
        $ctx->errors->triggerError(
            sprintf('Class "%s" not found', $scopeClass),
            ErrorReporter::E_WARNING,
            null,
            $ctx,
            $frame
        );
    }

    private static function warnCannotBindInternalScope(
        Context $ctx,
        ?Frame $frame,
        string $scopeClass
    ): void {
        $display = $scopeClass;
        $lc = strtolower($scopeClass);
        if (isset($ctx->classes[$lc])) {
            $display = $ctx->classes[$lc]->name;
        }
        $ctx->errors->triggerError(
            "Cannot bind closure to scope of internal class {$display}",
            ErrorReporter::E_WARNING,
            null,
            $ctx,
            $frame
        );
    }

    private static function warnCannotBindInstanceToStaticClosure(
        Context $ctx,
        ?Frame $frame
    ): void {
        $ctx->errors->triggerError(
            'Cannot bind an instance to a static closure',
            ErrorReporter::E_WARNING,
            null,
            $ctx,
            $frame
        );
    }

    private static function warnCannotUnbindThis(
        Context $ctx,
        ?Frame $frame
    ): void {
        $ctx->errors->triggerError(
            ClosureBindJitHelper::UNBIND_THIS_WARNING,
            ErrorReporter::E_WARNING,
            null,
            $ctx,
            $frame
        );
    }

    /** php-src zend_closures.c — fake non-static method unbind (#23421). */
    private static function warnCannotUnbindThisOfMethod(
        Context $ctx,
        ?Frame $frame
    ): void {
        $ctx->errors->triggerError(
            ClosureBindJitHelper::UNBIND_THIS_OF_METHOD_WARNING,
            ErrorReporter::E_WARNING,
            null,
            $ctx,
            $frame
        );
    }

    /**
     * Invoke a bound closure with a temporary $this (Closure::call; issues #4927, #21927).
     *
     * @param list<Variable> $invokeArgs
     */
    public static function call(
        Context $ctx,
        ClosureState $state,
        Variable $newThis,
        array $invokeArgs,
        string $context = 'Closure::call()',
        ?Frame $frame = null
    ): Variable {
        $newThis = self::normalizeNewThis($newThis);
        if (Variable::TYPE_OBJECT !== $newThis->type) {
            throw new \TypeError(
                "{$context}: Argument #1 (\$newThis) must be of type object, "
                .self::valueTypeName($newThis).' given'
            );
        }
        // User closures and instance-method wrappers (fromCallable / ReflectionMethod::getClosure)
        // rebind via Closure::call; free-function / static wrappers do not (#4927, #21927).
        $isInstanceMethodClosure = null !== $state->methodName && null !== $state->methodReceiver;
        if (!$state->isUserClosure() && !$isInstanceMethodClosure) {
            throw new \Error(
                'Cannot call non-static '.$state->func->getName().'() statically'
            );
        }
        if ($state->isStaticClosure()) {
            throw new \Error('Cannot bind static closure to object');
        }
        $scopeClass = $newThis->toObject()->class->name;
        if (self::isInternalScopeClass($ctx, $scopeClass)) {
            self::warnCannotBindInternalScope($ctx, $frame, $scopeClass);

            $null = new Variable();
            $null->null();

            return $null;
        }
        $invokeState = $state->cloneForBind();
        $boundThis = new Variable();
        $boundThis->copyFrom($newThis);
        $invokeState->boundThis = $boundThis;
        $invokeState->boundScopeClass = $scopeClass;
        if ($isInstanceMethodClosure) {
            // initClosureCall dispatches via methodReceiver — point it at the temporary $this.
            $invokeState->methodReceiver = $boundThis;
        }

        $copies = [];
        foreach ($invokeArgs as $arg) {
            $copy = new Variable();
            $copy->copyFrom($arg->resolveIndirect());
            $copies[] = $copy;
        }

        return $ctx->runtime->vm->invokeClosure($invokeState, ...$copies);
    }

    /**
     * Resolve a global function name for FCC / fromCallable.
     *
     * exit/die stay in the function table for 8.4 paren-call lowering but are hidden on the
     * Zend 8.2 reference profile (php-src zend_is_callable / Closure::fromCallable, #22796).
     *
     * @param bool $fromCallableApi Closure::fromCallable() → TypeError; FCC `$name(...)` → Error
     */
    private static function fromFunctionName(Context $ctx, string $name, bool $fromCallableApi = false): ClosureState
    {
        $lc = strtolower($name);
        $visible = isset($ctx->functions[$lc])
            && \PHPCompiler\ext\standard\VmReflection::isVisibleToFunctionExists($name);
        if (!$visible) {
            if ($fromCallableApi) {
                throw new \TypeError(
                    'Failed to create closure from callable: function "'.$name
                    .'" not found or invalid function name'
                );
            }

            throw new \Error('Call to undefined function '.$lc.'()');
        }

        return ClosureState::fromWrappedFunc($ctx->functions[$lc]);
    }

    private static function fromNewClassCallable(Context $ctx, Frame $frame, string $className): ClosureState
    {
        $lcClass = self::resolveClassScopeName($className, $frame, $ctx);
        if (!isset($ctx->classes[$lcClass])) {
            $ctx->autoloadClass($className);
        }
        if (!isset($ctx->classes[$lcClass])) {
            throw new \LogicException(
                "Closure::fromCallable(): Class '{$className}' not found"
            );
        }

        return ClosureState::fromWrappedFunc(new NewCallableHandler($ctx->classes[$lcClass]));
    }

    private static function fromStaticStringCallable(
        Context $ctx,
        Frame $frame,
        string $callable,
        bool $fromCallableApi = false
    ): ClosureState {
        [$className, $methodName] = explode('::', $callable, 2);
        $lcClass = self::resolveClassScopeName($className, $frame, $ctx);
        if (!isset($ctx->classes[$lcClass])) {
            $ctx->autoloadClass($className);
        }
        if (!isset($ctx->classes[$lcClass])) {
            throw new \LogicException(
                "Closure::fromCallable(): Class '{$className}' not found"
            );
        }
        $class = $ctx->classes[$lcClass];
        $methodLc = strtolower($methodName);
        if ($class->isEnum && 'cases' === $methodLc) {
            EnumSupport::ensureBuiltinCasesMethod($class);

            return ClosureState::fromWrappedFunc($class->methods['cases']);
        }
        if ($class->isEnum && null !== $class->backedType && ('from' === $methodLc || 'tryfrom' === $methodLc)) {
            return ClosureState::fromWrappedFunc(new EnumFromHandler($class, 'tryfrom' === $methodLc));
        }
        $namedClassLc = $lcClass;
        $namedClass = $ctx->classes[$namedClassLc];
        [$class, $methodLc] = self::resolveStaticMethod($ctx, $lcClass, $methodLc);
        $vis = $class->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
        $callerClassLc = self::callerClassLc($frame);
        $callerDisplay = self::classDisplayName($ctx, $callerClassLc);
        $declaredName = $class->methodNames[$methodLc] ?? $methodName;
        $isSameOrSubclass = fn (string $classLc, string $ancestorLc): bool => self::isClassSameOrSubclassOf($ctx, $classLc, $ancestorLc);

        // Closure::fromCallable([Class, instanceMethod]) / "Class::instanceMethod": when
        // $this is an instance of Class, Zend binds a fake closure (zend_closures.c, #23771).
        // FCC Class::method(...) still Errors via assertStaticMethodForCallable.
        if ($fromCallableApi && !self::isStaticMethod($class, $methodLc)) {
            $thisVar = self::callerThis($frame);
            if (null !== $thisVar) {
                $resolvedThis = $thisVar->resolveIndirect();
                if (Variable::TYPE_OBJECT === $resolvedThis->type) {
                    $thisClassLc = strtolower($resolvedThis->toObject()->class->name);
                    if (self::isClassSameOrSubclassOf($ctx, $thisClassLc, $namedClassLc)) {
                        self::assertMethodAccessibleForFromCallable(
                            $vis,
                            $callerClassLc,
                            strtolower($class->name),
                            $class->name,
                            $declaredName,
                            $isSameOrSubclass,
                            $callerDisplay
                        );
                        $boundThis = new Variable();
                        $boundThis->copyFrom($thisVar);
                        // Non-virtual: invoke the resolved Func with bound $this (like parent::).
                        $state = ClosureState::fromMethodCallable(
                            $class->methods[$methodLc],
                            $boundThis,
                            $declaredName
                        );
                        $state->methodReceiver = null;
                        $state->methodName = null;
                        $state->boundThis = $boundThis;
                        $state->boundScopeClass = $namedClass->name;

                        return $state;
                    }
                }
            }
            self::assertStaticMethodForCallable($class, $methodLc, true);
        } elseif (!$fromCallableApi) {
            self::assertStaticMethodForCallable($class, $methodLc, false);
        }

        self::assertMethodAccessibleForFromCallable(
            $vis,
            $callerClassLc,
            strtolower($class->name),
            $class->name,
            $declaredName,
            $isSameOrSubclass,
            $callerDisplay
        );

        return ClosureState::fromWrappedFunc($class->methods[$methodLc]);
    }

    private static function fromArrayCallable(
        Context $ctx,
        Frame $frame,
        Variable $callable,
        bool $parentScope = false,
        bool $fromCallableApi = false
    ): ClosureState {
        $table = $callable->toArray();
        $idx0 = new Variable(Variable::TYPE_INTEGER);
        $idx0->int(0);
        $idx1 = new Variable(Variable::TYPE_INTEGER);
        $idx1->int(1);
        if (!$table->keyExists($idx0) || !$table->keyExists($idx1)) {
            throw new \LogicException(
                'Closure::fromCallable(): Argument #1 ($callback) must be a valid callback'
            );
        }
        $receiver = $table->findVariable($idx0, false)->resolveIndirect();
        $methodName = $table->findVariable($idx1, false)->resolveIndirect()->toString();
        if (Variable::TYPE_OBJECT === $receiver->type) {
            return self::fromInstanceMethodCallable($ctx, $frame, $receiver, $methodName, $parentScope);
        }
        if (Variable::TYPE_ENUM_CASE === $receiver->type) {
            return self::fromInstanceMethodCallable(
                $ctx,
                $frame,
                EnumCaseSupport::receiverForInstanceMethod($receiver),
                $methodName
            );
        }
        if (Variable::TYPE_STRING === $receiver->type) {
            return self::fromStaticStringCallable(
                $ctx,
                $frame,
                $receiver->toString().'::'.$methodName,
                $fromCallableApi
            );
        }

        throw new \LogicException(
            'Closure::fromCallable(): Argument #1 ($callback) must be a valid callback'
        );
    }

    /** `(new C)(...)` / Closure::fromCallable($obj) when $obj defines __invoke (zend_closures.c, #9605). */
    private static function tryFromInvokableObject(
        Context $ctx,
        Frame $frame,
        Variable $callable
    ): ?ClosureState {
        if (Variable::TYPE_OBJECT !== $callable->type) {
            return null;
        }
        $object = $callable->toObject();
        if (null !== $object->closureState) {
            return null;
        }
        if (!$ctx->runtime->vm->hasInstanceMethod($object->class, '__invoke')) {
            return null;
        }

        return self::fromInstanceMethodCallable($ctx, $frame, $callable, '__invoke');
    }

    private static function fromInstanceMethodCallable(
        Context $ctx,
        Frame $frame,
        Variable $receiver,
        string $methodName,
        bool $parentScope = false
    ): ClosureState {
        $object = $receiver->toObject();
        $methodLc = strtolower($methodName);
        $class = $object->class;
        $resolveFromLc = strtolower($class->name);
        $boundScopeClass = $class->name;
        if ($parentScope) {
            $resolveFromLc = self::resolveClassScopeName('parent', $frame, $ctx);
            if (!isset($ctx->classes[$resolveFromLc])) {
                throw new \LogicException('parent:: used when class has no parent');
            }
            $boundScopeClass = $ctx->classes[$resolveFromLc]->name;
        }
        [$declaringClass, $methodLc] = self::resolveStaticMethod($ctx, $resolveFromLc, $methodLc);
        $vis = $declaringClass->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
        $callerClassLc = self::callerClassLc($frame);
        $callerDisplay = self::classDisplayName($ctx, $callerClassLc);
        $parentScopeAllows = false;
        if ($parentScope) {
            $parentScopeAllows = MethodVisibility::parentScopeAllows(
                $vis,
                $callerClassLc,
                $resolveFromLc,
                strtolower($declaringClass->name),
                fn (string $classLc, string $ancestorLc): bool => self::isClassSameOrSubclassOf($ctx, $classLc, $ancestorLc)
            );
        }
        self::assertMethodAccessibleForFromCallable(
            $vis,
            $callerClassLc,
            strtolower($declaringClass->name),
            $declaringClass->name,
            $declaringClass->methodNames[$methodLc] ?? $methodName,
            fn (string $classLc, string $ancestorLc): bool => self::isClassSameOrSubclassOf($ctx, $classLc, $ancestorLc),
            $callerDisplay,
            $parentScopeAllows
        );
        $boundThis = new Variable();
        $boundThis->copyFrom($receiver);
        $state = ClosureState::fromMethodCallable($declaringClass->methods[$methodLc], $boundThis, $methodName);
        $state->boundScopeClass = $boundScopeClass;
        if ($parentScope) {
            // Invoke the resolved parent Func directly — virtual dispatch would hit child overrides (#17655).
            $state->methodReceiver = null;
            $state->methodName = null;
            $state->boundThis = $boundThis;
        }

        return $state;
    }

    private static function resolveScopeClass(
        ?Variable $newScope,
        Variable $newThis,
        string $context = 'Closure::bindTo()'
    ): ?string {
        $scopeArg = 'Closure::bind()' === $context ? '#3 ($newScope)' : '#2 ($newScope)';
        if (null === $newScope) {
            if (Variable::TYPE_OBJECT === $newThis->type) {
                return $newThis->toObject()->class->name;
            }

            return null;
        }
        $newScope = $newScope->resolveIndirect();
        if (Variable::TYPE_NULL === $newScope->type) {
            // bindTo($obj, null) — unbound scope; visibility must not widen (#10097, zend_closures.c).
            return null;
        }
        if (Variable::TYPE_OBJECT === $newScope->type) {
            return $newScope->toObject()->class->name;
        }
        if (Variable::TYPE_STRING === $newScope->type) {
            $scope = $newScope->toString();
            if ('static' === strtolower($scope)) {
                if (Variable::TYPE_OBJECT === $newThis->type) {
                    return $newThis->toObject()->class->name;
                }

                return null;
            }

            return $scope;
        }

        throw new \TypeError(
            "{$context}: Argument {$scopeArg} must be of type object|string|null, "
            .self::valueTypeName($newScope).' given'
        );
    }

    /** Enum cases are objects in Zend; bind canonical singleton before bindTo/bind/call (#7201, #8877). */
    private static function normalizeNewThis(Variable $newThis): Variable
    {
        $newThis = $newThis->resolveIndirect();
        if (!EnumCaseSupport::isEnumCaseVariable($newThis)) {
            return $newThis;
        }
        $enumClass = EnumCaseSupport::enumClassForCaseVariable($newThis);
        $caseName = EnumCaseSupport::enumCaseNameForVariable($newThis);
        if (null !== $enumClass && '' !== $caseName) {
            $canonical = BackedEnum::canonicalCaseVariable($enumClass, $caseName);
            if (null !== $canonical) {
                return $canonical->resolveIndirect();
            }
        }

        return EnumCaseSupport::receiverForInstanceMethod($newThis);
    }

    private static function valueTypeName(Variable $value): string
    {
        return EnumCaseSupport::typeNameForVariable($value);
    }

    private static function resolveClassScopeName(string $className, Frame $frame, Context $ctx): string
    {
        $lcClass = strtolower($className);
        if ('self' === $lcClass) {
            if (null === $frame->block->func || null === $frame->block->func->class) {
                PseudoClassScope::fatalInGlobalScope('self');
            }
            $funcClassValue = $frame->block->func->class->value;
            $declaring = strtolower($funcClassValue);
            $funcIsTrait = ($ctx->classes[$declaring] ?? null)?->isTrait ?? false;
            if ($funcIsTrait) {
                $declaring = TraitSelfClassScope::resolveComposingClassLc(
                    $funcClassValue,
                    true,
                    $frame->calledClass,
                    $declaring,
                    strtolower($frame->block->func->name),
                    fn (string $classLc, string $method): ?string => $ctx->classes[$classLc]->traitMethodSources[$method] ?? null,
                    fn (string $classLc): ?string => $ctx->classes[$classLc]->parentLc ?? null,
                    fn (string $classLc): bool => ($ctx->classes[$classLc] ?? null)?->isTrait ?? false,
                );
            }

            return $declaring;
        }
        if ('static' === $lcClass) {
            if (null !== $frame->calledClass && '' !== $frame->calledClass) {
                return strtolower($frame->calledClass);
            }
            if (null === $frame->block->func || null === $frame->block->func->class) {
                PseudoClassScope::fatalNoActiveClassScope('static');
            }

            return strtolower($frame->block->func->class->value);
        }
        if ('parent' === $lcClass) {
            if (null === $frame->block->func || null === $frame->block->func->class) {
                PseudoClassScope::fatalInGlobalScope('parent');
            }
            $funcClassValue = $frame->block->func->class->value;
            $declaring = strtolower($funcClassValue);
            $funcIsTrait = ($ctx->classes[$declaring] ?? null)?->isTrait ?? false;
            if ($funcIsTrait) {
                $declaring = TraitSelfClassScope::resolveComposingClassLc(
                    $funcClassValue,
                    true,
                    $frame->calledClass,
                    $declaring,
                    strtolower($frame->block->func->name),
                    fn (string $classLc, string $method): ?string => $ctx->classes[$classLc]->traitMethodSources[$method] ?? null,
                    fn (string $classLc): ?string => $ctx->classes[$classLc]->parentLc ?? null,
                    fn (string $classLc): bool => ($ctx->classes[$classLc] ?? null)?->isTrait ?? false,
                );
            }
            if (!isset($ctx->classes[$declaring])) {
                PseudoClassScope::fatalInGlobalScope('parent');
            }
            $parentLc = $ctx->classes[$declaring]->parentLc;
            if (null === $parentLc) {
                throw new \LogicException('parent:: used when class has no parent');
            }

            return $parentLc;
        }

        return $lcClass;
    }

    /**
     * True when the resolved method is static (or enum cases / lazy-ghost factory).
     */
    private static function isStaticMethod(ClassEntry $declaringClass, string $methodLc): bool
    {
        if ($declaringClass->isEnum && 'cases' === $methodLc) {
            return true;
        }
        if ($declaringClass->usesLazyGhostTrait && 'createlazyghost' === $methodLc) {
            return true;
        }
        $vis = $declaringClass->methodVisibility[$methodLc] ?? 0;
        if (($vis & \PHPCfg\Func::FLAG_STATIC) !== 0) {
            return true;
        }
        $func = $declaringClass->methods[$methodLc] ?? null;

        return $func instanceof Func\PHP && null !== $func->block->func
            && (($func->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) !== 0;
    }

    /**
     * First-class `Class::instanceMethod(...)` must Error at creation (zend_compile.c, #7465).
     * Closure::fromCallable without a compatible $this uses the same message as TypeError (#23771).
     */
    private static function assertStaticMethodForCallable(
        ClassEntry $declaringClass,
        string $methodLc,
        bool $fromCallableApi = false
    ): void {
        if (self::isStaticMethod($declaringClass, $methodLc)) {
            return;
        }
        $func = $declaringClass->methods[$methodLc] ?? null;
        $declaringName = $declaringClass->name;
        $declaredName = $declaringClass->methodNames[$methodLc] ?? $methodLc;
        if ($func instanceof Func\PHP && null !== $func->block->func && null !== $func->block->func->class) {
            $declaringName = $func->block->func->class->value;
            if (isset($declaringClass->methodNames[$methodLc])) {
                $declaredName = $declaringClass->methodNames[$methodLc];
            } elseif (isset($func->block->func->name)) {
                $declaredName = $func->block->func->name;
            }
        }
        $message = 'Non-static method '.$declaringName.'::'.$declaredName.'() cannot be called statically';
        if ($fromCallableApi) {
            throw new \TypeError(
                'Failed to create closure from callable: '
                .'non-static method '.$declaringName.'::'.$declaredName.'() cannot be called statically'
            );
        }

        throw new \Error($message);
    }

    /**
     * @return array{0: ClassEntry, 1: string}
     */
    private static function resolveStaticMethod(Context $ctx, string $lcClass, string $methodLc): array
    {
        $visited = [];
        while (!isset($visited[$lcClass])) {
            $visited[$lcClass] = true;
            if (!isset($ctx->classes[$lcClass])) {
                break;
            }
            $class = $ctx->classes[$lcClass];
            if (isset($class->methods[$methodLc])) {
                return [$class, $methodLc];
            }
            if (null === $class->parentLc) {
                break;
            }
            $lcClass = $class->parentLc;
        }

        throw new \LogicException("Call to undefined static method {$lcClass}::{$methodLc}()");
    }

    private static function classDisplayName(Context $ctx, ?string $classLc): ?string
    {
        if (null === $classLc || !isset($ctx->classes[$classLc])) {
            return null;
        }

        return $ctx->classes[$classLc]->name;
    }

    /**
     * Zend zend_closure_from_callable() visibility — TypeError, not call-site LogicException (#7416).
     *
     * @throws \TypeError when the callback is not accessible from the current scope
     */
    private static function assertMethodAccessibleForFromCallable(
        int $visibilityFlags,
        ?string $callerClassLc,
        string $declaringClassLc,
        string $declaringClassDisplay,
        string $methodName,
        ?callable $isSameOrSubclassOf = null,
        ?string $callerClassDisplay = null,
        bool $parentScopeAllows = false
    ): void {
        try {
            MethodVisibility::assertCallable(
                $visibilityFlags,
                $callerClassLc,
                $declaringClassLc,
                $declaringClassDisplay,
                $methodName,
                false,
                $isSameOrSubclassOf,
                $callerClassDisplay,
                $parentScopeAllows
            );
        } catch (\LogicException) {
            $kind = ($visibilityFlags & \PHPCfg\Func::FLAG_PRIVATE) !== 0 ? 'private' : 'protected';
            throw new \TypeError(
                "Failed to create closure from callable: cannot access {$kind} method {$declaringClassDisplay}::{$methodName}()"
            );
        }
    }

    private static function isClassSameOrSubclassOf(Context $ctx, string $classLc, string $ancestorLc): bool
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
