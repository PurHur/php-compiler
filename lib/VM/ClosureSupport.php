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
     * @param null|'parent'|'self' $scope  scoped FCC resolve class (#17655, #26630); null = virtual
     * @param bool                 $fromCallableApi true for Closure::fromCallable() (TypeError); false for FCC `$name(...)` (Error)
     */
    public static function fromCallable(
        Context $ctx,
        Frame $frame,
        Variable $callable,
        ?string $scope = null,
        bool $fromCallableApi = false
    ): ObjectEntry {
        $callable = $callable->resolveIndirect();
        if (Variable::TYPE_OBJECT === $callable->type) {
            $state = $callable->toObject()->closureState;
            if (null !== $state) {
                return $callable->toObject();
            }
            $invokeState = self::tryFromInvokableObject($ctx, $frame, $callable, $fromCallableApi);
            if (null !== $invokeState) {
                return self::wrapState($ctx, $invokeState);
            }

            // Closure::fromCallable($obj): Zend TypeError (not Error) for non-invokable (#26457).
            if ($fromCallableApi) {
                throw new \TypeError(self::fromCallableNoArrayOrStringMessage());
            }

            throw new \Error(
                'Object of type '.self::valueTypeName($callable).' is not callable'
            );
        }
        if (Variable::TYPE_ENUM_CASE === $callable->type) {
            if ($fromCallableApi) {
                throw new \TypeError(self::fromCallableNoArrayOrStringMessage());
            }

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
                return self::wrapState(
                    $ctx,
                    self::fromStaticStringCallable($ctx, $frame, $name, $fromCallableApi, $scope)
                );
            }

            return self::wrapState($ctx, self::fromFunctionName($ctx, $name, $fromCallableApi));
        }
        if (Variable::TYPE_ARRAY === $callable->type) {
            return self::wrapState($ctx, self::fromArrayCallable($ctx, $frame, $callable, $scope, $fromCallableApi));
        }

        // Scalars / resources: Closure::fromCallable → TypeError; FCC `$c(...)` → catchable Error (#26457, #28937).
        if ($fromCallableApi) {
            throw new \TypeError(self::fromCallableNoArrayOrStringMessage());
        }

        throw new \Error(CallableCheck::scalarNotCallableMessage($callable));
    }

    /** php-src zend_closures.c — invalid scalar/object for Closure::fromCallable (#26457). */
    private static function fromCallableNoArrayOrStringMessage(): string
    {
        return 'Failed to create closure from callable: no array or string given';
    }

    /** php-src zend_closures.c — Closure::fromCallable TypeError prefix (#26457). */
    private static function fromCallableFailedMessage(string $detail): string
    {
        return 'Failed to create closure from callable: '.$detail;
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
        $scopeClass = self::resolveScopeClass(
            $newScope,
            $context,
            $state->boundScopeClass
        );
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
        // Re-derive called_scope from new $this (or scope) at invoke (#25793).
        $bound->boundCalledScopeClass = null;

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
        // php-src ZEND_METHOD(Closure, call): zend_valid_closure_binding fails → Warning + null
        // (same text as bindTo; #25984 / #22423). Do not throw Error.
        if ($state->isStaticClosure()) {
            self::warnCannotBindInstanceToStaticClosure($ctx, $frame);

            $null = new Variable();
            $null->null();

            return $null;
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
        $invokeState->boundCalledScopeClass = null;
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

            // Zend preserves source spelling in FCC Error messages (#26690, zend_execute_API.c).
            throw new \Error('Call to undefined function '.$name.'()');
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
        bool $fromCallableApi = false,
        ?string $scope = null
    ): ClosureState {
        [$className, $methodName] = explode('::', $callable, 2);
        $lcClass = self::resolveClassScopeName($className, $frame, $ctx);
        if (!isset($ctx->classes[$lcClass])) {
            $ctx->autoloadClass($className);
        }
        if (!isset($ctx->classes[$lcClass])) {
            if ($fromCallableApi) {
                throw new \TypeError(
                    self::fromCallableFailedMessage('class "'.$className.'" not found')
                );
            }

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
        try {
            [$class, $methodLc] = self::resolveStaticMethod($ctx, $lcClass, $methodLc, $methodName);
        } catch (\Error $e) {
            // Missing method + __callStatic → fake Closure (zend_closures.c / #25757).
            $magicState = self::tryMagicStaticCallable($ctx, $namedClassLc, $namedClass->name, $methodName);
            if (null !== $magicState) {
                return $magicState;
            }
            if ($fromCallableApi) {
                throw new \TypeError(
                    self::fromCallableFailedMessage(
                        'class '.$namedClass->name.' does not have a method "'.$methodName.'"'
                    )
                );
            }
            throw $e;
        }
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
                            $callerDisplay,
                            false,
                            true
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
            $callerDisplay,
            false,
            $fromCallableApi
        );

        // Named class is called-scope for LSB (zend_closures.c): Closure::fromCallable([B::class, 'foo'])
        // / 'B::foo' / FCC B::foo(...) must resolve static:: to B, not declaring class A (#24431).
        // self::/parent:: FCC: method resolve uses declaring/parent, but called_scope stays the
        // creation frame's late-static class (B::viaSelf → self::foo keeps B) (#27835).
        $state = ClosureState::fromWrappedFunc($class->methods[$methodLc]);
        $state->boundScopeClass = $namedClass->name;
        self::applyScopedStaticFccCalledScope($state, $frame, $scope, $className);

        return $state;
    }

    /**
     * Preserve creation-time LSB for self::/parent:: static FCC (#27835, zend_closures.c).
     *
     * Named Class::method FCC freezes called_scope to that class; self/parent keep the
     * enclosing frame's called_scope (distinct from scope/ce used for method resolve).
     */
    private static function applyScopedStaticFccCalledScope(
        ClosureState $state,
        Frame $frame,
        ?string $scope,
        string $originalClassName
    ): void {
        $lc = strtolower($scope ?? $originalClassName);
        if ('self' !== $lc && 'parent' !== $lc) {
            return;
        }
        if (null !== $frame->calledClass && '' !== $frame->calledClass) {
            $state->boundCalledScopeClass = $frame->calledClass;
        }
    }

    private static function fromArrayCallable(
        Context $ctx,
        Frame $frame,
        Variable $callable,
        ?string $scope = null,
        bool $fromCallableApi = false
    ): ClosureState {
        $table = $callable->toArray();
        $idx0 = new Variable(Variable::TYPE_INTEGER);
        $idx0->int(0);
        $idx1 = new Variable(Variable::TYPE_INTEGER);
        $idx1->int(1);
        $has0 = $table->keyExists($idx0);
        $has1 = $table->keyExists($idx1);
        if (!$has0 || !$has1) {
            if ($fromCallableApi) {
                // zend_is_callable_ex: wrong arity vs missing 0/1 keys (#26457).
                if (2 !== $table->getNumElements()) {
                    throw new \TypeError(
                        self::fromCallableFailedMessage(
                            'array callback must have exactly two members'
                        )
                    );
                }

                throw new \TypeError(
                    self::fromCallableFailedMessage(
                        'array callback has to contain indices 0 and 1'
                    )
                );
            }

            // FCC `$c(...)` — catchable Error, not Closure::fromCallable LogicException (#28937).
            throw new \Error(CallableCheck::arrayCallbackTwoElementsMessage());
        }
        $receiver = $table->findVariable($idx0, false)->resolveIndirect();
        $methodVar = $table->findVariable($idx1, false)->resolveIndirect();
        // Zend checks receiver kind before method kind (zend_is_callable_ex, #26457).
        $receiverOk = Variable::TYPE_OBJECT === $receiver->type
            || Variable::TYPE_ENUM_CASE === $receiver->type
            || Variable::TYPE_STRING === $receiver->type
            || null !== $scope;
        if (!$receiverOk) {
            if ($fromCallableApi) {
                throw new \TypeError(
                    self::fromCallableFailedMessage(
                        'first array member is not a valid class name or object'
                    )
                );
            }

            throw new \Error(CallableCheck::firstArrayMemberInvalidMessage());
        }
        if (Variable::TYPE_STRING !== $methodVar->type) {
            if ($fromCallableApi) {
                throw new \TypeError(
                    self::fromCallableFailedMessage('second array member is not a valid method')
                );
            }

            throw new \Error(CallableCheck::secondArrayMemberInvalidMessage());
        }
        $methodName = $methodVar->toString();
        if (Variable::TYPE_OBJECT === $receiver->type) {
            return self::fromInstanceMethodCallable(
                $ctx,
                $frame,
                $receiver,
                $methodName,
                $scope,
                $fromCallableApi
            );
        }
        if (Variable::TYPE_ENUM_CASE === $receiver->type) {
            return self::fromInstanceMethodCallable(
                $ctx,
                $frame,
                EnumCaseSupport::receiverForInstanceMethod($receiver),
                $methodName,
                null,
                $fromCallableApi
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
        // `parent::`/`self::` from a static method: compiler may still emit [$this, m] with a
        // null/unset $this (#26252, #26630). Resolve as scoped static callable (Zend Error if non-static).
        if (null !== $scope) {
            $scopeLc = self::resolveClassScopeName($scope, $frame, $ctx);
            if (!isset($ctx->classes[$scopeLc])) {
                throw new \LogicException($scope.':: used when class scope is unresolved');
            }

            return self::fromStaticStringCallable(
                $ctx,
                $frame,
                $ctx->classes[$scopeLc]->name.'::'.$methodName,
                $fromCallableApi,
                $scope
            );
        }

        if ($fromCallableApi) {
            throw new \TypeError(
                'Closure::fromCallable(): Argument #1 ($callback) must be a valid callback'
            );
        }

        throw new \Error(CallableCheck::firstArrayMemberInvalidMessage());
    }

    /** `(new C)(...)` / Closure::fromCallable($obj) when $obj defines __invoke (zend_closures.c, #9605). */
    private static function tryFromInvokableObject(
        Context $ctx,
        Frame $frame,
        Variable $callable,
        bool $fromCallableApi = false
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

        return self::fromInstanceMethodCallable($ctx, $frame, $callable, '__invoke', null, $fromCallableApi);
    }

    private static function fromInstanceMethodCallable(
        Context $ctx,
        Frame $frame,
        Variable $receiver,
        string $methodName,
        ?string $scope = null,
        bool $fromCallableApi = false
    ): ClosureState {
        $object = $receiver->toObject();
        $methodLc = strtolower($methodName);
        $class = $object->class;
        $resolveFromLc = strtolower($class->name);
        $boundScopeClass = $class->name;
        $scoped = null !== $scope;
        if ($scoped) {
            $resolveFromLc = self::resolveClassScopeName($scope, $frame, $ctx);
            if (!isset($ctx->classes[$resolveFromLc])) {
                throw new \LogicException($scope.':: used when class scope is unresolved');
            }
            $boundScopeClass = $ctx->classes[$resolveFromLc]->name;
        }
        // Missing method + __call → fake Closure that magic-dispatches on invoke (#25757).
        if (!$ctx->runtime->vm->hasInstanceMethod($ctx->classes[$resolveFromLc] ?? $class, $methodName)) {
            $magicState = self::tryMagicInstanceCallable(
                $ctx,
                $receiver,
                $methodName,
                $resolveFromLc,
                $boundScopeClass
            );
            if (null !== $magicState) {
                return $magicState;
            }
            if ($fromCallableApi) {
                $displayClass = ($ctx->classes[$resolveFromLc] ?? $class)->name;

                throw new \TypeError(
                    self::fromCallableFailedMessage(
                        'class '.$displayClass.' does not have a method "'.$methodName.'"'
                    )
                );
            }
        }
        try {
            [$declaringClass, $methodLc] = self::resolveStaticMethod($ctx, $resolveFromLc, $methodLc, $methodName);
        } catch (\Error $e) {
            if ($fromCallableApi) {
                $displayClass = ($ctx->classes[$resolveFromLc] ?? $class)->name;

                throw new \TypeError(
                    self::fromCallableFailedMessage(
                        'class '.$displayClass.' does not have a method "'.$methodName.'"'
                    )
                );
            }

            throw $e;
        }
        $vis = $declaringClass->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
        $callerClassLc = self::callerClassLc($frame);
        $callerDisplay = self::classDisplayName($ctx, $callerClassLc);
        $parentScopeAllows = false;
        if ($scoped) {
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
            $parentScopeAllows,
            $fromCallableApi
        );
        $boundThis = new Variable();
        $boundThis->copyFrom($receiver);
        $state = ClosureState::fromMethodCallable($declaringClass->methods[$methodLc], $boundThis, $methodName);
        $state->boundScopeClass = $boundScopeClass;
        if ($scoped) {
            // Invoke the resolved Func directly — virtual dispatch would hit child overrides (#17655, #26630).
            $state->methodReceiver = null;
            $state->methodName = null;
            $state->boundThis = $boundThis;
        }

        return $state;
    }

    /**
     * Bind FCC / fromCallable to __call when the named instance method is missing (#25757).
     *
     * php-src: Zend/zend_closures.c + zend_std_get_method fallback.
     */
    private static function tryMagicInstanceCallable(
        Context $ctx,
        Variable $receiver,
        string $methodName,
        string $resolveFromLc,
        string $boundScopeClass
    ): ?ClosureState {
        $magicClass = self::findMagicCallClass($ctx, $resolveFromLc);
        if (null === $magicClass) {
            return null;
        }
        $boundThis = new Variable();
        $boundThis->copyFrom($receiver);
        $state = ClosureState::fromMethodCallable(
            $magicClass->methods['__call'],
            $boundThis,
            $methodName
        );
        $state->boundScopeClass = $boundScopeClass;

        return $state;
    }

    /**
     * Bind FCC / fromCallable to __callStatic when the named static method is missing (#25757).
     */
    private static function tryMagicStaticCallable(
        Context $ctx,
        string $lcClass,
        string $scopeClassName,
        string $methodName
    ): ?ClosureState {
        $magicClass = self::findMagicCallStaticClass($ctx, $lcClass);
        if (null === $magicClass) {
            return null;
        }

        return ClosureState::fromMagicStaticCallable(
            $magicClass->methods['__callstatic'],
            $methodName,
            $scopeClassName
        );
    }

    /** @see \PHPCompiler\VM::findMagicCallClass */
    private static function findMagicCallClass(Context $ctx, string $lcClass): ?ClassEntry
    {
        $visited = [];
        while (!isset($visited[$lcClass])) {
            $visited[$lcClass] = true;
            if (!isset($ctx->classes[$lcClass])) {
                break;
            }
            $class = $ctx->classes[$lcClass];
            if (isset($class->methods['__call'])) {
                return $class;
            }
            if (null === $class->parentLc) {
                break;
            }
            $lcClass = $class->parentLc;
        }

        return null;
    }

    /** @see \PHPCompiler\VM::findMagicCallStaticClass */
    private static function findMagicCallStaticClass(Context $ctx, string $lcClass): ?ClassEntry
    {
        $visited = [];
        while (!isset($visited[$lcClass])) {
            $visited[$lcClass] = true;
            if (!isset($ctx->classes[$lcClass])) {
                break;
            }
            $class = $ctx->classes[$lcClass];
            if (isset($class->methods['__callstatic'])) {
                return $class;
            }
            if (null === $class->parentLc) {
                break;
            }
            $lcClass = $class->parentLc;
        }

        return null;
    }

    /**
     * Resolve bind/bindTo scope class (php-src Zend/zend_closures.c).
     *
     * Omitted $newScope and the string alias "static" keep the closure's prior
     * bound scope — they must not adopt get_class($newThis) (#24244).
     * Explicit null unbinds scope (#10097).
     */
    private static function resolveScopeClass(
        ?Variable $newScope,
        string $context = 'Closure::bindTo()',
        ?string $priorScopeClass = null
    ): ?string {
        $scopeArg = 'Closure::bind()' === $context ? '#3 ($newScope)' : '#2 ($newScope)';
        if (null === $newScope) {
            // Scope argument omitted — do not change the scope by default.
            return $priorScopeClass;
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
            if (ClosureBindJitHelper::resolveStaticScopeAlias($scope)) {
                return $priorScopeClass;
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
    private static function resolveStaticMethod(
        Context $ctx,
        string $lcClass,
        string $methodLc,
        ?string $displayMethodName = null
    ): array {
        $requestedLc = $lcClass;
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

        // Zend zend_execute_API.c — catchable Error (no "static" token); preserve casing (#27921, #28003).
        $declClass = $ctx->classes[$requestedLc] ?? null;
        $classDisplay = null !== $declClass ? $declClass->name : $requestedLc;
        $methodDisplay = $displayMethodName ?? $methodLc;
        throw new \Error("Call to undefined method {$classDisplay}::{$methodDisplay}()");
    }

    private static function classDisplayName(Context $ctx, ?string $classLc): ?string
    {
        if (null === $classLc || !isset($ctx->classes[$classLc])) {
            return null;
        }

        return $ctx->classes[$classLc]->name;
    }

    /**
     * Visibility for Closure::fromCallable() vs first-class callable `$obj->m(...)` (#7416, #25689).
     *
     * php-src: zend_closures.c — fromCallable → TypeError "Failed to create closure…"
     * php-src: zend_object_handlers.c / FCC — same Error wording as a direct inaccessible call.
     *
     * @throws \TypeError when $fromCallableApi and the callback is not accessible
     * @throws \Error when FCC ($fromCallableApi false) and the method is not accessible
     */
    private static function assertMethodAccessibleForFromCallable(
        int $visibilityFlags,
        ?string $callerClassLc,
        string $declaringClassLc,
        string $declaringClassDisplay,
        string $methodName,
        ?callable $isSameOrSubclassOf = null,
        ?string $callerClassDisplay = null,
        bool $parentScopeAllows = false,
        bool $fromCallableApi = false
    ): void {
        try {
            MethodVisibility::assertCallable(
                $visibilityFlags,
                $callerClassLc,
                $declaringClassLc,
                $declaringClassDisplay,
                $methodName,
                $parentScopeAllows,
                $isSameOrSubclassOf,
                $callerClassDisplay,
                false
            );
        } catch (\LogicException $e) {
            if ($fromCallableApi) {
                $kind = ($visibilityFlags & \PHPCfg\Func::FLAG_PRIVATE) !== 0 ? 'private' : 'protected';
                throw new \TypeError(
                    "Failed to create closure from callable: cannot access {$kind} method {$declaringClassDisplay}::{$methodName}()"
                );
            }
            // FCC: surface the same Error text as a direct private/protected call (#25689).
            throw new \Error($e->getMessage());
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
