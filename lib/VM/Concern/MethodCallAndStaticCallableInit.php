<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Func;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\Variable;

/**
 * Instance/static method-call and callable init for the VM (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code initMethodCall} through
 * {@code implicitThisArgsForStaticInstanceCall} (php-src Zend/zend_object_handlers.c
 * zend_std_get_method / get_static_method_fallback; Zend/zend_execute.c
 * INIT_METHOD_CALL / INIT_STATIC_METHOD_CALL — #25669, #25670, #3273, #1858, #28050,
 * #18172 parameterized get hooks). Companion to {@see UserInvokeArrayAccessAndClosureCall}.
 * Concern trait — same namespace as parent so relative Frame / OpCode helpers resolve.
 * Move-only; no new C ABI.
 */
trait MethodCallAndStaticCallableInit
{
    protected function initMethodCall(
        Frame $frame,
        Variable $receiver,
        string $methodName,
        bool $objectCallInvoke = false
    ): ?Frame
    {
        $methodLc = strtolower($methodName);
        $object = $receiver->toObject();
        if ($object->lazyPending && 'marklazyobjectasinitialized' !== $methodLc) {
            $catchFrame = $this->ensureLazyObjectInitialized($object, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
        }
        $object = VM\LazyObjectSupport::getLazyInstance($object);
        if ($object !== $receiver->toObject()) {
            $receiver = new Variable(Variable::TYPE_OBJECT);
            $receiver->object($object);
        }
        if (null !== $object->closureState && '__invoke' === $methodLc) {
            $this->initClosureCall($frame, $object->closureState);

            return null;
        }
        if ('propertyisinitialized' === $methodLc) {
            $frame->call = new VM\PropertyIsInitializedHandler();
            $frame->callArgs = [$receiver];
            $frame->callArgEntries = [];

            return null;
        }
        $class = $object->class;
        try {
            [$declaringClass, $methodLc] = $this->resolveInstanceMethod($class, $methodLc);
        } catch (\LogicException $e) {
            $hookInit = $this->initParameterizedPropertyGetMethodCall($frame, $receiver, $methodName);
            if (true === $hookInit) {
                return null;
            }
            if ($hookInit instanceof Frame) {
                return $hookInit;
            }
            if (isset($class->methods['__call'])) {
                $frame->magicCallMethodName = $methodName;
                $frame->call = $class->methods['__call'];
                $frame->callArgs = [$receiver];
                $frame->callArgEntries = [];

                return null;
            }
            // Zend zend_std_get_method — __call is looked up on parents too (#24287 dual-it proxies).
            $magicCallClass = $this->findMagicCallClass(strtolower($class->name));
            if (null !== $magicCallClass && $magicCallClass !== $class) {
                $frame->magicCallMethodName = $methodName;
                $frame->call = $magicCallClass->methods['__call'];
                $frame->callArgs = [$receiver];
                $frame->callArgEntries = [];

                return null;
            }
            if (str_starts_with($e->getMessage(), 'Call to undefined method ')
                || str_starts_with($e->getMessage(), 'Call to undefined static method ')) {
                return $this->dispatchVmError(
                    "Call to undefined method {$class->name}::{$methodName}()",
                    $frame
                );
            }
            throw $e;
        }
        // Zend zend_check_private / early-bind: when the resolved method is private and the
        // calling scope differs, prefer the caller's same-name private if $obj is in that
        // class hierarchy (Zend/zend_object_handlers.c; #22928).
        $callerClassLc = $this->callerClassLc($frame);
        $declaringClass = $this->resolvePrivateInstanceMethodForScope(
            $declaringClass,
            $methodLc,
            $class,
            $callerClassLc
        );
        $vis = $declaringClass->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
        $callerDisplay = $this->callerScopeDisplay($frame, $callerClassLc);
        $declaredName = $declaringClass->methodNames[$methodLc] ?? $methodName;
        // `$obj(...)` object-call handler ignores __invoke visibility (zend_object_handlers.c, #26438).
        // Explicit `$obj->__invoke()` and `[$obj,'__invoke']()` still enforce it.
        $skipInvokeVisibility = $objectCallInvoke && '__invoke' === $methodLc;
        if (!$skipInvokeVisibility) {
            try {
                MethodVisibility::assertCallable(
                    $vis,
                    $callerClassLc,
                    strtolower($declaringClass->name),
                    $declaringClass->name,
                    $declaredName,
                    false,
                    fn (string $classLc, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLc, $ancestorLc),
                    $callerDisplay
                );
            } catch (\LogicException $e) {
                // Inaccessible private/protected instance → __call fallback (zend_std_get_method /
                // #25669, re-#146) — same shape as static get_static_method_fallback (#25670).
                if ($this->tryDispatchCall($frame, $receiver, $class, $methodName)) {
                    return null;
                }

                return $this->dispatchVmError($e->getMessage(), $frame);
            }
        }
        $frame->call = $declaringClass->methods[$methodLc];
        // Zend: `$obj->staticMethod($arg)` does not bind $obj as argument #1 (zend_execute.c;
        // #22288 DOMXPath::quote, DateTime::createFromFormat, user static methods).
        // Keep LSB from the receiver class via staticCallClass (static::class === get_class($obj)).
        // Exception: XMLReader::open/XML C methods still inspect EX(This) (#22630, re-#19330).
        $isStatic = (($vis & \PHPCfg\Func::FLAG_STATIC) !== 0)
            || $this->methodIsStatic($frame->call);
        if ($isStatic) {
            $frame->staticCallClass = $object->class->name;
            $frame->callArgs = $this->staticMethodKeepsInstanceThis($declaringClass, $methodLc)
                ? [$receiver]
                : [];
        } else {
            $frame->callArgs = [$receiver];
        }
        $frame->callArgEntries = [];
        $frame->builtinCalleeQualifiedMethod = $declaringClass->name.'::'.$declaredName;

        return null;
    }

    /**
     * PHP 8.4 parameterized get hooks: `$obj->prop($arg)` routes to get-hook method (#18172).
     *
     * @return true when handled|Frame on catchable error|null when not applicable
     */
    protected function initParameterizedPropertyGetMethodCall(Frame $frame, Variable $receiver, string $methodName): true|Frame|null
    {
        $object = $receiver->toObject();
        $propLc = strtolower($methodName);
        for ($class = $object->class; null !== $class; ) {
            foreach ($class->properties as $prop) {
                if (strtolower($prop->name) !== $propLc) {
                    continue;
                }
                if (null === $prop->getHookMethodLc || !$prop->getHookParameterized) {
                    return null;
                }
                $catchFrame = $this->enforcePropertyReadVisibility($object, $prop->name, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                $getLc = $prop->getHookMethodLc;
                if (!isset($class->methods[$getLc])) {
                    return null;
                }
                $func = $class->methods[$getLc];
                if (!$func instanceof Func\PHP) {
                    return null;
                }
                $frame->call = $func;
                $frame->callArgs = [$receiver];
                $frame->callArgEntries = [];
                $declaredName = $class->methodNames[$getLc] ?? $getLc;
                $frame->builtinCalleeQualifiedMethod = $class->name.'::'.$declaredName;
                $frame->propertyHookRawProperty = $prop->name;

                return true;
            }
            if (null === $class->parentLc) {
                break;
            }
            $class = $this->context->classes[$class->parentLc] ?? null;
        }

        return null;
    }

    protected function initStaticCallable(
        Frame $frame,
        string $callableName,
        bool $parentKeywordScope = false,
        bool $selfKeywordScope = false,
        bool $resolveScopeKeywords = true,
        bool $isDynamicCallable = false
    ): void {
        [$className, $methodName] = explode('::', $callableName, 2);
        $lcClass = $resolveScopeKeywords
            ? $this->resolveClassScopeName($className, $frame)
            : strtolower($className);
        if (!isset($this->context->classes[$lcClass])) {
            $this->context->autoloadClass($className);
        }
        if (!isset($this->context->classes[$lcClass])) {
            throw new \Error($this->classNotFoundMessage($className));
        }
        $class = $this->context->classes[$lcClass];
        // parent:: / self:: run the resolved implementation but keep the caller's LSB scope
        // (#12245 parent, #21983 self) — unlike a named ClassName::call which rebinds LSB.
        $frame->staticCallClass = ($parentKeywordScope || $selfKeywordScope)
            ? $this->lateStaticClassLc($frame)
            : $class->name;
        $methodLc = strtolower($methodName);
        if ($class->isEnum && 'cases' === $methodLc) {
            VM\EnumSupport::ensureBuiltinCasesMethod($class);
            $frame->call = $class->methods['cases'];
            $frame->callArgs = [];
            $frame->callArgEntries = [];

            return;
        }
        if ($class->usesLazyGhostTrait && 'createlazyghost' === $methodLc) {
            VM\LazyGhostTraitSupport::ensureBuiltinLazyGhostMethods($class);
            $frame->call = $class->methods['createlazyghost'];
            $frame->callArgs = [];
            $frame->callArgEntries = [];

            return;
        }
        if ($class->isEnum && null !== $class->backedType && ('from' === $methodLc || 'tryfrom' === $methodLc)) {
            $frame->call = new VM\EnumFromHandler($class, 'tryfrom' === $methodLc);
            $frame->callArgs = [];
            $frame->callArgEntries = [];

            return;
        }
        try {
            [$class, $methodLc] = $this->resolveStaticMethod($lcClass, $methodLc, $methodName);
            // Zend INIT_STATIC_METHOD_CALL: non-static Class::method() is allowed when
            // EX(This) is an object instanceof the called class (self::/static::/parent::
            // and compatible named Class:: from instance methods) (#28050, #1858).
            if ($isDynamicCallable || !$this->instanceThisAllowsNonStaticCall($frame, $lcClass)) {
                $this->assertMethodCallableStatically($class, $methodLc);
            }
        } catch (\LogicException $e) {
            // Missing __construct on a static/parent call is never __callStatic — Zend
            // zend_std_get_constructor / INIT_STATIC_METHOD_CALL (#25909).
            if ('__construct' === $methodLc) {
                throw new \LogicException('Cannot call constructor');
            }
            // Missing method → zend_std_get_static_method slow path → __callStatic (#3273).
            if ($this->tryDispatchCallStatic($frame, $lcClass, $methodName)) {
                return;
            }
            throw $e;
        }
        $vis = $class->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
        $callerClassLc = $this->callerClassLc($frame);
        $parentScopeAllows = false;
        if ($parentKeywordScope) {
            $parentScopeAllows = MethodVisibility::parentScopeAllows(
                $vis,
                $callerClassLc,
                $lcClass,
                strtolower($class->name),
                fn (string $classLc, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLc, $ancestorLc)
            );
        }
        $declaredName = $class->methodNames[$methodLc] ?? $methodName;
        $callerDisplay = $this->callerScopeDisplay($frame, $callerClassLc);
        try {
            MethodVisibility::assertCallable(
                $vis,
                $callerClassLc,
                strtolower($class->name),
                $class->name,
                $declaredName,
                $parentScopeAllows,
                fn (string $classLc, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLc, $ancestorLc),
                $callerDisplay
            );
        } catch (\LogicException $e) {
            // Inaccessible private/protected static → same __callStatic fallback as missing
            // methods (php-src get_static_method_fallback / #25670, re-#3273).
            if ($this->tryDispatchCallStatic($frame, $lcClass, $methodName)) {
                return;
            }
            throw $e;
        }
        $frame->call = $class->methods[$methodLc];
        $frame->callArgs = $this->callArgsForStaticMethod($frame, $lcClass, $frame->call, $parentKeywordScope);
        $frame->callArgEntries = [];
        $frame->builtinCalleeQualifiedMethod = $class->name.'::'.$declaredName;
    }

    /**
     * Bind an instance call to __call when present (Zend zend_std_get_method fallback).
     *
     * Used for inaccessible private/protected instance methods (#25669, re-#146). Missing
     * methods already dispatch __call in initMethodCall before visibility checks.
     *
     * @return bool true when the frame was bound to __call
     */
    private function tryDispatchCall(
        Frame $frame,
        Variable $receiver,
        ClassEntry $class,
        string $methodName
    ): bool {
        $magicClass = $this->findMagicCallClass(strtolower($class->name));
        if (null === $magicClass) {
            return false;
        }
        $frame->magicCallMethodName = $methodName;
        $vis = $magicClass->methodVisibility['__call'] ?? \PHPCfg\Func::FLAG_PUBLIC;
        $callerClassLc = $this->callerClassLc($frame);
        MethodVisibility::assertCallable(
            $vis,
            $callerClassLc,
            strtolower($magicClass->name),
            $magicClass->name,
            '__call'
        );
        $frame->call = $magicClass->methods['__call'];
        $frame->callArgs = [$receiver];
        $frame->callArgEntries = [];

        return true;
    }

    /**
     * Bind a static call to __callStatic when present (Zend get_static_method_fallback).
     *
     * Used for both missing methods (#3273) and inaccessible private/protected statics (#25670).
     * Non-public `__callStatic` still dispatches — Zend warns at declaration then invokes
     * the trampoline without a normal visibility check (#26437).
     *
     * @return bool true when the frame was bound to __callStatic
     */
    private function tryDispatchCallStatic(Frame $frame, string $lcClass, string $methodName): bool
    {
        $magicClass = $this->findMagicCallStaticClass($lcClass);
        if (null === $magicClass) {
            return false;
        }
        $frame->magicCallMethodName = $methodName;
        // Do not MethodVisibility::assertCallable — magic trampoline ignores declaration
        // visibility (zend_std_get_static_method / #26437). Direct C::__callStatic(...) still
        // goes through the normal static path first; inaccessible → this fallback.
        $frame->call = $magicClass->methods['__callstatic'];
        $frame->callArgs = [];
        $frame->callArgEntries = [];

        return true;
    }

    /**
     * @return list<Variable>
     */
    protected function callArgsForStaticMethod(
        Frame $frame,
        string $resolvedLc,
        Func $call,
        bool $parentKeywordScope = false
    ): array {
        $args = $this->implicitThisArgsForStaticInstanceCall($frame, $call);
        if ([] !== $args) {
            return $args;
        }
        if ($parentKeywordScope || $this->isDirectParentScopeInstanceCall($frame, $resolvedLc)) {
            $thisVar = $this->resolveCallerThis($frame);
            if (null !== $thisVar) {
                return [$thisVar];
            }
        }

        return [];
    }

    protected function isClassSameOrSubclassOf(string $classLc, string $ancestorLc): bool
    {
        $current = $classLc;
        while (true) {
            if ($current === $ancestorLc) {
                return true;
            }
            if (!isset($this->context->classes[$current])) {
                return false;
            }
            $parentLc = $this->context->classes[$current]->parentLc;
            if (null === $parentLc) {
                return false;
            }
            $current = $parentLc;
        }
    }

    protected function resolveCallerThis(Frame $frame): ?Variable
    {
        if (null === $frame->block->func) {
            return null;
        }
        if (($frame->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) {
            return null;
        }
        $isClosure = (($frame->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE) !== 0;
        if (!$isClosure && null === $frame->block->func->class) {
            return null;
        }
        $idx = $frame->block->slotIndexForVariableName('this');
        if (null !== $idx && isset($frame->scope[$idx])) {
            return $frame->scope[$idx];
        }
        $fromScope = $frame->block->findVariableByRuntimeName('this', $frame);
        if (null !== $fromScope) {
            return $fromScope;
        }
        if ($isClosure) {
            $state = $frame->closureCall ?? $frame->pendingClosureInvoke;
            if (null !== $state && null !== $state->boundThis) {
                return $state->boundThis;
            }
        }
        if (!empty($frame->calledArgs)) {
            $receiver = $frame->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_OBJECT === $receiver->type) {
                return $frame->calledArgs[0];
            }
        }
        if (!empty($frame->callArgs)) {
            $receiver = $frame->callArgs[0]->resolveIndirect();
            if (Variable::TYPE_OBJECT === $receiver->type) {
                return $frame->callArgs[0];
            }
        }

        return null;
    }

    /**
     * Non-parent static calls to instance methods pass $this from the caller (#1858).
     *
     * @return list<Variable>
     */
    protected function implicitThisArgsForStaticInstanceCall(Frame $frame, Func $call): array
    {
        if (!$call instanceof Func\PHP) {
            return [];
        }
        $callee = $call->block;
        if (null === $callee->func || null === $callee->func->class) {
            return [];
        }
        if (($callee->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) {
            return [];
        }
        $thisVar = $this->resolveCallerThis($frame);
        if (null === $thisVar) {
            return [];
        }

        return [$thisVar];
    }
}
