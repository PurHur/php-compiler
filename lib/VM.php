<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler;

require_once __DIR__.'/OpCodeNames.php';

use PHPCompiler\BuiltinByRefParams;
use PHPCompiler\Compiler\AttributeNames;
use PHPCompiler\Compiler\NoDiscardMetadata;
use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\Func;
use PHPCompiler\ext\dom\VmDomCollectionDimension;
use PHPCompiler\ext\intl\VmResourceBundle;
use PHPCompiler\ext\standard\VmEval;
use PHPCompiler\ext\standard\VmForwardStaticCall;
use PHPCompiler\ext\standard\VmIteratorWalk;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\ext\spl\ArrayObjectBuiltin;
use PHPCompiler\ext\spl\RecursiveArrayIteratorBuiltin;
use PHPCompiler\ext\spl\SplArrayStorage;
use PHPCompiler\VM\ForeachIterator;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\CastSupport;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\DnfCheck;
use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\CycleCollector;
use PHPCompiler\VM\DateIntervalSupport;
use PHPCompiler\VM\DatePeriodSupport;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\EnumCaseEntry;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\FiberState;
use PHPCompiler\VM\GeneratorState;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\CallableCheck;
use PHPCompiler\VM\IterableCheck;
use PHPCompiler\VM\NamedArgs;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ObjectLifetime;
use PHPCompiler\VM\ObjectPropertyIterator;
use PHPCompiler\VM\WeakMapIterator;
use PHPCompiler\VM\WeakRefSupport;
use PHPCompiler\VM\ReferencableCheck;
use PHPCompiler\VM\ReflectionPropertyHookSupport;
use PHPCompiler\VM\ScriptExit;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\TraitCompositionConflictMessage;
use PHPCompiler\VM\TypedPropertyReadSignal;
use PHPCompiler\VM\VmIncDec;
use PHPCompiler\VM\VmVarFetch;
use PHPCompiler\VM\VmIsset;
use PHPCompiler\VM\WeakRefRegistry;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

class VM {
    const SUCCESS = 1;
    const FAILURE = 2;

    private static ?self $running = null;

    /** Frame executing the current opcode (property hook ref read/write, #6426). */
    private ?Frame $executingFrame = null;

    /** Active builtin handler while {@see executeInternalHandler} bridges a throw (#11677). */
    private ?Frame $builtinHandlerFrameForTrace = null;

    /** @internal Active VM during runFrames (#3429 typed property errors). */
    public static function running(): ?self
    {
        return self::$running;
    }

    /** Builtin handler frame while {@see executeInternalHandler} runs (#16409). */
    public function builtinHandlerFrame(): ?Frame
    {
        return $this->builtinHandlerFrameForTrace;
    }

    /**
     * Named locals, dynamic locals, globals, and in-flight builtin args — not compiler temps (#14103).
     *
     * @param callable(Variable): void $visitVar
     */
    public function visitNamedStrongRefRoots(callable $visitVar): void
    {
        if (null !== $this->executingFrame) {
            $frame = $this->executingFrame;
            if (null !== $frame->closureCall || null !== $frame->parent) {
                self::visitFrameNamedStrongRefRoots($frame, $visitVar);
            }
        }
        if (null !== $this->builtinHandlerFrameForTrace) {
            foreach ($this->builtinHandlerFrameForTrace->calledArgs as $arg) {
                $visitVar($arg);
            }
        }
        foreach ($this->context->runStackFrames() as $frame) {
            self::visitFrameNamedStrongRefRoots($frame, $visitVar);
        }
        $this->context->visitGlobalVariables($visitVar);
    }

    /** @param callable(Variable): void $visitVar */
    private static function visitFrameNamedStrongRefRoots(Frame $frame, callable $visitVar): void
    {
        if (null !== $frame->block) {
            foreach ($frame->block->eachNamedScopeSlot() as [, $slot]) {
                if (isset($frame->scope[$slot])) {
                    $visitVar($frame->scope[$slot]);
                }
            }
        }
        foreach ($frame->dynamicLocals as $var) {
            $visitVar($var);
        }
    }

    /**
     * Visit slots that may strongly retain weak-ref targets — active opcode frame,
     * in-flight builtin args, suspended callers, globals (#13923).
     *
     * @param callable(Variable): void $visitVar
     */
    public function visitStrongRefRoots(callable $visitVar, bool $includeBuiltinHandler = true): void
    {
        if (null !== $this->executingFrame) {
            $frame = $this->executingFrame;
            // Script-root scope mirrors globals — visit globals only (#13474, #13923).
            if (null !== $frame->closureCall || null !== $frame->parent) {
                CycleCollector::markFrameRoots($frame, $visitVar);
            }
        }
        if ($includeBuiltinHandler && null !== $this->builtinHandlerFrameForTrace) {
            CycleCollector::markFrameRoots($this->builtinHandlerFrameForTrace, $visitVar, false);
        }
        foreach ($this->context->runStackFrames() as $frame) {
            CycleCollector::markFrameRoots($frame, $visitVar);
        }
        $this->context->visitGlobalVariables($visitVar);
    }

    /** Generator body suspended at `yield` (issue #167). */
    const GENERATOR_YIELD = 3;

    /** Fiber callback suspended at Fiber::suspend() (issue #3130). */
    const FIBER_SUSPEND = 4;

    public Context $context;

    public function __construct(Context $context) {
        $this->context = $context;
    }

    public function run(Block $block): int {
        ObjectLifetime::setVm($this);
        CycleCollector::captureRequestBaseline();
        try {
            if (!is_null($block->handler)) {
                $frame = $block->getFrame($this->context);
                $this->seedScriptPath($frame);
                $block->handler->execute($frame);

                return self::SUCCESS;
            }

            $frame = $block->getFrame($this->context);
            $this->seedScriptPath($frame);
            $frame->vmContext = $this->context;
            $this->context->executionLimits->begin();
            $this->context->push($frame);

            $result = $this->runFrames();
            if ('' !== $frame->scriptPath) {
                $this->context->scriptStack->pop();
            }

            return $result;
        } finally {
            ObjectLifetime::runShutdownDestructors();
            ObjectLifetime::clearVm();
        }
    }

    /**
     * Invoke a user-defined PHP function from a VM builtin (isolated run stack).
     */
    public function invokePhpFunction(Func\PHP $func, Variable ...$args): Variable
    {
        if ($this->context->coercingObjectToString) {
            return $this->invokePhpFunctionForCoercion($func, ...$args);
        }

        return $this->invokePhpFunctionOnStack($func, ...$args);
    }

    /**
     * Invoke user PHP on an isolated run stack — serialize/unserialize magic hooks (#12069).
     *
     * Prevents outer user catch handlers from absorbing __wakeup/__unserialize throws while
     * nested inside a builtin execute() call.
     */
    public function invokePhpFunctionIsolated(Func\PHP $func, Variable ...$args): Variable
    {
        $savedStack = $this->context->swapRunStack(null);
        $savedTryHandlers = $this->context->activeTryHandlerFrames;
        $this->context->activeTryHandlerFrames = [];
        $this->context->isolatedPhpFunctionInvoke = true;
        try {
            return $this->invokePhpFunctionOnStack($func, ...$args);
        } finally {
            $this->context->isolatedPhpFunctionInvoke = false;
            $this->context->activeTryHandlerFrames = $savedTryHandlers;
            $this->context->swapRunStack($savedStack);
        }
    }

    /**
     * Isolated-stack invoke that keeps outer try/catch visible (#25911).
     *
     * Used for property magic (__get/__set/__isset/__unset): Zend delivers throws from
     * zend_std_read_property / write / has / unset to the caller's try/catch
     * (Zend/zend_object_handlers.c). Clearing handlers (as {@see invokePhpFunctionIsolated}
     * does for serialize hooks) made those throws look uncaught.
     *
     * {@see Context::$deferBuiltinCallbackCatchToOuterRunFrames} forces a
     * {@see VM\BuiltinCallbackCatchRedirect} so the catch resumes on the outer runFrames
     * loop — not inside this nested stack.
     */
    public function invokePhpFunctionIsolatedCatchable(Func\PHP $func, Variable ...$args): Variable
    {
        $savedStack = $this->context->swapRunStack(null);
        $prevDefer = $this->context->deferBuiltinCallbackCatchToOuterRunFrames;
        $this->context->deferBuiltinCallbackCatchToOuterRunFrames = true;
        $this->context->isolatedPhpFunctionInvoke = true;
        try {
            return $this->invokePhpFunctionOnStack($func, ...$args);
        } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
            throw $redirect;
        } finally {
            $this->context->isolatedPhpFunctionInvoke = false;
            $this->context->deferBuiltinCallbackCatchToOuterRunFrames = $prevDefer;
            $this->context->swapRunStack($savedStack);
        }
    }

    /**
     * Invoke a user function with positional/named call arg entries (call_user_func forwarding, #10637).
     *
     * @param list<array{0: string, 1?: mixed, 2?: Variable}> $entries
     */
    public function invokePhpFunctionWithArgEntries(Func\PHP $func, array $entries): Variable
    {
        if ($this->context->coercingObjectToString) {
            throw new \LogicException(
                'invokePhpFunctionWithArgEntries() is not supported during object-to-string coercion'
            );
        }
        $resolved = NamedArgs::resolve(
            $entries,
            $func->block->paramNames,
            $func->block->variadicParamIndex,
            $func->block->func?->name ?? null
        );
        ksort($resolved);

        // Keep sparse keys so omitted optionals hit RECV defaults (php-src-strict / #23388).
        return $this->invokePhpFunctionWithCalledArgs($func, $resolved);
    }

    /**
     * Invoke a user PHP function with a possibly sparse calledArgs map (named optionals, #23388).
     *
     * @param array<int, Variable> $calledArgs
     */
    public function invokePhpFunctionWithCalledArgs(Func\PHP $func, array $calledArgs): Variable
    {
        if ($this->context->coercingObjectToString) {
            return $this->invokePhpFunctionForCoercionWithCalledArgs($func, $calledArgs);
        }

        return $this->invokePhpFunctionOnStackWithCalledArgs($func, $calledArgs);
    }

    /**
     * Isolated stack variant of {@see invokePhpFunctionWithCalledArgs()} (Reflection / builtins).
     *
     * @param array<int, Variable> $calledArgs
     */
    public function invokePhpFunctionIsolatedWithCalledArgs(Func\PHP $func, array $calledArgs): Variable
    {
        $savedStack = $this->context->swapRunStack(null);
        $savedTryHandlers = $this->context->activeTryHandlerFrames;
        $this->context->activeTryHandlerFrames = [];
        $this->context->isolatedPhpFunctionInvoke = true;
        try {
            return $this->invokePhpFunctionOnStackWithCalledArgs($func, $calledArgs);
        } finally {
            $this->context->isolatedPhpFunctionInvoke = false;
            $this->context->activeTryHandlerFrames = $savedTryHandlers;
            $this->context->swapRunStack($savedStack);
        }
    }

    /**
     * @param Variable ...$args
     */
    private function invokePhpFunctionOnStack(Func\PHP $func, ...$args): Variable
    {
        return $this->invokePhpFunctionOnStackWithCalledArgs($func, $args);
    }

    /**
     * @param array<int, Variable> $args
     */
    private function invokePhpFunctionOnStackWithCalledArgs(Func\PHP $func, array $args): Variable
    {
        if ($func->block->isGenerator) {
            $state = new GeneratorState($this, $func, $args);
            $out = new Variable();
            $out->object($state->wrapObject());

            return $out;
        }

        $child = $func->getFrame($this->context, null);
        $child->calledArgs = $args;
        if (
            [] !== $args
            && array_key_exists(0, $args)
            && null !== $func->block->func
            && null !== $func->block->func->class
            && !(($func->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC)
        ) {
            $thisIdx = $func->block->slotIndexForVariableName('this');
            if (null !== $thisIdx) {
                if (!isset($child->scope[$thisIdx])) {
                    $child->scope[$thisIdx] = new Variable();
                }
                // copyInto scope slot; assigning calledArgs[0] directly breaks $this writes (#4772).
                $child->scope[$thisIdx]->copyFrom($args[0]);
            }
        }
        $out = new Variable();
        $child->returnVar = $out;
        $this->context->push($child);
        $result = $this->runFrames();
        if (self::SUCCESS !== $result) {
            throw new \LogicException('User function invocation failed in this compiler build');
        }
        if ($this->context->magicMethodThrowHandled) {
            $this->context->magicMethodThrowHandled = false;
            throw new VM\MagicMethodInvocationAborted();
        }

        return $out->resolveIndirect();
    }

    /**
     * Isolated __toString / coercion invoke — must not run the caller script in nested runFrames (#4284).
     *
     * Outer try/catch is deferred via {@see VM\BuiltinCallbackCatchRedirect} so catch resumes on the
     * caller's runFrames (echo/print/cast/concat). Nest-running the catch here used to execute the
     * merge block (and everything after) then resume the try body — printing AFTER twice (#29521).
     *
     * @param Variable ...$args
     */
    private function invokePhpFunctionForCoercion(Func\PHP $func, ...$args): Variable
    {
        return $this->invokePhpFunctionForCoercionWithCalledArgs($func, $args);
    }

    /**
     * @param array<int, Variable> $args
     */
    private function invokePhpFunctionForCoercionWithCalledArgs(Func\PHP $func, array $args): Variable
    {
        $savedStack = $this->context->swapRunStack(null);
        $prevDefer = $this->context->deferBuiltinCallbackCatchToOuterRunFrames;
        $this->context->deferBuiltinCallbackCatchToOuterRunFrames = true;
        try {
            $result = $this->invokePhpFunctionOnStackWithCalledArgs($func, $args);
            $this->context->swapRunStack($savedStack);

            return $result;
        } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
            if (!$this->context->hasRunStack()) {
                $this->context->swapRunStack($savedStack);
            }
            $this->context->magicMethodThrowHandled = false;
            throw $redirect;
        } catch (VM\MagicMethodInvocationAborted $aborted) {
            if (!$this->context->hasRunStack()) {
                $this->context->swapRunStack($savedStack);
            }
            throw $aborted;
        } catch (\Throwable $native) {
            $this->context->swapRunStack($savedStack);
            if (null !== $savedStack) {
                $thrown = $native instanceof \Error
                    ? VM\BuiltinExceptionSupport::materializeNativeError($this->context, $native)
                    : $this->makeEngineError($native->getMessage(), 'Exception');
                $catchFrame = $this->findCatchFrameForThrow($savedStack->frame, $thrown);
                if (null !== $catchFrame) {
                    // Defer catch to outer opcode handler — do not nest-run merge (#29521).
                    $this->context->magicMethodThrowHandled = false;
                    throw new VM\BuiltinCallbackCatchRedirect($catchFrame);
                }
            }
            throw $native;
        } finally {
            $this->context->deferBuiltinCallbackCatchToOuterRunFrames = $prevDefer;
        }
    }

    /**
     * Invoke a static method in the caller's late-static scope (forward_static_call, #3197).
     *
     * Method body is resolved on {@see $calledScopeClass} (same as owner). Prefer
     * {@see invokeDeclaredStaticWithCalledScope()} when the callable names a different class (#20251).
     */
    public function invokeStaticWithCalledScope(
        string $calledScopeClass,
        string $methodName,
        Variable ...$args
    ): Variable {
        return $this->invokeDeclaredStaticWithCalledScope(
            $calledScopeClass,
            $calledScopeClass,
            $methodName,
            ...$args
        );
    }

    /**
     * Run {@see $methodOwnerClass}::{@see $methodName} with late-static called-scope {@see $calledScopeClass}.
     *
     * php-src forward_static_call*: lookup uses the callable class; EG called-scope is the caller LSB
     * only when that LSB instanceof the callable calling_scope — otherwise the named class (#20251, #27140).
     */
    public function invokeDeclaredStaticWithCalledScope(
        string $methodOwnerClass,
        string $calledScopeClass,
        string $methodName,
        Variable ...$args
    ): Variable {
        return $this->invokeDeclaredStaticWithCalledArgs(
            $methodOwnerClass,
            $calledScopeClass,
            $methodName,
            $args
        );
    }

    /**
     * @param array<int, Variable> $args possibly sparse (ReflectionMethod::invokeArgs named keys, #23388)
     */
    public function invokeDeclaredStaticWithCalledArgs(
        string $methodOwnerClass,
        string $calledScopeClass,
        string $methodName,
        array $args
    ): Variable {
        $func = VmForwardStaticCall::resolveStaticMethod($this->context, $methodOwnerClass, $methodName);
        $savedStack = $this->context->swapRunStack(null);
        try {
            $child = $func->getFrame($this->context, null);
            $child->calledClass = $calledScopeClass;
            $child->calledArgs = $args;
            $out = new Variable();
            $child->returnVar = $out;
            $this->context->push($child);
            $result = $this->runFrames();
            if (self::SUCCESS !== $result) {
                throw new \LogicException('Static method invocation failed in this compiler build');
            }

            return $out->resolveIndirect();
        } finally {
            $this->context->swapRunStack($savedStack);
        }
    }

    /**
     * Walk inheritance for an instance method (Zend zend_object_handlers parity, #3259).
     *
     * @return array{0: ClassEntry, 1: string}
     */
    public function resolveInstanceMethod(ClassEntry $class, string $methodLc): array
    {
        $methodLc = strtolower($methodLc);
        $lcClass = strtolower($class->name);
        $visited = [];
        $abstractDecl = null;
        while (!isset($visited[$lcClass])) {
            $visited[$lcClass] = true;
            if (!isset($this->context->classes[$lcClass])) {
                break;
            }
            $entry = $this->context->classes[$lcClass];
            if (isset($entry->methods[$methodLc])) {
                return [$entry, $methodLc];
            }
            if (isset($entry->abstractMethods[$methodLc])) {
                $abstractDecl ??= $entry;
            }
            if (null === $entry->parentLc) {
                break;
            }
            $lcClass = $entry->parentLc;
        }

        if (null !== $abstractDecl) {
            $declName = $abstractDecl->methodNames[$methodLc] ?? $methodLc;
            throw new \LogicException("Cannot call abstract method {$abstractDecl->name}::{$declName}()");
        }

        $declName = $class->methodNames[$methodLc] ?? $methodLc;
        throw new \LogicException("Call to undefined method {$class->name}::{$declName}()");
    }

    public function hasInstanceMethod(ClassEntry $class, string $methodLc): bool
    {
        $methodLc = strtolower($methodLc);
        $lcClass = strtolower($class->name);
        $visited = [];
        while (!isset($visited[$lcClass])) {
            $visited[$lcClass] = true;
            if (!isset($this->context->classes[$lcClass])) {
                return false;
            }
            $entry = $this->context->classes[$lcClass];
            if (isset($entry->methods[$methodLc])) {
                // Parent-only PDO_*_Ext methods are not visible on subclasses (#21552).
                if ($entry !== $class && isset($entry->methodNotInherited[$methodLc])) {
                    if (null === $entry->parentLc) {
                        return false;
                    }
                    $lcClass = $entry->parentLc;
                    continue;
                }

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
     * Zend zend_check_private: when virtual lookup found a private method outside the
     * calling scope, rebind to the caller's same-name private if $obj is in that hierarchy.
     *
     * php-src: Zend/zend_object_handlers.c — zend_check_private / zend_std_get_method (#22928).
     */
    private function resolvePrivateInstanceMethodForScope(
        ClassEntry $declaringClass,
        string $methodLc,
        ClassEntry $objectClass,
        ?string $callerClassLc
    ): ClassEntry {
        $vis = $declaringClass->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
        if (($vis & \PHPCfg\Func::FLAG_PRIVATE) === 0) {
            return $declaringClass;
        }
        if (null === $callerClassLc || $callerClassLc === strtolower($declaringClass->name)) {
            return $declaringClass;
        }
        if (!isset($this->context->classes[$callerClassLc])) {
            return $declaringClass;
        }
        $callerEntry = $this->context->classes[$callerClassLc];
        if (!isset($callerEntry->methods[$methodLc])) {
            return $declaringClass;
        }
        $callerVis = $callerEntry->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
        if (($callerVis & \PHPCfg\Func::FLAG_PRIVATE) === 0) {
            return $declaringClass;
        }
        if (!$this->isClassSameOrSubclassOf(strtolower($objectClass->name), $callerClassLc)) {
            return $declaringClass;
        }

        return $callerEntry;
    }

    /** Coerce a VM value to string, invoking __toString on objects when defined (issue #3296). */
    public function coerceVariableToString(Variable $var, ?Frame $frame = null): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            return $var->toString($this, $frame);
        }
        $object = $var->toObject();
        if (VM\ResourceSupport::isResourceObject($object)) {
            return $var->toString($this, $frame);
        }
        if (EnumCaseSupport::isEnumCase($object)) {
            throw new \Error("Object of class {$object->class->name} could not be converted to string");
        }
        if (!$this->hasInstanceMethod($object->class, '__tostring')) {
            throw new \Error(
                'Object of class '.$object->class->name.' could not be converted to string'
            );
        }
        $this->context->coercingObjectToString = true;
        try {
            $result = $this->invokeMagicToString($object, $frame)->resolveIndirect();
        } finally {
            $this->context->coercingObjectToString = false;
        }

        return $result->toString($this, $frame);
    }

    /**
     * Invoke __toString for user Func\PHP or VM builtin VmClassMethod handlers (#7159).
     */
    private function invokeMagicToString(ObjectEntry $object, ?Frame $callerFrame = null): Variable
    {
        $methodLc = '__tostring';
        [$declaring] = $this->resolveInstanceMethod($object->class, $methodLc);
        $func = $declaring->methods[$methodLc];
        $thisVar = new Variable();
        $thisVar->object($object);
        if ($func instanceof Func\Internal) {
            $caller = $callerFrame ?? $this->coercionCallerFrame();
            $result = new Variable();
            $catchFrame = $this->invokeVmClassMethod($func, $caller, $result, $thisVar);
            if (null !== $catchFrame) {
                // Catch not run yet — resume on outer echo/print/cast (#29521 / #4284).
                throw new VM\BuiltinCallbackCatchRedirect($catchFrame);
            }

            return $result;
        }
        if ($func instanceof Func\PHP) {
            return $this->invokePhpFunctionForCoercion($func, $thisVar);
        }

        throw new \LogicException("{$declaring->name}::__toString() is not invokable in this compiler build");
    }

    private function coercionCallerFrame(): Frame
    {
        $frames = $this->context->runStackFrames();
        if ([] !== $frames) {
            return $frames[0];
        }

        return (new VM\Builtin\ExceptionGetMessage())->getFrame($this->context);
    }

    /** Invoke an instance method from VM internals (e.g. __debugInfo, #3259, #7069). */
    public function invokeInstanceMethod(ObjectEntry $object, string $methodName, Variable ...$extraArgs): Variable
    {
        return $this->invokeInstanceMethodInternal($object, $methodName, false, ...$extraArgs);
    }

    /**
     * @deprecated Prefer {@see invokeInstanceMethod()} — suppress was wrong for
     *             strict_types Countable::count() (#26433); kept for rare callers.
     */
    public function invokeInstanceMethodWithoutReturnCheck(
        ObjectEntry $object,
        string $methodName,
        Variable ...$extraArgs
    ): Variable {
        return $this->invokeInstanceMethodInternal($object, $methodName, true, ...$extraArgs);
    }

    private function invokeInstanceMethodInternal(
        ObjectEntry $object,
        string $methodName,
        bool $suppressReturnTypeCheck,
        Variable ...$extraArgs
    ): Variable {
        if ($suppressReturnTypeCheck) {
            ++$this->context->suppressReturnTypeCheckDepth;
        }
        try {
            return $this->invokeInstanceMethodBody($object, $methodName, ...$extraArgs);
        } finally {
            if ($suppressReturnTypeCheck) {
                --$this->context->suppressReturnTypeCheckDepth;
            }
        }
    }

    private function invokeInstanceMethodBody(ObjectEntry $object, string $methodName, Variable ...$extraArgs): Variable
    {
        $methodLc = strtolower($methodName);
        [$declaring] = $this->resolveInstanceMethod($object->class, $methodLc);
        $func = $declaring->methods[$methodLc];
        $vis = $declaring->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
        $isStatic = (($vis & \PHPCfg\Func::FLAG_STATIC) !== 0) || $this->methodIsStatic($func);
        // XMLReader::open/XML keep EX(This) under `$obj->static()` (#22630); others omit (#22288).
        $omitThis = $isStatic && !$this->staticMethodKeepsInstanceThis($declaring, $methodLc);
        $thisVar = new Variable();
        $thisVar->object($object);
        if ($func instanceof Func\Internal) {
            $caller = $this->coercionCallerFrame();
            $result = new Variable();
            $catchFrame = $omitThis
                ? $this->invokeVmClassMethod($func, $caller, $result, ...$extraArgs)
                : $this->invokeVmClassMethod($func, $caller, $result, $thisVar, ...$extraArgs);
            if (null !== $catchFrame) {
                // Catch is prepared but not run — defer to outer runFrames so user try/catch
                // resumes once (FilterIterator::accept #24286/#24297; __toString #29521/#4284).
                throw new VM\BuiltinCallbackCatchRedirect($catchFrame);
            }

            return $result;
        }
        if (!$func instanceof Func\PHP) {
            throw new \LogicException("{$declaring->name}::{$methodName}() is not invokable in this compiler build");
        }

        // Isolated stack: nested user method must not resume the caller frame mid-builtin (#11452).
        // Property magic + engine-invoked interface protocol keep outer try/catch visible so
        // return TypeErrors remain catchable like Zend (#25911, #26433).
        $catchableEngineInvoke = $this->isCatchableEngineInvokedMethod($methodLc);
        if ($omitThis) {
            return $catchableEngineInvoke
                ? $this->invokePhpFunctionIsolatedCatchable($func, ...$extraArgs)
                : $this->invokePhpFunctionIsolated($func, ...$extraArgs);
        }

        return $catchableEngineInvoke
            ? $this->invokePhpFunctionIsolatedCatchable($func, $thisVar, ...$extraArgs)
            : $this->invokePhpFunctionIsolated($func, $thisVar, ...$extraArgs);
    }

    /**
     * Methods the engine invokes on behalf of builtins/opcodes whose throws Zend delivers
     * to the caller's try/catch (zend_object_handlers / zend_interfaces / php_count).
     */
    private function isCatchableEngineInvokedMethod(string $methodLc): bool
    {
        return \in_array($methodLc, [
            '__get', '__set', '__isset', '__unset',
            // Countable / ArrayAccess / Iterator / IteratorAggregate (#26433)
            'count',
            'offsetexists', 'offsetget', 'offsetset', 'offsetunset',
            'valid', 'current', 'key', 'next', 'rewind',
            'getiterator',
        ], true);
    }

    /**
     * php-src zim_xmlreader_open / zim_xmlreader_XML inspect EX(This) even though the methods
     * are ZEND_ACC_STATIC — instance `$r->open()` / `$r->XML()` mutate $this and return bool
     * (#22630, re-#19330/#19308). Other static-via-instance calls omit the receiver (#22288).
     */
    private function staticMethodKeepsInstanceThis(ClassEntry $declaring, string $methodLc): bool
    {
        if (ext\xmlreader\VmXmlReader::CLASS_LC !== strtolower($declaring->name)) {
            return false;
        }

        return 'open' === $methodLc || 'xml' === $methodLc;
    }

    public function objectImplementsArrayAccess(ObjectEntry $object): bool
    {
        return VM\InterfaceCheck::entryImplements($object->class, 'arrayaccess', $this->context);
    }

    /**
     * Array or ArrayAccess RHS for guarded list destructuring (#4325, #7440, #25096).
     *
     * php-src ZEND_FETCH_LIST: Traversable-only / plain objects are not unpackable —
     * dim fetch raises "Cannot use object of type … as array" (Generator, Iterator, stdClass).
     */
    private function variableIsListDestructUnpackable(Variable $value): bool
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_ARRAY === $value->type) {
            return true;
        }
        if (Variable::TYPE_OBJECT !== $value->type) {
            return false;
        }

        return $this->objectImplementsArrayAccess($value->toObject());
    }

    /**
     * Snapshot array literal elements so compiler expr temps can be reused (#5593, #5598, #5627).
     */
    private function materializeArrayElementForStorage(Variable $value): Variable
    {
        $copy = new Variable();
        $copy->copyFrom($value->resolveIndirect());

        return $copy;
    }

    /**
     * Materialize Iterator / Generator RHS into a packed list array for integer-key dim fetches (#7452).
     */
    private function materializeListDestructIterableRhs(Variable $rhsSlot, Frame $frame): ?Frame
    {
        $unpack = $rhsSlot->resolveIndirect();
        if (Variable::TYPE_ARRAY === $unpack->type) {
            return null;
        }
        if (
            Variable::TYPE_OBJECT === $unpack->type
            && $this->objectImplementsArrayAccess($unpack->toObject())
        ) {
            return null;
        }
        if (!VM\IterableCheck::isIterable($unpack, $this->context)) {
            return null;
        }

        $ht = new HashTable();
        if (Variable::TYPE_OBJECT === $unpack->type && $this->variableIsGenerator($unpack)) {
            $gen = $unpack->toObject()->generatorState;
            $gen->rewind();
            $index = 0;
            while ($this->advanceGeneratorIteration($gen)) {
                $packedKey = new Variable();
                $packedKey->int($index++);
                self::appendHashTableEntry($ht, $packedKey, $gen->currentValue);
            }
        } elseif (Variable::TYPE_OBJECT === $unpack->type) {
            try {
                $iterable = VM\ForeachIterator::resolveTraversableObject($this, $frame, $unpack);
            } catch (\TypeError $e) {
                return $this->dispatchVmTypeError($e, $frame);
            }
            if ($this->variableIsGenerator($iterable)) {
                $gen = $iterable->toObject()->generatorState;
                $gen->rewind();
                $index = 0;
                while ($this->advanceGeneratorIteration($gen)) {
                    $packedKey = new Variable();
                    $packedKey->int($index++);
                    self::appendHashTableEntry($ht, $packedKey, $gen->currentValue);
                }
            } else {
                $this->invokeForeachInstanceMethod($frame, $iterable, 'rewind');
                $index = 0;
                while ($this->invokeForeachInstanceMethod($frame, $iterable, 'valid')->toBool()) {
                    $current = $this->invokeForeachInstanceMethod($frame, $iterable, 'current');
                    $packedKey = new Variable();
                    $packedKey->int($index++);
                    self::appendHashTableEntry($ht, $packedKey, $current);
                    $this->invokeForeachInstanceMethod($frame, $iterable, 'next');
                }
            }
        }
        $rhsSlot->array($ht);

        return null;
    }

    public function invokeArrayAccessOffsetGet(
        ObjectEntry $object,
        Variable $key,
        Frame $callerFrame,
        Variable $resultOut
    ): ?Frame {
        [$declaring, $methodLc] = $this->resolveInstanceMethod($object->class, 'offsetGet');
        $func = $declaring->methods[$methodLc];
        $thisVar = new Variable();
        $thisVar->object($object);
        if ($func instanceof Func\Internal) {
            $handlerOut = new Variable();
            $catchFrame = $this->invokeVmClassMethod($func, $callerFrame, $handlerOut, $thisVar, $key);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            $resultOut->copyFrom($handlerOut);

            return null;
        }

        // Isolated stack: nested user offset* must not resume the caller mid-opcode (#23450, #11452).
        // Keep outer try/catch for return TypeError under strict_types (#26433).
        $resultOut->copyFrom($this->invokePhpFunctionIsolatedCatchable($func, $thisVar, $key));

        return null;
    }

    public function invokeArrayAccessOffsetSet(
        ObjectEntry $object,
        Variable $key,
        Variable $value,
        Frame $callerFrame
    ): ?Frame {
        [$declaring, $methodLc] = $this->resolveInstanceMethod($object->class, 'offsetSet');
        $func = $declaring->methods[$methodLc];
        $thisVar = new Variable();
        $thisVar->object($object);
        if ($func instanceof Func\Internal) {
            return $this->invokeVmClassMethod($func, $callerFrame, null, $thisVar, $key, $value);
        }
        // Isolated stack: nested user offset* must not resume the caller mid-opcode (#23450, #11452).
        // Keep outer try/catch for return TypeError under strict_types (#26433).
        $this->invokePhpFunctionIsolatedCatchable($func, $thisVar, $key, $value);

        return null;
    }

    /**
     * Deferred $obj[$key] = $value — let TypeError bubble to ASSIGN for catch dispatch (#8949).
     */
    public function executeArrayAccessOffsetSet(
        ObjectEntry $object,
        Variable $key,
        Variable $value,
        Frame $callerFrame
    ): void {
        [$declaring, $methodLc] = $this->resolveInstanceMethod($object->class, 'offsetSet');
        $func = $declaring->methods[$methodLc];
        $thisVar = new Variable();
        $thisVar->object($object);
        if ($func instanceof Func\Internal) {
            $handlerFrame = $func->getFrame($this->context, $callerFrame);
            $handlerFrame->calledArgs = [$thisVar, $key, $value];
            $handlerFrame->returnVar = new Variable();
            $func->execute($handlerFrame);

            return;
        }
        // Isolated stack: nested user offset* must not resume the caller mid-opcode (#23450, #11452).
        // Keep outer try/catch for return TypeError under strict_types (#26433).
        $this->invokePhpFunctionIsolatedCatchable($func, $thisVar, $key, $value);
    }

    public function invokeArrayAccessOffsetExists(
        ObjectEntry $object,
        Variable $key,
        Frame $callerFrame,
        Variable $resultOut
    ): ?Frame {
        [$declaring, $methodLc] = $this->resolveInstanceMethod($object->class, 'offsetExists');
        $func = $declaring->methods[$methodLc];
        $thisVar = new Variable();
        $thisVar->object($object);
        if ($func instanceof Func\Internal) {
            $handlerOut = new Variable();
            $catchFrame = $this->invokeVmClassMethod($func, $callerFrame, $handlerOut, $thisVar, $key);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            $resultOut->copyFrom($handlerOut);

            return null;
        }

        // Isolated stack: nested user offsetExists must not resume the caller mid-isset/empty (#23450, #11452).
        // Keep outer try/catch for return TypeError under strict_types (#26433).
        $resultOut->copyFrom($this->invokePhpFunctionIsolatedCatchable($func, $thisVar, $key));

        return null;
    }

    /**
     * php-src spl_array_has_dimension(isset) when offsetExists is the Internal SPL method (#24251).
     * Returns null when ArrayAccess isset must stay offsetExists-only (user override / non-SPL).
     */
    private function nativeSplArrayDimensionIsSet(ObjectEntry $object, Variable $key): ?bool
    {
        if (!SplArrayStorage::hasState($object)) {
            return null;
        }
        [$declaring, $methodLc] = $this->resolveInstanceMethod($object->class, 'offsetExists');
        $func = $declaring->methods[$methodLc] ?? null;
        if (!$func instanceof Func\Internal) {
            return null;
        }

        return SplArrayStorage::dimensionIsSet($object, $key);
    }

    public function invokeArrayAccessOffsetUnset(
        ObjectEntry $object,
        Variable $key,
        Frame $callerFrame
    ): ?Frame {
        [$declaring, $methodLc] = $this->resolveInstanceMethod($object->class, 'offsetUnset');
        $func = $declaring->methods[$methodLc];
        $thisVar = new Variable();
        $thisVar->object($object);
        if ($func instanceof Func\Internal) {
            return $this->invokeVmClassMethod($func, $callerFrame, null, $thisVar, $key);
        }
        // Isolated stack: nested user offset* must not resume the caller mid-opcode (#23450, #11452).
        // Keep outer try/catch for return TypeError under strict_types (#26433).
        $this->invokePhpFunctionIsolatedCatchable($func, $thisVar, $key);

        return null;
    }

    /**
     * empty($container[$dim]) — Zend zend_check_empty dimension parity (#14798).
     *
     * @return ?Frame catch frame when user code handles a throw
     */
    public function evaluateEmptyDimension(
        Variable $container,
        Variable $dim,
        Frame $frame,
        Variable $dst
    ): ?Frame {
        return VM\VmEmptyDimension::evaluate($this, $container, $dim, $frame, $dst);
    }

    /** @internal VmEmptyDimension error propagation */
    public function propagateEmptyDimensionTypeError(\TypeError $error, Frame $frame): ?Frame
    {
        return $this->dispatchVmTypeError($error, $frame);
    }

    /** @internal VmEmptyDimension error propagation */
    public function propagateEmptyDimensionError(string $message, Frame $frame): ?Frame
    {
        return $this->dispatchVmError($message, $frame);
    }

    /**
     * Invoke a VM builtin class method; return catch frame when user code handles the throw.
     *
     * @param Variable ...$args
     */
    private function invokeVmClassMethod(
        Func\Internal $func,
        Frame $callerFrame,
        ?Variable $returnVar,
        Variable ...$args
    ): ?Frame {
        $handlerFrame = $func->getFrame($this->context, $callerFrame);
        $handlerFrame->vmContext = $this->context;
        $handlerFrame->calledArgs = $args;
        $handlerFrame->returnVar = $returnVar;

        return $this->executeInternalHandler($handlerFrame, $callerFrame);
    }

    /**
     * isset($obj->prop) — Zend zend_std_has_property / __isset parity (#3298, #4586, #25668).
     * Hooked properties: when a get hook exists, always invoke get (zend_std_has_property; #29214, #11262).
     * Incomplete objects: E_WARNING + false (zend_object_handlers.c, #19632).
     * Inaccessible declared props skip the slot and route through __isset (zend_object_handlers.c).
     */
    public function objectPropertyIsSet(ObjectEntry $object, string $propName, ?Frame $frame = null): bool
    {
        if (VM\IncompleteClassSupport::isIncomplete($object)) {
            VM\IncompleteClassSupport::emitAccessWarning($object, $this->context, $frame);

            return false;
        }
        $hookedIsset = $this->issetHookedPropertyForIssetEmpty($object, $propName, $frame);
        if (null !== $hookedIsset) {
            return $hookedIsset;
        }
        // Dom\HTMLDocument computed props (body/title/…) — not the null ClassProperty slot (#20540).
        $domHtmlIsset = ext\dom\DomHtmlDocumentPropertySupport::propertyIsSet($object, $propName);
        if (null !== $domHtmlIsset) {
            return $domHtmlIsset;
        }
        // Dom\Element::$id|/className|/innerHTML|/outerHTML (#20532).
        $domHtmlElIsset = ext\dom\DomHtmlElementPropertySupport::propertyIsSet($object, $propName);
        if (null !== $domHtmlElIsset) {
            return $domHtmlElIsset;
        }
        // Dom\* Node/CharacterData/ParentNode computed props (#21033, #21053, #21055).
        $domChildrenIsset = ext\dom\DomNodePropertySupport::propertyIsSet($object, $propName);
        if (null !== $domChildrenIsset) {
            return $domChildrenIsset;
        }
        // ReflectionAttribute / other C-only slots are not PHP-visible (#22513).
        $meta = $this->classPropertyMeta($object, $propName, $frame);
        if (null !== $meta && $meta->phpInvisible) {
            return false;
        }
        $props = $object->getRawProperties();
        if (isset($props[$propName])) {
            // Declared but not visible from caller scope — do not leak the private/protected slot (#25668).
            // Post-unset declared slots also route through __isset (zend_std_has_property; #25810).
            $useOverload = $object->isPropertyExplicitlyUnset($propName)
                || (
                    null !== $frame
                    && null !== $meta
                    && $this->declaredPropertyIssetUsesOverload($object, $meta, $propName, $frame)
                );
            if ($useOverload) {
                // Fall through to __isset / false (zend_std_has_property).
            } else {
                return VmIsset::storedPropertyIsSet($props[$propName]);
            }
        }
        // ArrayObject/ArrayIterator::ARRAY_AS_PROPS — backing keys as properties (spl_array.c; #22576).
        // has_property(isset) shares spl_array_has_dimension null-check (#24398, peer #24251).
        if (SplArrayStorage::hasArrayAsProps($object)) {
            $key = new Variable(Variable::TYPE_STRING);
            $key->string($propName);
            $native = $this->nativeSplArrayDimensionIsSet($object, $key);
            if (null !== $native) {
                return $native;
            }

            return SplArrayStorage::offsetExists($object, $key);
        }
        if ($this->hasInstanceMethod($object->class, '__isset')) {
            if ($object->isPropertyGuardActive($propName, ObjectEntry::GUARD_IN_ISSET)) {
                return false;
            }
            if (!$object->beginPropertyGuard($propName, ObjectEntry::GUARD_IN_ISSET)) {
                return false;
            }
            try {
                $key = new Variable();
                $key->string($propName);
                $result = $this->invokeInstanceMethod($object, '__isset', $key)->resolveIndirect();

                return $result->toBool();
            } finally {
                $object->endPropertyGuard($propName, ObjectEntry::GUARD_IN_ISSET);
            }
        }

        return false;
    }

    /**
     * ?? / ??= on property hooks — Zend BP_VAR_IS invokes get when present (#29266, zend_object_handlers.c).
     * Write-only (no get): probe backing (#6472). Incomplete: E_WARNING + false (#19632).
     */
    public function objectPropertyIsSetForCoalesceAssign(ObjectEntry $object, string $propName, ?Frame $frame = null): bool
    {
        if (VM\IncompleteClassSupport::isIncomplete($object)) {
            VM\IncompleteClassSupport::emitAccessWarning($object, $this->context, $frame);

            return false;
        }
        $hookedIsset = $this->issetHookedPropertyForIssetEmpty($object, $propName, $frame);
        if (null !== $hookedIsset) {
            return $hookedIsset;
        }

        return $this->objectPropertyIsSet($object, $propName, $frame);
    }

    /**
     * ?? / ??= on static property hooks — invoke get when present (#29266); else backing (#9683).
     */
    public function fetchStaticPropertyForCoalesce(
        string $classLc,
        string $propNameRaw,
        Variable $dst,
        ?Frame $frame = null
    ): void {
        $propLc = strtolower($propNameRaw);
        $hooks = $this->resolveStaticPropertyHooks($classLc, $propLc);
        if (null !== $hooks && isset($hooks['get']) && null !== $frame) {
            $hookValue = $this->fetchStaticPropertyWithHooks($classLc, $propNameRaw, $hooks['get'], $frame);
            $dst->copyFromForClone($hookValue->resolveIndirect());

            return;
        }
        $backing = $this->hookedStaticPropertyBackingValue($classLc, $propNameRaw);
        if (false !== $backing) {
            $dst->copyFromForClone($backing);

            return;
        }
        $storage = $this->resolveStaticPropertyStorage($classLc, strtolower($propNameRaw));
        if (null !== $storage) {
            $dst->copyFromForClone($storage);

            return;
        }
        $dst->undefined();
    }

    /**
     * ReflectionClass::getStaticPropertyValue / ReflectionProperty::getValue on static props.
     * Invokes get hook when present instead of reading uninitialized backing (#9863, php_reflection.c).
     */
    public function readStaticPropertyForReflection(
        string $classLc,
        string $propertyName,
        Variable $backingStorage,
        ?Variable $default,
        Frame $frame
    ): Variable {
        $propLc = strtolower($propertyName);
        $hooks = $this->resolveStaticPropertyHooks($classLc, $propLc);
        if (null !== $hooks && isset($hooks['get'])) {
            return $this->fetchStaticPropertyWithHooks($classLc, $propertyName, $hooks['get'], $frame);
        }
        if (VM\TypedPropertyCheck::isUninitialized($backingStorage)) {
            if (null !== $default) {
                $out = new Variable();
                $out->copyFrom($default);

                return $out;
            }
            throw new \Error(VM\TypedPropertyCheck::errorMessage($backingStorage));
        }
        $out = new Variable();
        $out->copyFrom($backingStorage->resolveIndirect());

        return $out;
    }

    /**
     * ReflectionProperty::setValue on static props — invoke set hook when present (#4469, php_reflection.c).
     */
    public function writeStaticPropertyForReflection(
        ClassEntry $entry,
        string $propertyName,
        Variable $value,
        Frame $frame
    ): void {
        $classLc = strtolower(ltrim($entry->name, '\\'));
        $propLc = strtolower($propertyName);
        $hooks = $this->resolveStaticPropertyHooks($classLc, $propLc);
        if (null !== $hooks && !empty($hooks['set'])) {
            $setLc = $hooks['set'];
            if (isset($entry->methods[$setLc])) {
                $func = $entry->methods[$setLc];
                if ($func instanceof Func\PHP) {
                    $this->context->propertyHookSetAborted = false;
                    $this->invokeStaticPropertyHookRaw(
                        $func,
                        $propertyName,
                        $classLc,
                        $frame,
                        $value->resolveIndirect()
                    );

                    return;
                }
            }
        }
        \PHPCompiler\ext\standard\VmReflection::setStaticPropertyValueForReflection(
            $entry,
            $this->context,
            $propertyName,
            $value
        );
    }

    /**
     * ReflectionProperty::setValue on instance props — invoke set hook when present (#4469, php_reflection.c).
     */
    public function writeInstancePropertyForReflection(
        ObjectEntry $object,
        string $instanceName,
        ?VM\ClassProperty $meta,
        Variable $value,
        Frame $frame
    ): void {
        $this->assertReadonlyPropertyWriteAllowedForReflection($object, $instanceName, $frame);
        $this->assertFinalPropertyWriteAllowedForReflection($object, $instanceName);
        $setLc = $meta?->setHookMethodLc
            ?? strtolower(SourcePreprocessor\PropertyHooks::setHookMethodName($instanceName));
        if (isset($object->class->methods[$setLc])) {
            $func = $object->class->methods[$setLc];
            if ($func instanceof Func\PHP) {
                $this->context->propertyHookSetAborted = false;
                $thisVar = new Variable();
                $thisVar->object($object);
                $this->invokePhpFunctionWithPropertyHookRaw(
                    $func,
                    $instanceName,
                    $frame,
                    $thisVar,
                    $value->resolveIndirect()
                );

                return;
            }
        }
        $slot = $object->getProperty($instanceName);
        $slot->copyFrom($value->resolveIndirect());
        TypeCheck::coercePropertyWrite($slot, false);
        $resolved = $slot->resolveIndirect();
        if (null !== $resolved->dnfArms) {
            DnfCheck::assertMatches(
                $value,
                $resolved->dnfArms,
                $this->context,
                'Property',
                $resolved,
                false
            );
        }
    }

    /**
     * ReflectionProperty::getRawValue — read backing storage without get hook (#6451, php_reflection.c).
     */
    public function readInstancePropertyRawForReflection(
        ObjectEntry $object,
        string $instanceName,
        ?VM\ClassProperty $meta
    ): Variable {
        $slot = $this->instancePropertyRawBackingSlot($object, $instanceName);
        if (null === $slot) {
            throw new \LogicException('Undefined property in this compiler build');
        }
        if (VM\TypedPropertyCheck::isUninitialized($slot)) {
            throw new \Error(VM\TypedPropertyCheck::errorMessage($slot));
        }
        $out = new Variable();
        $out->copyFrom($slot->resolveIndirect());

        return $out;
    }

    /**
     * ReflectionProperty::setRawValue — write backing storage without set hook (#6451, php_reflection.c).
     */
    public function writeInstancePropertyRawForReflection(
        ObjectEntry $object,
        string $instanceName,
        ?VM\ClassProperty $meta,
        Variable $value,
        bool $strictTypes
    ): void {
        $slot = $this->instancePropertyRawBackingSlot($object, $instanceName);
        if (null === $slot) {
            throw new \LogicException('Undefined property in this compiler build');
        }
        if (null !== $meta) {
            $probe = new Variable();
            $probe->copyFrom($value);
            $target = $probe->resolveIndirect();
            $typeMeta = $meta->prototype->resolveIndirect();
            $target->typeConstraint = $typeMeta->typeConstraint;
            $target->classConstraint = $typeMeta->classConstraint;
            $target->literalBoolType = $typeMeta->literalBoolType;
            $target->unionTypeConstraints = $typeMeta->unionTypeConstraints;
            $target->declaredTypeLabel = $typeMeta->declaredTypeLabel;
            $target->genericArrayTypeSpec = $typeMeta->genericArrayTypeSpec;
            $target->dnfArms = $typeMeta->dnfArms;
            VM\TypeCheck::coercePropertyWrite($probe, $strictTypes);
            $slot->copyFrom($probe);

            return;
        }
        $slot->copyFrom($value->resolveIndirect());
        VM\TypeCheck::coercePropertyWrite($slot, $strictTypes);
    }

    /**
     * Writable slot for hooked or plain instance property backing (#6451).
     */
    private function instancePropertyRawBackingSlot(ObjectEntry $object, string $propName): ?Variable
    {
        if ($this->instancePropertyHasHooks($object, $propName)) {
            $lcClass = strtolower($object->class->name);
            $propMeta = $this->context->propertyHookRegistry[$lcClass][$propName]
                ?? $this->context->propertyHookRegistry[$lcClass][strtolower($propName)]
                ?? null;
            if (is_array($propMeta)) {
                $backingName = $propMeta['setBacking'] ?? $propMeta['getBacking'] ?? null;
                if (null !== $backingName && strcasecmp($backingName, $propName) !== 0) {
                    if ($object->hasProperty($backingName)) {
                        return $object->getProperty($backingName);
                    }

                    return null;
                }
            }
            if ($object->hasProperty($propName)) {
                return $object->getProperty($propName);
            }

            return null;
        }
        if ($object->hasProperty($propName)) {
            return $object->getProperty($propName);
        }

        return null;
    }

    /**
     * ?? / ??= isset probe on static hooked properties — backing only, never get hook (#9683).
     */
    public function staticPropertyIsSetForCoalesceAssign(string $classLc, string $propNameRaw): bool
    {
        $hookedIsset = $this->issetHookedStaticPropertyWithoutGetHook($classLc, $propNameRaw);
        if (null !== $hookedIsset) {
            return $hookedIsset;
        }
        $storage = $this->resolveStaticPropertyStorage($classLc, strtolower($propNameRaw));
        if (null === $storage) {
            return false;
        }
        $value = $storage->resolveIndirect();

        return !$value->isUndefined() && Variable::TYPE_NULL !== $value->type;
    }

    /**
     * ?? / ??= isset probe on hooked properties when no get hook — backing only (#8902, #6472).
     * Prefer {@see issetHookedPropertyForIssetEmpty} when a get hook may exist (#29266).
     *
     * @return bool|null null when the property is not hook-backed
     */
    private function issetHookedPropertyWithoutGetHook(ObjectEntry $object, string $propName): ?bool
    {
        $backing = $this->hookedPropertyBackingValue($object, $propName);
        if (false === $backing) {
            return null;
        }
        if ($backing->isUndefined() || VM\TypedPropertyCheck::isUninitialized($backing)) {
            return false;
        }

        return Variable::TYPE_NULL !== $backing->type;
    }

    /**
     * isset($obj->hooked) — php-src zend_std_has_property: when a get hook exists, always
     * invoke it (virtual and non-virtual / expression-bodied set included) (#29214, #11262).
     * Write-only (no get): probe backing, or Error for virtual write-only (#6484).
     *
     * @return bool|null null when the property is not hook-backed
     */
    private function issetHookedPropertyForIssetEmpty(ObjectEntry $object, string $propName, ?Frame $frame): ?bool
    {
        if (!$this->instancePropertyHasHooks($object, $propName)) {
            return null;
        }
        if (!$this->instancePropertyHasGetHook($object, $propName)) {
            return $this->issetHookedPropertyWithoutGetHook($object, $propName);
        }
        if (null === $frame) {
            return $this->issetHookedPropertyWithoutGetHook($object, $propName);
        }
        try {
            $hookValue = $this->fetchPropertyWithHooks($object, $propName, $frame);
        } catch (VM\PropertyHookRefWriteSignal) {
            return false;
        }
        if (null === $hookValue) {
            return $this->issetHookedPropertyWithoutGetHook($object, $propName);
        }
        $value = $hookValue->resolveIndirect();

        return Variable::TYPE_NULL !== $value->type;
    }

    /**
     * ?? / ??= quiet property read (zend BP_VAR_IS / coalesce) (#6472, #8902, #29228, #29266).
     * Hooked props with get: invoke get (zend_std_read_property; virtual get-only included).
     * Write-only hooked: backing probe only. Magic: __isset then __get, or __get alone
     * when __isset is absent (unlike isset(), which stays false without __isset).
     * ArrayObject/ArrayIterator::ARRAY_AS_PROPS — backing keys (spl_array.c; #22649, re-#22576).
     *
     * @return Frame|null catch frame when a hook/type guard throws into userland
     */
    public function fetchObjectPropertyForCoalesce(
        ObjectEntry $object,
        string $propName,
        Variable $dst,
        ?Frame $frame = null
    ): ?Frame {
        if (null !== $frame && $this->instancePropertyHasGetHook($object, $propName)) {
            try {
                $hookValue = $this->fetchPropertyWithHooks($object, $propName, $frame);
            } catch (VM\PropertyHookRefWriteSignal $signal) {
                return $signal->catchFrame;
            }
            if (null !== $hookValue) {
                $dst->copyFrom($hookValue->resolveIndirect());

                return null;
            }
        }
        $backing = $this->hookedPropertyBackingValue($object, $propName);
        if (false !== $backing) {
            $dst->copyFrom($backing);

            return null;
        }
        // ARRAY_AS_PROPS storage is not declarative object properties — mirror PROPERTY_FETCH read.
        if (SplArrayStorage::hasArrayAsProps($object)) {
            $key = new Variable(Variable::TYPE_STRING);
            $key->string($propName);
            if (SplArrayStorage::offsetExists($object, $key)) {
                $dst->copyFrom(SplArrayStorage::offsetGet($object, $key));
            } else {
                $dst->null();
            }

            return null;
        }
        // Overloaded / inaccessible: coalesce consults __get (zend_std_read_property IS-mode; #29228).
        if (
            null !== $frame
            && $this->propertyReadUsesMagicGet($object, $propName, $frame)
        ) {
            if ($this->hasInstanceMethod($object->class, '__isset')) {
                if (!$this->objectPropertyIsSet($object, $propName, $frame)) {
                    $dst->undefined();

                    return null;
                }
            }
            $got = $this->invokeMagicGet($object, $propName)->resolveIndirect();
            $dst->copyFrom($got);

            return null;
        }
        // No __get: inaccessible declared slots are unset for ?? (zend_std_has_property; #29503).
        if (null !== $frame) {
            $meta = $this->classPropertyMeta($object, $propName, $frame);
            if (
                null !== $meta
                && $this->declaredPropertyInaccessibleFromCaller(
                    $object,
                    $meta,
                    $propName,
                    $frame,
                    $meta->getVisibility
                )
            ) {
                $dst->undefined();

                return null;
            }
        }
        if ($object->hasProperty($propName)) {
            $dst->copyFrom($object->getProperty($propName));
        } else {
            $dst->undefined();
        }

        return null;
    }

    /**
     * empty($obj->prop) — uninitialized typed slots are empty without read (#6787, zend_object_handlers.c);
     * declared/dynamic slots use value truthiness (zend_is_true), not isset alone (#23983);
     * magic: __isset first, then __get + truthiness when set (#3298, zend_object_handlers.c).
     * Incomplete objects: E_WARNING + empty (true) (#19632).
     */
    public function emptyObjectProperty(ObjectEntry $object, string $propName, Frame $frame, Variable $dst): ?Frame
    {
        if (VM\IncompleteClassSupport::isIncomplete($object)) {
            VM\IncompleteClassSupport::emitAccessWarning($object, $this->context, $frame);
            $dst->bool(true);

            return null;
        }
        // SimpleXMLElement: empty($s->child) uses string cast of matching children (#19707, sxe.c).
        if (
            ext\simplexml\VmSimpleXml::CLASS_LC === strtolower($object->class->name)
            && ext\simplexml\SimpleXmlRegistry::has($object)
        ) {
            $dst->bool(ext\simplexml\VmSimpleXml::childPropertyIsEmpty($object, $propName));

            return null;
        }
        // Dom\HTMLDocument::$body|/title — computed get + truthiness (php-src html_document.c; #20540).
        $domHtmlEmpty = ext\dom\DomHtmlDocumentPropertySupport::propertyIsEmpty($object, $propName);
        if (null !== $domHtmlEmpty) {
            $dst->bool($domHtmlEmpty);

            return null;
        }
        // Dom\Element::$id|/className|/innerHTML|/outerHTML (#20532).
        $domHtmlElEmpty = ext\dom\DomHtmlElementPropertySupport::propertyIsEmpty($object, $propName);
        if (null !== $domHtmlElEmpty) {
            $dst->bool($domHtmlElEmpty);

            return null;
        }
        // Dom\* Node/CharacterData/ParentNode computed props (#21033, #21053, #21055).
        $domChildrenEmpty = ext\dom\DomNodePropertySupport::propertyIsEmpty($object, $propName);
        if (null !== $domChildrenEmpty) {
            $dst->bool($domChildrenEmpty);

            return null;
        }
        $catchFrame = $this->enforceWriteOnlyVirtualPropertyRead($object, $propName, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        try {
            if ($this->emptyHookedProperty($object, $propName, $frame, $dst)) {
                return null;
            }
        } catch (VM\PropertyHookRefWriteSignal $signal) {
            return $signal->catchFrame;
        }
        // ArrayObject/ArrayIterator::ARRAY_AS_PROPS — empty mirrors offset value truthiness (#22576).
        if (SplArrayStorage::hasArrayAsProps($object)) {
            $key = new Variable(Variable::TYPE_STRING);
            $key->string($propName);
            if (!SplArrayStorage::offsetExists($object, $key)) {
                $dst->bool(true);

                return null;
            }
            $dst->bool(!ext\standard\boolval::isTruthy(SplArrayStorage::offsetGet($object, $key)));

            return null;
        }
        // Accessible declared/dynamic slot: value truthiness (zend_is_true). Inaccessible declared
        // props are unset for empty unless __isset/__get overload applies (#23983).
        if (
            $object->hasProperty($propName)
            && $this->isInstancePropertyReadableForEmpty($object, $propName, $frame)
        ) {
            $props = $object->getRawProperties();
            if (isset($props[$propName]) && VM\TypedPropertyCheck::isUninitialized($props[$propName])) {
                $dst->bool(true);

                return null;
            }
            $slot = $object->getProperty($propName);
            $dst->bool(!ext\standard\boolval::isTruthy($slot));

            return null;
        }
        // Overload: zend_std_has_property(check_empty) — __isset first; only then __get + zend_is_true (#23983).
        if ($this->hasInstanceMethod($object->class, '__isset')) {
            if (!$this->objectPropertyIsSet($object, $propName, $frame)) {
                $dst->bool(true);

                return null;
            }
            if ($this->propertyReadUsesMagicGet($object, $propName, $frame)) {
                $read = new Variable();
                $this->deliverMagicGetRead($read, $object, $propName);
                $dst->bool(!ext\standard\boolval::isTruthy($read));

                return null;
            }
            // __isset true but no readable value (no __get / no slot) — treat as empty (null-like).
            $dst->bool(true);

            return null;
        }
        // __get without __isset, or inaccessible without magic: empty does not invoke __get.
        $dst->bool(true);

        return null;
    }

    /**
     * empty(Class::$prop) — uninitialized typed statics empty without read; else value truthiness (#23983, #6787).
     */
    public function emptyStaticProperty(string $classLc, string $propNameRaw, Frame $frame, Variable $dst): ?Frame
    {
        $visFrame = $this->enforceStaticPropertyReadVisibility($classLc, $propNameRaw, $frame);
        if (null !== $visFrame) {
            return $visFrame;
        }
        $propLc = strtolower($propNameRaw);
        $hooks = $this->resolveStaticPropertyHooks($classLc, $propLc);
        if (null !== $hooks && isset($hooks['get'])) {
            if ($this->emptyHookedStaticProperty($classLc, $propNameRaw, $frame, $dst)) {
                return null;
            }
        }
        $storage = $this->resolveStaticPropertyStorage($classLc, $propLc);
        if (null === $storage) {
            $dst->bool(true);

            return null;
        }
        $value = $storage->resolveIndirect();
        if ($value->isUndefined() || VM\TypedPropertyCheck::isUninitialized($value)) {
            $dst->bool(true);

            return null;
        }
        $dst->bool(!ext\standard\boolval::isTruthy($value));

        return null;
    }

    public function unsetObjectProperty(ObjectEntry $object, string $propName): void
    {
        // php-src date_interval_get_property_ptr_ptr — living fields ignore unset (#26180).
        if (VM\DateIntervalSupport::shouldNoopUnset($object, $propName)) {
            return;
        }
        $props = $object->getRawProperties();
        if (isset($props[$propName])) {
            $object->unsetProperty($propName);

            return;
        }
        // ArrayObject/ArrayIterator::ARRAY_AS_PROPS — unset mirrors offsetUnset (spl_array.c; #22576).
        if (SplArrayStorage::hasArrayAsProps($object)) {
            $key = new Variable(Variable::TYPE_STRING);
            $key->string($propName);
            SplArrayStorage::offsetUnset($object, $key);

            return;
        }
        if ($this->hasInstanceMethod($object->class, '__unset')) {
            $key = new Variable();
            $key->string($propName);
            $this->invokeInstanceMethod($object, '__unset', $key);
        }
    }

    /**
     * unset($obj->hooked) — invoke unset hook, or Error for any get/set-hooked property (#6471, #6502, #26373).
     * Zend rejects unset on hooked properties without a dedicated unset hook (backed get+set included).
     * Inaccessible declared props: __unset or Error before touching the slot (#25668).
     */
    private function dispatchHookedInstancePropertyUnset(ObjectEntry $object, string $propName, Frame $frame): ?Frame
    {
        if ($this->isPropertyHookRawWrite($frame, $propName)) {
            $this->unsetHookedInstancePropertyRaw($object, $propName);

            return null;
        }
        $inaccessibleFrame = $this->dispatchInaccessibleDeclaredPropertyUnset($object, $propName, $frame);
        if (false !== $inaccessibleFrame) {
            return $inaccessibleFrame;
        }
        if ($this->invokeInstancePropertyUnsetHook($object, $propName, $frame)) {
            return null;
        }
        $meta = $this->classPropertyMeta($object, $propName);
        if (null !== $meta && (null !== $meta->getHookMethodLc || null !== $meta->setHookMethodLc)) {
            $className = $object->class->name;
            if ('' !== $meta->declaringClassLc && isset($this->context->classes[$meta->declaringClassLc])) {
                $className = $this->context->classes[$meta->declaringClassLc]->name;
            }

            return $this->raiseVirtualPropertyHookUnsetError(
                $className,
                $propName,
                $frame
            );
        }
        $this->unsetHookedInstanceProperty($object, $propName);

        return null;
    }

    /** unset(Class::$hooked) — unset hook, or Error for get/set-hooked statics (#6502, #26373). */
    private function dispatchHookedStaticPropertyUnset(
        string $classLc,
        string $propLc,
        string $propNameRaw,
        Variable $storage,
        Frame $frame
    ): ?Frame {
        if ($this->isPropertyHookRawWrite($frame, $propNameRaw)) {
            $storage->reset();
            $storage->type = Variable::TYPE_UNDEFINED;

            return null;
        }
        if ($this->invokeStaticPropertyUnsetHook($classLc, $propLc, $propNameRaw, $frame)) {
            return null;
        }
        $hooks = $this->resolveStaticPropertyHooks($classLc, $propLc);
        if (null !== $hooks && (!empty($hooks['get']) || !empty($hooks['set']))) {
            $className = $this->context->classes[$classLc]->name ?? $classLc;

            return $this->raiseVirtualPropertyHookUnsetError(
                $className,
                $propNameRaw,
                $frame
            );
        }
        $storage->reset();
        $storage->type = Variable::TYPE_UNDEFINED;

        return null;
    }

    /**
     * isset/empty/?? backing probe — never invokes get hook (#6472, #8901, #8917, #8918).
     *
     * @return Variable|false false when the property is not hooked
     */
    private function hookedPropertyBackingValue(ObjectEntry $object, string $propName): Variable|false
    {
        if (!$this->instancePropertyHasHooks($object, $propName)) {
            return false;
        }
        $lcClass = strtolower($object->class->name);
        $propMeta = $this->context->propertyHookRegistry[$lcClass][$propName]
            ?? $this->context->propertyHookRegistry[$lcClass][strtolower($propName)]
            ?? null;
        if (is_array($propMeta)) {
            $backingName = $propMeta['setBacking'] ?? $propMeta['getBacking'] ?? null;
            if (null !== $backingName) {
                if ($object->hasProperty($backingName)) {
                    return $object->getProperty($backingName)->resolveIndirect();
                }
                $uninit = new Variable();
                $uninit->undefined();

                return $uninit;
            }
        }
        if ($object->hasProperty($propName)) {
            return $object->getProperty($propName)->resolveIndirect();
        }
        $uninit = new Variable();
        $uninit->undefined();

        return $uninit;
    }

    /**
     * isset/empty/?? backing probe for static hooked properties — never invokes get hook (#9683).
     *
     * @return bool|null null when the property is not hooked
     */
    private function issetHookedStaticPropertyWithoutGetHook(string $classLc, string $propNameRaw): ?bool
    {
        $backing = $this->hookedStaticPropertyBackingValue($classLc, $propNameRaw);
        if (false === $backing) {
            return null;
        }
        if ($backing->isUndefined() || VM\TypedPropertyCheck::isUninitialized($backing)) {
            return false;
        }

        return Variable::TYPE_NULL !== $backing->type;
    }

    /**
     * @return Variable|false false when the static property is not hooked
     */
    private function hookedStaticPropertyBackingValue(string $classLc, string $propNameRaw): Variable|false
    {
        $propLc = strtolower($propNameRaw);
        if (null === $this->resolveStaticPropertyHooks($classLc, $propLc)) {
            return false;
        }
        $propMeta = $this->context->propertyHookRegistry[$classLc][$propNameRaw]
            ?? $this->context->propertyHookRegistry[$classLc][$propLc]
            ?? null;
        if (is_array($propMeta)) {
            $backingName = $propMeta['setBacking'] ?? $propMeta['getBacking'] ?? null;
            if (null !== $backingName) {
                $backingStorage = $this->resolveStaticPropertyStorage($classLc, strtolower($backingName));
                if (null !== $backingStorage) {
                    return $backingStorage->resolveIndirect();
                }
                $uninit = new Variable();
                $uninit->undefined();

                return $uninit;
            }
        }
        $storage = $this->resolveStaticPropertyStorage($classLc, $propLc);
        if (null !== $storage) {
            return $storage->resolveIndirect();
        }
        $uninit = new Variable();
        $uninit->undefined();

        return $uninit;
    }

    private function instancePropertyHasHooks(ObjectEntry $object, string $propName): bool
    {
        $meta = $this->classPropertyMeta($object, $propName);
        if (null !== $meta && (null !== $meta->getHookMethodLc || null !== $meta->setHookMethodLc)) {
            return true;
        }
        $lcClass = strtolower($object->class->name);
        $propMeta = $this->context->propertyHookRegistry[$lcClass][$propName]
            ?? $this->context->propertyHookRegistry[$lcClass][strtolower($propName)]
            ?? null;

        return is_array($propMeta) && (isset($propMeta['get']) || isset($propMeta['set']));
    }

    private function instancePropertyHasGetHook(ObjectEntry $object, string $propName): bool
    {
        $meta = $this->classPropertyMeta($object, $propName);
        if (null !== $meta && null !== $meta->getHookMethodLc) {
            return true;
        }
        $lcClass = strtolower($object->class->name);
        $propMeta = $this->context->propertyHookRegistry[$lcClass][$propName]
            ?? $this->context->propertyHookRegistry[$lcClass][strtolower($propName)]
            ?? null;

        return is_array($propMeta) && isset($propMeta['get']);
    }

    /**
     * empty(Class::$hooked) — uninitialized/unset distinct backing probes storage only;
     * initialized get-hook paths invoke get (#23983, #9683, zend_property_hooks.c).
     */
    private function emptyHookedStaticProperty(string $classLc, string $propNameRaw, Frame $frame, Variable $dst): bool
    {
        $propLc = strtolower($propNameRaw);
        $hooks = $this->resolveStaticPropertyHooks($classLc, $propLc);
        if (null === $hooks) {
            return false;
        }
        if (!is_array($hooks) || !isset($hooks['get'])) {
            $backing = $this->hookedStaticPropertyBackingValue($classLc, $propNameRaw);
            if (false === $backing) {
                return false;
            }
            $uninit = $backing->isUndefined() || VM\TypedPropertyCheck::isUninitialized($backing);
            if ($uninit) {
                $dst->bool(true);

                return true;
            }
            $dst->bool(!ext\standard\boolval::isTruthy($backing));

            return true;
        }
        $issetProbe = $this->issetHookedStaticPropertyWithoutGetHook($classLc, $propNameRaw);
        if (false === $issetProbe) {
            // Uninitialized / unset backing — empty without invoking get (#9683).
            $dst->bool(true);

            return true;
        }
        $hookValue = $this->fetchStaticPropertyWithHooks($classLc, $propNameRaw, $hooks['get'], $frame);
        $value = $hookValue->resolveIndirect();
        if ($value->isUndefined() || VM\TypedPropertyCheck::isUninitialized($value)) {
            $dst->bool(true);

            return true;
        }
        $dst->bool(!ext\standard\boolval::isTruthy($value));

        return true;
    }

    /**
     * True when empty($obj->prop) may read the declared slot (public/accessible), not overload (#23983).
     */
    private function isInstancePropertyReadableForEmpty(ObjectEntry $object, string $propName, Frame $frame): bool
    {
        if ($this->propertyReadUsesMagicGet($object, $propName, $frame)) {
            return false;
        }
        $meta = $this->classPropertyMeta($object, $propName, $frame);
        if (null === $meta) {
            // Dynamic property on the object — readable.
            return true;
        }
        if ($this->isParentPrivatePropertyInvisibleFromCaller($meta, $frame, $object)) {
            return false;
        }
        $readVis = PropertyVisibility::effectiveGetVisibility($meta->visibility, $meta->getVisibility);
        if (MethodVisibility::isPublic($readVis)) {
            return true;
        }
        $declaringDisplay = $this->context->classes[$meta->declaringClassLc]->name
            ?? $meta->declaringClassLc;
        try {
            PropertyVisibility::assertAccessible(
                $meta->visibility,
                $this->callerClassLc($frame),
                $meta->declaringClassLc,
                $declaringDisplay,
                $propName,
                strtolower($object->class->name),
                fn (string $classLc, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLc, $ancestorLc),
                $meta->getVisibility
            );

            return true;
        } catch (\LogicException $e) {
            return false;
        }
    }

    /**
     * empty($obj->hooked) — php-src zend_std_has_property(ZEND_PROPERTY_NOT_EMPTY): when a get
     * hook exists, always invoke it then zend_is_true (#29214, #16935, zend_property_hooks.c).
     */
    private function emptyHookedProperty(ObjectEntry $object, string $propName, Frame $frame, Variable $dst): bool
    {
        if (!$this->instancePropertyHasHooks($object, $propName)) {
            return false;
        }
        if (!$this->instancePropertyHasGetHook($object, $propName)) {
            $backing = $this->hookedPropertyBackingValue($object, $propName);
            if (false === $backing) {
                return false;
            }
            $uninit = $backing->isUndefined() || VM\TypedPropertyCheck::isUninitialized($backing);
            if ($uninit) {
                $dst->bool(true);

                return true;
            }
            $dst->bool(!ext\standard\boolval::isTruthy($backing));

            return true;
        }
        $hookValue = $this->fetchPropertyWithHooks($object, $propName, $frame);
        if (null === $hookValue) {
            $backing = $this->hookedPropertyBackingValue($object, $propName);
            if (false === $backing) {
                return false;
            }
            $dst->bool(!ext\standard\boolval::isTruthy($backing));

            return true;
        }
        $value = $hookValue->resolveIndirect();
        $dst->bool(!ext\standard\boolval::isTruthy($value));

        return true;
    }

    private function invokeInstancePropertyUnsetHook(ObjectEntry $object, string $propName, Frame $frame): bool
    {
        $meta = $this->classPropertyMeta($object, $propName);
        $unsetLc = $meta?->unsetHookMethodLc
            ?? strtolower(SourcePreprocessor\PropertyHooks::unsetHookMethodName($propName));
        if (!isset($object->class->methods[$unsetLc])) {
            return false;
        }
        $func = $object->class->methods[$unsetLc];
        if (!$func instanceof Func\PHP) {
            return false;
        }
        $thisVar = new Variable();
        $thisVar->object($object);
        $this->invokePhpFunctionWithPropertyHookRaw($func, $propName, $frame, $thisVar);

        return true;
    }

    private function invokeStaticPropertyUnsetHook(
        string $classLc,
        string $propLc,
        string $propNameRaw,
        Frame $frame
    ): bool {
        if (!isset($this->context->classes[$classLc])) {
            return false;
        }
        $entry = $this->context->classes[$classLc];
        $hooks = $this->resolveStaticPropertyHooks($classLc, $propLc) ?? [];
        $unsetLc = $hooks['unset']
            ?? strtolower(SourcePreprocessor\PropertyHooks::unsetHookMethodName($propNameRaw));
        if (!isset($entry->methods[$unsetLc])) {
            return false;
        }
        $func = $entry->methods[$unsetLc];
        if (!$func instanceof Func\PHP) {
            return false;
        }
        $this->invokeStaticPropertyHookRaw($func, $propNameRaw, $classLc, $frame);

        return true;
    }

    /**
     * unset($obj->hooked) — reset hook backing + declared slot (Zend zend_property_hooks.c, #6471).
     */
    private function unsetHookedInstanceProperty(ObjectEntry $object, string $propName): void
    {
        $this->resetHookedPropertyBackingField($object, $propName);
        $this->unsetObjectProperty($object, $propName);
    }

    /**
     * unset($this->hooked) inside a property hook — backing storage only, no hook re-entry (#9625).
     */
    private function unsetHookedInstancePropertyRaw(ObjectEntry $object, string $propName): void
    {
        $lcClass = strtolower($object->class->name);
        $propMeta = $this->context->propertyHookRegistry[$lcClass][$propName]
            ?? $this->context->propertyHookRegistry[$lcClass][strtolower($propName)]
            ?? null;
        $backingName = $propName;
        if (is_array($propMeta)) {
            $backingName = $propMeta['setBacking'] ?? $propMeta['getBacking'] ?? $propName;
        }
        if ($object->hasProperty($backingName)) {
            // Always UNDEF after unset — distinguish from initialized null so isset/empty
            // can invoke get for `?T $backing = null` defaults (#23339 / re-#17260).
            $slot = $object->getProperty($backingName);
            $slot->reset();
            $slot->type = Variable::TYPE_UNDEFINED;
        }
        if (0 !== strcasecmp($backingName, $propName)) {
            $this->unsetObjectProperty($object, $propName);
        }
    }

    /** Clear registry-recorded get/set backing field after hooked-property unset (#6471, #5191, #11617, #23339). */
    private function resetHookedPropertyBackingField(ObjectEntry $object, string $propName): void
    {
        $lcClass = strtolower($object->class->name);
        $propMeta = $this->context->propertyHookRegistry[$lcClass][$propName]
            ?? $this->context->propertyHookRegistry[$lcClass][strtolower($propName)]
            ?? null;
        if (!is_array($propMeta)) {
            return;
        }
        $backingName = $propMeta['setBacking'] ?? $propMeta['getBacking'] ?? null;
        if (null === $backingName || !$object->hasProperty($backingName)) {
            return;
        }
        // UNDEF (not null) so nullable init-null still runs get on isset/empty (#23339).
        $slot = $object->getProperty($backingName);
        $slot->reset();
        $slot->type = Variable::TYPE_UNDEFINED;
    }

    /**
     * True when $slot is an indirect binding shared with another local (Zend ref chain).
     * Used by (unset) cast: only break references, not ordinary locals (#3517).
     *
     * @param array<int, Variable> $scope
     */
    private function slotIsReferenceBinding(Variable $slot, array $scope): bool
    {
        if (Variable::TYPE_INDIRECT !== $slot->type) {
            return false;
        }
        $target = $slot->resolveIndirect();
        foreach ($scope as $other) {
            if ($other === $slot) {
                continue;
            }
            if ($other === $target || $other->resolveIndirect() === $target) {
                return true;
            }
        }

        return false;
    }

    /** (string) cast on objects — invoke __toString (Zend zend_operators.c, issue #3421). */
    public function castObjectToString(ObjectEntry $object): string
    {
        if (VM\ResourceSupport::isResourceObject($object)) {
            $var = new Variable();
            $var->object($object);

            return $var->toString($this);
        }
        if (EnumCaseSupport::isEnumCase($object)) {
            throw new \Error(
                'Object of class '.$object->class->name.' could not be converted to string'
            );
        }
        $typeString = VM\ReflectionTypeSupport::tryObjectTypeString($object);
        if (null !== $typeString) {
            return $typeString;
        }
        if (!$this->hasInstanceMethod($object->class, '__tostring')) {
            throw new \Error(
                'Object of class '.$object->class->name.' could not be converted to string'
            );
        }
        $this->context->coercingObjectToString = true;
        try {
            $result = $this->invokeMagicToString($object)->resolveIndirect();
        } finally {
            $this->context->coercingObjectToString = false;
        }

        return $result->toString();
    }

    /**
     * Convert a value to string for echo/print (Zend zend_print_variable parity, #3564).
     *
     * php-src: Zend/zend_operators.c — cast to string via __toString when defined.
     */
    public function valueToPrintString(Variable $var, ?Frame $frame = null): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $var->type) {
            throw new \Error(
                VM\ValueEchoSupport::objectToStringErrorMessage($var->toEnumCase()->enumClass->name)
            );
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            return $var->toString($this, $frame);
        }
        $object = $var->toObject();
        if (VM\ResourceSupport::isResourceObject($object)) {
            return $var->toString($this, $frame);
        }
        if (EnumCaseSupport::isEnumCase($object)) {
            throw new \Error(VM\ValueEchoSupport::objectToStringErrorMessage($object->class->name));
        }
        if (!$this->hasInstanceMethod($object->class, '__tostring')) {
            throw new \Error(VM\ValueEchoSupport::objectToStringErrorMessage($object->class->name));
        }
        $this->context->coercingObjectToString = true;
        try {
            $result = $this->invokeMagicToString($object, $frame)->resolveIndirect();
        } finally {
            $this->context->coercingObjectToString = false;
        }

        return $result->toString($this, $frame);
    }

    /**
     * Invoke Iterator protocol methods during foreach (Zend zend_iterators.c parity, #3234).
     */
    public function invokeForeachInstanceMethod(Frame $_parentFrame, Variable $receiver, string $methodName): Variable
    {
        return $this->invokeInstanceMethod($receiver->toObject(), $methodName);
    }

    /**
     * Properties for var_dump / print_r when __debugInfo is defined (Zend parity, #3259, #29379).
     *
     * Integer HashTable keys stay ints so var_dump prints `[0]=>` not `["0"]=>`
     * (php-src zend_array / php_var_dump; SplFixedArray #19783).
     *
     * @return array<int|string, Variable>
     */
    public function getObjectDebugProperties(ObjectEntry $object, ?Frame $frame = null): array
    {
        // Closure: Zend zend_closure_get_debug_info handler — not a __debugInfo method (#22565).
        if (null !== $object->closureState) {
            $props = [];
            foreach ($object->closureState->debugInfoEntries() as $name => $value) {
                $copy = new Variable();
                $copy->copyFrom($value->resolveIndirect());
                $props[$name] = $copy;
            }

            return $props;
        }
        // WeakMap: Zend zend_weakmap_get_properties_for(DEBUG) — key/value pairs, not storage (#24522).
        if (WeakRefSupport::isWeakMap($object)) {
            return WeakRefSupport::debugInfoEntries($object);
        }
        if ($this->hasInstanceMethod($object->class, '__debuginfo')) {
            // php-src zend_std_get_debug_info: hook throw → zend_exception_error(E_WARNING)
            // then zend_error_noreturn(E_ERROR, "__debuginfo() must return an array") (#25748).
            // Caller try/catch must not absorb the hook exception.
            try {
                $result = $this->invokeInstanceMethod($object, '__debugInfo')->resolveIndirect();
            } catch (ScriptExit $e) {
                throw $e;
            } catch (VM\BuiltinCallbackCatchRedirect $e) {
                throw $e;
            } catch (VM\MagicMethodInvocationAborted $e) {
                throw $e;
            } catch (\Throwable $hookException) {
                $this->raiseDebugInfoMustReturnArrayFatal($frame, $hookException, $object);
            }
            if (Variable::TYPE_NULL === $result->type) {
                return [];
            }
            if (Variable::TYPE_ARRAY !== $result->type) {
                $this->raiseDebugInfoMustReturnArrayFatal($frame, null, $object);
            }
            $props = [];
            foreach ($result->toArray()->iterateKeyed(true) as [$key, $value]) {
                $name = Variable::TYPE_INTEGER === $key->type
                    ? $key->toInt()
                    : $key->toString();
                $copy = new Variable();
                $copy->copyFrom($value->resolveIndirect());
                $props[$name] = $copy;
            }

            return $props;
        }
        // DateInterval: Zend date_interval_get_properties DEBUG wire (#22473).
        // Same bag as get_object_vars / (array) cast (#22446) — never walk raw slots
        // (uninit date_string prototype is TYPE_STRING without $string → Variable::$string Error).
        $intervalMap = $this->dateIntervalObjectVarsPropertyMap($object);
        if (null !== $intervalMap) {
            return $intervalMap;
        }
        // php-src ext/date/php_date.c — date_object_get_properties_for(DEBUG) (#22462).
        // User props first, then Zend date/timezone wire; never leak __dt_* storage.
        $dateWire = DateTimeSupport::tryDebugWirePropertyMap($object, $this->context);
        if (null !== $dateWire) {
            $user = null !== $frame
                ? DateTimeSupport::filterInternalStorageFromMangledVars(
                    $this->collectDebugPropertiesForBuiltin($object, $frame)
                )
                : DateTimeSupport::filterInternalStorageFromMangledVars(
                    $this->rawPropertiesAsDebugMap($object)
                );

            return $user + $dateWire;
        }
        if (null !== $frame) {
            return $this->collectDebugPropertiesForBuiltin($object, $frame);
        }

        return $object->class->getProperties($object->getRawProperties(), ClassEntry::PROP_PURPOSE_DEBUG);
    }

    /**
     * php-src zend_std_get_debug_info failure: optional Warning for hook throw, then E_ERROR (#25748).
     *
     * Warning stack frames match Zend engine-invoke shape (#28618):
     * `[internal function]: Class->__debugInfo()` then `var_dump()`/`print_r()`/…
     *
     * @return never
     */
    private function raiseDebugInfoMustReturnArrayFatal(
        ?Frame $frame,
        ?\Throwable $hookException,
        ObjectEntry $object,
    ): never {
        if (null !== $hookException) {
            VM\ExceptionSupport::emitNativeUncaughtWarning(
                $hookException,
                null,
                $this->context->errors->getDisplayErrors(),
                VM\ExceptionTrace::buildDebugInfoEngineInvokeTrace($object, $frame),
            );
        }
        $message = '__debuginfo() must return an array';
        if (null !== $frame) {
            [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        } else {
            $file = '';
            $line = 0;
            $stack = $this->context->runStackFrames();
            if ([] !== $stack) {
                [$file, $line] = VM\ExceptionSupport::userFatalSite($stack[0]);
            }
        }
        $this->context->errors->recordLastError(
            VM\ErrorReporter::E_ERROR,
            $message,
            $file,
            $line
        );
        VM\ErrorReporter::writeCliErrorOutput(
            VM\ErrorReporter::E_ERROR,
            $message,
            '' !== $file ? $file : null,
            $line,
            $this->context->errors->getDisplayErrors()
        );
        throw new ScriptExit(255);
    }

    /**
     * Raw instance slots as a debug property map (no hooks) — DateTime DEBUG fallback without Frame.
     *
     * @return array<string, Variable>
     */
    private function rawPropertiesAsDebugMap(ObjectEntry $object): array
    {
        /** @var array<string, Variable> $result */
        $result = [];
        foreach ($object->getRawProperties() as $name => $prop) {
            $value = $prop->resolveIndirect();
            if (VM\TypedPropertyCheck::isUninitialized($value)) {
                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($value);
            $result[$name] = $copy;
        }

        return $result;
    }

    /**
     * Lowercase names of separate hook backing fields — hidden from debug/var_export (#8854, zend_property_hooks.c).
     *
     * @return array<string, true>
     */
    private function separatePropertyHookBackingNameSet(ObjectEntry $object): array
    {
        /** @var array<string, true> $set */
        $set = [];
        foreach (array_reverse(\PHPCompiler\ext\standard\VmReflection::classHierarchyChain($object->class, $this->context)) as $class) {
            $lcClass = strtolower($class->name);
            foreach ($this->context->propertyHookRegistry[$lcClass] ?? [] as $hookProp => $meta) {
                if (!is_array($meta)) {
                    continue;
                }
                $backingName = $meta['setBacking'] ?? $meta['getBacking'] ?? null;
                if (null === $backingName || 0 === strcasecmp($backingName, $hookProp)) {
                    continue;
                }
                $set[strtolower($backingName)] = true;
            }
        }

        return $set;
    }

    /**
     * get_mangled_object_vars() — mangled keys, dynamic props, raw backing (#3497, #10491, #22445, #29379).
     *
     * php-src: Zend/zend_builtin_functions.c — ZEND_FUNCTION(get_mangled_object_vars)
     * uses zend_get_properties_no_lazy_init (raw property table), not get-hook reads.
     * DateTime / DateTimeImmutable / DateTimeZone store state in C on Zend — filter
     * compiler __dt_* storage keys (#22445).
     *
     * @return array<string, Variable>
     */
    public function collectMangledObjectVarsForBuiltin(ObjectEntry $object, Frame $frame): array
    {
        // DateInterval: Zend date_interval_get_properties wire (not raw slots / uninit date_string) (#22446).
        $dateMap = $this->dateIntervalObjectVarsPropertyMap($object);
        if (null !== $dateMap) {
            return $dateMap;
        }

        // DateTime / DateTimeImmutable / DateTimeZone: Zend raw property table is empty (#22445).
        return DateTimeSupport::filterInternalStorageFromMangledVars(
            $this->collectDebugPropertiesForBuiltin($object, $frame)
        );
    }

    /**
     * array_walk / array_walk_recursive object property keys — Zend-mangled (#23552).
     *
     * @return list<string>
     */
    public function collectObjectArrayWalkPropertyKeys(ObjectEntry $object, Frame $frame): array
    {
        $ctx = $this->context;
        $keys = [];
        $seenLc = [];
        $seenPrivate = [];
        $seenDeclaredLc = [];
        foreach (array_reverse(\PHPCompiler\ext\standard\VmReflection::classHierarchyChain($object->class, $ctx)) as $class) {
            foreach ($class->properties as $meta) {
                if ($meta->phpInvisible) {
                    continue;
                }
                $lc = strtolower($meta->name);
                $seenDeclaredLc[$lc] = true;
                $isPrivate = ($meta->visibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0;
                if ($isPrivate) {
                    $privKey = ($meta->declaringClassLc !== '' ? $meta->declaringClassLc : strtolower($class->name))."\0".$lc;
                    if (isset($seenPrivate[$privKey])) {
                        continue;
                    }
                    $seenPrivate[$privKey] = true;
                } else {
                    if (isset($seenLc[$lc])) {
                        continue;
                    }
                    $seenLc[$lc] = true;
                }
                if (DateTimeSupport::isInternalStorageProperty($meta->name)) {
                    continue;
                }
                if ($meta->propertyHookVirtual && null === $meta->getHookMethodLc) {
                    continue;
                }
                // Presence only — avoid resolveIndirect during key listing (keeps by-ref slots healthy).
                if (!$object->hasPropertyForMeta($meta) && $meta->prototype->hasDeclaredTypeConstraint()) {
                    continue;
                }
                $keys[] = \PHPCompiler\ext\standard\VmReflection::manglePropertyKey($meta, $ctx);
            }
        }
        foreach ($object->getRawProperties() as $name => $_) {
            $nameLc = strtolower($name);
            if (isset($seenDeclaredLc[$nameLc]) || isset($seenLc[$nameLc])) {
                continue;
            }
            if (DateTimeSupport::isInternalStorageProperty((string) $name)) {
                continue;
            }
            $keys[] = (string) $name;
        }

        return $keys;
    }

    /**
     * var_dump()/print_r()/debug_zval_dump() property list — mangled keys, no get hooks (#29379).
     *
     * php-src: zend_get_properties_for(..., ZEND_PROP_PURPOSE_DEBUG) walks the property table
     * without zend_read_property_ex — virtual hooked props are omitted; backed hooks dump the
     * backing slot (re-#6604 wrongly invoked get). var_export / get_object_vars still use get.
     *
     * @return array<string, Variable>
     */
    private function collectDebugPropertiesForBuiltin(ObjectEntry $object, Frame $frame): array
    {
        $ctx = $this->context;
        $hookBackingLc = $this->separatePropertyHookBackingNameSet($object);
        /** @var array<string, Variable> $result */
        $result = [];
        /** @var array<string, true> $seenLc */
        $seenLc = [];
        /** @var array<string, true> $seenPrivate */
        $seenPrivate = [];
        /** @var array<string, true> $seenDeclaredLc — skip raw re-add of declared slots (#22521) */
        $seenDeclaredLc = [];
        foreach (array_reverse(\PHPCompiler\ext\standard\VmReflection::classHierarchyChain($object->class, $ctx)) as $class) {
            foreach ($class->properties as $meta) {
                $lc = strtolower($meta->name);
                $isPrivate = ($meta->visibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0;
                $seenDeclaredLc[$lc] = true;
                if ($isPrivate) {
                    $privKey = ($meta->declaringClassLc !== '' ? $meta->declaringClassLc : strtolower($class->name))."\0".$lc;
                    if (isset($seenPrivate[$privKey]) || isset($hookBackingLc[$lc])) {
                        continue;
                    }
                    $seenPrivate[$privKey] = true;
                } else {
                    if (isset($seenLc[$lc]) || isset($hookBackingLc[$lc])) {
                        continue;
                    }
                    $seenLc[$lc] = true;
                }
                if ($meta->phpInvisible) {
                    continue;
                }
                // Virtual hooked properties: no DEBUG slot — omit entirely (#29379, zend_property_hooks.c).
                if ($meta->propertyHookVirtual) {
                    continue;
                }
                // Backed hooked property: dump raw backing, never invoke get (#29379).
                if (null !== $meta->getHookMethodLc || null !== $meta->setHookMethodLc) {
                    if (!$object->hasPropertyForMeta($meta)) {
                        continue;
                    }
                    $value = $object->getPropertyForMeta($meta)->resolveIndirect();
                    if (VM\TypedPropertyCheck::isUninitialized($value)) {
                        continue;
                    }
                    $key = \PHPCompiler\ext\standard\VmReflection::manglePropertyKey($meta, $ctx);
                    $copy = new Variable();
                    $copy->copyFrom($value);
                    $result[$key] = $copy;

                    continue;
                }
                if (!$object->hasPropertyForMeta($meta)) {
                    if (!$meta->prototype->hasDeclaredTypeConstraint()) {
                        $key = \PHPCompiler\ext\standard\VmReflection::manglePropertyKey($meta, $ctx);
                        $copy = new Variable();
                        $copy->null();
                        $result[$key] = $copy;
                    }

                    continue;
                }
                $value = $object->getPropertyForMeta($meta)->resolveIndirect();
                if (VM\TypedPropertyCheck::isUninitialized($value)) {
                    if ($meta->prototype->hasDeclaredTypeConstraint()) {
                        continue;
                    }
                    $key = \PHPCompiler\ext\standard\VmReflection::manglePropertyKey($meta, $ctx);
                    $copy = new Variable();
                    $copy->null();
                    $result[$key] = $copy;

                    continue;
                }
                $key = \PHPCompiler\ext\standard\VmReflection::manglePropertyKey($meta, $ctx);
                $copy = new Variable();
                $copy->copyFrom($value);
                $result[$key] = $copy;
            }
        }
        foreach ($object->getRawProperties() as $name => $prop) {
            $nameLc = strtolower($name);
            if (isset($seenDeclaredLc[$nameLc]) || isset($seenLc[$nameLc]) || isset($hookBackingLc[$nameLc])) {
                continue;
            }
            $value = $prop->resolveIndirect();
            if (VM\TypedPropertyCheck::isUninitialized($value)) {
                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($value);
            $result[$name] = $copy;
        }

        return $result;
    }

    /**
     * Declared + dynamic properties for get_object_vars() get-hook reads (#5203, #6453).
     *
     * php-src: zend_hooked_object_build_properties + zend_read_property_ex
     *
     * @return array<string, Variable>
     */
    public function collectObjectVarsForBuiltin(ObjectEntry $object, Frame $frame): array
    {
        // Enum cases: Zend zend_enum.c name/value pseudo-properties (foreach + get_object_vars; #23433).
        if (VM\EnumCaseSupport::isEnumCase($object)) {
            $caseVar = new Variable(Variable::TYPE_OBJECT);
            $caseVar->object($object);

            return VM\EnumCaseSupport::objectVarsForCaseVariable($caseVar);
        }
        // DateInterval: Zend date_interval_get_properties — public wire despite isInternal (#22446).
        $dateMap = $this->dateIntervalObjectVarsPropertyMap($object);
        if (null !== $dateMap) {
            return $dateMap;
        }

        // DateTime* / DateTimeZone: Zend property table has no __dt_* storage (#23432, #22445).
        // Base internal CE short-circuits empty above; subclasses still declare inherited slots.
        return DateTimeSupport::filterInternalStorageFromMangledVars(
            $this->collectObjectPropertiesForBuiltin($object, $frame, false)
        );
    }

    /**
     * php-src ext/date/php_date.c — date_interval_get_properties for get_object_vars / mangled / DEBUG (#22446, #22473).
     *
     * Reuses the same Zend wire as var_export / (array) cast ({@see DateIntervalSupport::varExportPropertyMap}).
     * DateTime* stay empty from global scope (#10719); only DateInterval exposes this bag.
     *
     * @return array<string, Variable>|null
     */
    private function dateIntervalObjectVarsPropertyMap(ObjectEntry $object): ?array
    {
        if (DateIntervalSupport::CLASS_DATEINTERVAL !== strtolower($object->class->name)) {
            return null;
        }

        return DateIntervalSupport::varExportPropertyMap($object);
    }

    /**
     * Internal classes that still publish PHP-visible CE properties via get_object_vars
     * (php-src reflection_object handlers; #22515). DateTime* and similar stay empty.
     */
    private function internalClassExportsGetObjectVars(ObjectEntry $object): bool
    {
        $lc = strtolower($object->class->name);
        return match ($lc) {
            VM\ReflectionSupport::REFLECTION_CLASS,
            VM\ReflectionSupport::REFLECTION_OBJECT,
            VM\ReflectionSupport::REFLECTION_METHOD => true,
            default => false,
        };
    }

    /**
     * All set instance properties for var_export() — ignores caller visibility (#3594).
     *
     * php-src: zend_get_properties_for(..., ZEND_PROP_PURPOSE_VAR_EXPORT)
     *
     * @return array<string, Variable>
     */
    public function collectVarExportPropertiesForBuiltin(ObjectEntry $object, Frame $frame): array
    {
        $lc = strtolower($object->class->name);
        if (DateTimeSupport::CLASS_DATETIME === $lc || DateTimeSupport::CLASS_DATETIMEIMMUTABLE === $lc) {
            return DateTimeSupport::varExportPropertyMap($object);
        }
        if (DateTimeSupport::CLASS_DATETIMEZONE === $lc) {
            return DateTimeSupport::varExportTimezonePropertyMap($object);
        }
        if (DateIntervalSupport::CLASS_DATEINTERVAL === $lc) {
            return DateIntervalSupport::varExportPropertyMap($object);
        }
        if (DatePeriodSupport::CLASS_DATEPERIOD === $lc) {
            return DatePeriodSupport::varExportPropertyMap($object);
        }
        // Zend zend_exceptions.c — SensitiveParameterValue get_properties_for(VAR_EXPORT) is empty (#23042).
        if (VM\SensitiveParamSupport::CLASS_NAME === $object->class->name
            || strtolower(VM\SensitiveParamSupport::CLASS_NAME) === $lc) {
            return [];
        }
        // Zend zend_weakrefs.c — WeakMap get_properties_for(VAR_EXPORT) returns NULL (#24522).
        if (WeakRefSupport::isWeakMap($object)) {
            return [];
        }

        return $this->collectObjectPropertiesForBuiltin($object, $frame, true);
    }

    /**
     * @return array<string, Variable>
     */
    private function collectObjectPropertiesForBuiltin(ObjectEntry $object, Frame $frame, bool $forVarExport): array
    {
        $ctx = $this->context;
        $scopeFrame = $frame;
        while (null !== $scopeFrame && null !== $scopeFrame->handler) {
            $scopeFrame = $scopeFrame->parent;
        }
        if (null === $scopeFrame) {
            $scopeFrame = $frame;
        }
        $callerClassLc = $forVarExport ? null : $this->callerClassLc($scopeFrame);
        if (
            !$forVarExport
            && null === $callerClassLc
            && $object->class->isInternal
            && !$object->class->allowsDynamicProperties
            && !$this->internalClassExportsGetObjectVars($object)
        ) {
            return [];
        }
        $hookBackingLc = $forVarExport ? $this->separatePropertyHookBackingNameSet($object) : [];
        /** @var array<string, Variable> $result */
        $result = [];
        /** @var array<string, true> $seenLc — unmangled result keys already taken (first-wins; #22547) */
        $seenLc = [];
        /** @var array<string, true> $seenPrivate — declaring-class private slots (#22521 / #22547) */
        $seenPrivate = [];
        /** @var array<string, true> $seenDeclaredLc — skip raw re-add of declared slots */
        $seenDeclaredLc = [];
        foreach (array_reverse(\PHPCompiler\ext\standard\VmReflection::classHierarchyChain($object->class, $ctx)) as $class) {
            foreach ($class->properties as $meta) {
                $lc = strtolower($meta->name);
                $isPrivate = ($meta->visibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0;
                $seenDeclaredLc[$lc] = true;
                if ($isPrivate) {
                    $privKey = ($meta->declaringClassLc !== '' ? $meta->declaringClassLc : strtolower($class->name))."\0".$lc;
                    if (isset($seenPrivate[$privKey]) || isset($hookBackingLc[$lc])) {
                        continue;
                    }
                    $seenPrivate[$privKey] = true;
                    // Parent private must not claim the result key when inaccessible — child
                    // private/public with the same name may still be visible (#22547).
                    if (isset($seenLc[$lc])) {
                        continue;
                    }
                } elseif (isset($seenLc[$lc]) || isset($hookBackingLc[$lc])) {
                    continue;
                }
                if (JitMcjitEmbed::isEmbedClassPadProperty($meta->name)) {
                    continue;
                }
                if ($meta->phpInvisible) {
                    if (!$isPrivate) {
                        $seenLc[$lc] = true;
                    }
                    continue;
                }
                if (!$forVarExport && !$this->isPropertyAccessibleForObjectVars($meta, $callerClassLc)) {
                    continue;
                }
                // Accessible (or var_export): claim unmangled key — first-wins vs later same name.
                $seenLc[$lc] = true;
                if ($meta->propertyHookVirtual && null === $meta->getHookMethodLc) {
                    continue;
                }
                if (null !== $meta->getHookMethodLc) {
                    $hookValue = $this->fetchPropertyWithHooks($object, $meta->name, $scopeFrame);
                    if (null === $hookValue) {
                        continue;
                    }
                    $value = $hookValue->resolveIndirect();
                    if ($forVarExport) {
                        if (VM\TypedPropertyCheck::omitFromPropertyEnumeration($value)) {
                            continue;
                        }
                    } elseif (VM\TypedPropertyCheck::isUninitialized($value)) {
                        continue;
                    }
                    $copy = new Variable();
                    $copy->copyFrom($value);
                    $result[$meta->name] = $copy;

                    continue;
                }
                if (!$object->hasPropertyForMeta($meta)) {
                    if (!$forVarExport && !$meta->prototype->hasDeclaredTypeConstraint()) {
                        $copy = new Variable();
                        $copy->null();
                        $result[$meta->name] = $copy;
                    }

                    continue;
                }
                $value = $object->getPropertyForMeta($meta)->resolveIndirect();
                if ($forVarExport) {
                    if (VM\TypedPropertyCheck::omitFromPropertyEnumeration($value)) {
                        continue;
                    }
                } elseif (
                    Variable::TYPE_UNDEFINED === $value->type
                    && (
                        $meta->prototype->hasDeclaredTypeConstraint()
                        || null !== $meta->default
                        || $meta->hasRuntimeDefaultInit()
                        || !$meta->prototype->isUndefined()
                    )
                ) {
                    // unset($obj->prop) — omit; never-set untyped falls through to null below (#1370).
                    continue;
                } elseif (VM\TypedPropertyCheck::isUninitialized($value)) {
                    if ($meta->prototype->hasDeclaredTypeConstraint()) {
                        continue;
                    }
                    $copy = new Variable();
                    $copy->null();
                    $result[$meta->name] = $copy;

                    continue;
                }
                $copy = new Variable();
                $copy->copyFrom($value);
                $result[$meta->name] = $copy;
            }
        }
        foreach ($object->getRawProperties() as $name => $prop) {
            $nameLc = strtolower($name);
            if (isset($seenDeclaredLc[$nameLc]) || isset($seenLc[$nameLc]) || isset($hookBackingLc[$nameLc])) {
                continue;
            }
            if (JitMcjitEmbed::isEmbedClassPadProperty($name)) {
                continue;
            }
            $value = $prop->resolveIndirect();
            if (VM\TypedPropertyCheck::omitFromPropertyEnumeration($value)) {
                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($value);
            $result[$name] = $copy;
        }

        return $result;
    }

    public function collectPublicPropertiesForSerialize(ObjectEntry $object, Frame $frame): array
    {
        if (SplArrayStorage::hasState($object)) {
            return SplArrayStorage::collectJsonEncodeProperties($object);
        }
        $ctx = $this->context;
        $hookFrame = $this->resolvePropertyHookParentFrame($frame);
        /** @var array<string, Variable> $result */
        $result = [];
        /** @var array<string, true> $seenLc */
        $seenLc = [];
        foreach (array_reverse(ext\standard\VmReflection::classHierarchyChain($object->class, $ctx)) as $class) {
            foreach ($class->properties as $meta) {
                $lc = strtolower($meta->name);
                if (isset($seenLc[$lc])) {
                    continue;
                }
                $seenLc[$lc] = true;
                if (!MethodVisibility::isPublic($meta->visibility)) {
                    continue;
                }
                if ($meta->propertyHookVirtual && null === $meta->getHookMethodLc) {
                    continue;
                }
                if (null !== $meta->getHookMethodLc) {
                    $hookValue = $this->fetchPropertyWithHooks($object, $meta->name, $hookFrame);
                    if (null === $hookValue) {
                        continue;
                    }
                    $value = $hookValue->resolveIndirect();
                    if (VM\TypedPropertyCheck::omitFromSerialize($value)) {
                        continue;
                    }
                    $copy = new Variable();
                    $copy->copyFrom($value);
                    $result[$meta->name] = $copy;

                    continue;
                }
                if (!$object->hasProperty($meta->name)) {
                    continue;
                }
                $value = $object->getProperty($meta->name)->resolveIndirect();
                if (VM\TypedPropertyCheck::omitFromSerialize($value)) {
                    continue;
                }
                $copy = new Variable();
                $copy->copyFrom($value);
                $result[$meta->name] = $copy;
            }
        }
        foreach ($object->getRawProperties() as $name => $prop) {
            if (isset($seenLc[strtolower($name)])) {
                continue;
            }
            $value = $prop->resolveIndirect();
            if (VM\TypedPropertyCheck::omitFromSerialize($value)) {
                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($value);
            $result[$name] = $copy;
        }

        return $result;
    }

    /**
     * All declared + dynamic properties for plain-object serialize() — mangled visibility keys (#15751, var.c).
     *
     * php-src: serialize uses raw property-table values (ZEND_PROP_PURPOSE_SERIALIZE), not get hooks.
     * Virtual hooked props have no slot and are omitted; backed hooks serialize the backing field
     * under its mangled name (#28184, re-#6474 — #6474 wrongly matched json_encode get-hook semantics).
     *
     * @return array<string, Variable>
     */
    public function collectObjectPropertiesForSerialize(ObjectEntry $object, Frame $frame): array
    {
        if (SplArrayStorage::hasState($object)) {
            return SplArrayStorage::collectJsonEncodeProperties($object);
        }
        $ctx = $this->context;
        /** @var array<string, Variable> $result */
        $result = [];
        /** @var array<string, true> $seenLc */
        $seenLc = [];
        /** @var array<string, true> $seenPrivate */
        $seenPrivate = [];
        /** @var array<string, true> $seenDeclaredLc */
        $seenDeclaredLc = [];
        foreach (array_reverse(ext\standard\VmReflection::classHierarchyChain($object->class, $ctx)) as $class) {
            foreach ($class->properties as $meta) {
                $lc = strtolower($meta->name);
                $isPrivate = ($meta->visibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0;
                $seenDeclaredLc[$lc] = true;
                if ($isPrivate) {
                    $privKey = ($meta->declaringClassLc !== '' ? $meta->declaringClassLc : strtolower($class->name))."\0".$lc;
                    if (isset($seenPrivate[$privKey])) {
                        continue;
                    }
                    $seenPrivate[$privKey] = true;
                } else {
                    if (isset($seenLc[$lc])) {
                        continue;
                    }
                    $seenLc[$lc] = true;
                }
                // Virtual hooked properties: no backing store — omit from serialize (#28184).
                if ($meta->propertyHookVirtual) {
                    continue;
                }
                // Non-virtual hooked property: serialize raw backing slot, never invoke get (#28184).
                if (null !== $meta->getHookMethodLc || null !== $meta->setHookMethodLc) {
                    if (!$object->hasPropertyForMeta($meta)) {
                        continue;
                    }
                    $value = $object->getPropertyForMeta($meta)->resolveIndirect();
                    if (VM\TypedPropertyCheck::omitFromSerialize($value)) {
                        continue;
                    }
                    $copy = new Variable();
                    $copy->copyFrom($value);
                    $result[ext\standard\VmReflection::manglePropertyKey($meta, $ctx)] = $copy;

                    continue;
                }
                if (!$object->hasPropertyForMeta($meta)) {
                    continue;
                }
                $value = $object->getPropertyForMeta($meta)->resolveIndirect();
                if (VM\TypedPropertyCheck::omitFromSerialize($value)) {
                    continue;
                }
                $copy = new Variable();
                $copy->copyFrom($value);
                $result[ext\standard\VmReflection::manglePropertyKey($meta, $ctx)] = $copy;
            }
        }
        foreach ($object->getRawProperties() as $name => $prop) {
            $nameLc = strtolower($name);
            if (isset($seenDeclaredLc[$nameLc]) || isset($seenLc[$nameLc])) {
                continue;
            }
            $value = $prop->resolveIndirect();
            if (VM\TypedPropertyCheck::omitFromSerialize($value)) {
                continue;
            }
            $copy = new Variable();
            $copy->copyFrom($value);
            $result[$name] = $copy;
        }

        return $result;
    }

    /**
     * unserialize() property restore — set hooks when declared (#6474, var_unserializer.c).
     *
     * Wire keys are ZEND_PROP_PURPOSE_SERIALIZE mangled names (`\0*\0message`); resolve to the
     * declared slot so typed protected/private props initialize (#26673).
     */
    public function assignUnserializeProperty(
        ObjectEntry $object,
        string $propName,
        Variable $value,
        ?Frame $frame = null
    ): void {
        $meta = VM\PropertyMangle::findPropertyForSerializeKey($object, $propName, $this->context->classes);
        $storageName = null !== $meta ? $meta->name : $propName;
        if ($this->assignHookedPropertyBackingStorage($object, $storageName, $value)) {
            return;
        }
        if (null !== $frame) {
            $hookFrame = $this->resolvePropertyHookParentFrame($frame);
            $writeLvalue = new Variable();
            $writeLvalue->objectPropertyOwner = $object;
            $writeLvalue->objectPropertyName = $storageName;
            if ($this->dispatchPropertySetHookAssign($writeLvalue, $value, $hookFrame)) {
                return;
            }
        }
        if (null !== $meta) {
            $object->getPropertyForMeta($meta)->copyFrom($value->resolveIndirect());

            return;
        }
        $prop = $object->hasProperty($propName)
            ? $object->getProperty($propName)
            : $object->allocateProperty($propName);
        $prop->copyFrom($value);
    }

    /**
     * unserialize() restore when set-hook dispatch is unavailable — write registry backing (#6474).
     */
    private function assignHookedPropertyBackingStorage(
        ObjectEntry $object,
        string $propName,
        Variable $value
    ): bool {
        $lcClass = strtolower($object->class->name);
        $propMeta = $this->context->propertyHookRegistry[$lcClass][$propName]
            ?? $this->context->propertyHookRegistry[$lcClass][strtolower($propName)]
            ?? null;
        if (!is_array($propMeta)) {
            return false;
        }
        $backingName = $propMeta['setBacking'] ?? $propMeta['getBacking'] ?? null;
        if (null === $backingName) {
            return false;
        }
        if (!$object->hasProperty($backingName)) {
            $object->allocateProperty($backingName);
        }
        $object->getProperty($backingName)->copyFrom($value->resolveIndirect());

        return true;
    }

    private function isPropertyAccessibleForObjectVars(VM\ClassProperty $meta, ?string $callerClassLc): bool
    {
        if (MethodVisibility::isPublic($meta->visibility)) {
            return true;
        }
        if (null === $callerClassLc) {
            return false;
        }
        if (($meta->visibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
            return $callerClassLc === $meta->declaringClassLc;
        }
        if (($meta->visibility & \PHPCfg\Func::FLAG_PROTECTED) !== 0) {
            if ($callerClassLc === $meta->declaringClassLc) {
                return true;
            }

            return $this->isClassSameOrSubclassOf($callerClassLc, $meta->declaringClassLc);
        }

        return true;
    }

    /**
     * Zend zend_check_clone: private/protected __clone() rejects external-scope clone (#5077).
     *
     * @return null when clone is allowed, or a catch frame when Error was dispatched
     */
    protected function enforceCloneVisibility(ObjectEntry $object, Frame $frame): ?Frame
    {
        if (!$this->hasInstanceMethod($object->class, '__clone')) {
            return null;
        }
        try {
            [$resolvedClass, $methodLc] = $this->resolveInstanceMethod($object->class, '__clone');
            $declLc = $resolvedClass->methodDeclaringClassLc[$methodLc] ?? strtolower($resolvedClass->name);
            $declaringClass = $this->context->classes[$declLc] ?? $resolvedClass;
            $vis = $declaringClass->methodVisibility[$methodLc]
                ?? $resolvedClass->methodVisibility[$methodLc]
                ?? \PHPCfg\Func::FLAG_PUBLIC;
            $callerClassLc = $this->callerClassLc($frame);
            $callerDisplay = $this->callerScopeDisplay($frame, $callerClassLc);
            MethodVisibility::assertCloneCallable(
                $vis,
                $callerClassLc,
                strtolower($declaringClass->name),
                $declaringClass->name,
                false,
                fn (string $classLc, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLc, $ancestorLc),
                $callerDisplay
            );
        } catch (\LogicException $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }

        return null;
    }

    /**
     * Zend object construction: private/protected inherited __construct() rejects external scope (#5382).
     *
     * @return null when construction may proceed, or a catch frame when Error was dispatched
     */
    protected function enforceNewConstructorVisibility(ClassEntry $class, Frame $frame): ?Frame
    {
        if (null === $class->constructor && !$this->hasInstanceMethod($class, '__construct')) {
            return null;
        }
        // Internal ce handlers may keep $entry->constructor without advertising __construct
        // in the method table (php-src SplDoublyLinkedList / SplStack / SplQueue, #22789).
        if (!$this->hasInstanceMethod($class, '__construct')) {
            return null;
        }
        try {
            [$declaringClass, $methodLc] = $this->resolveInstanceMethod($class, '__construct');
            $vis = $declaringClass->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
            $callerClassLc = $this->callerClassLc($frame);
            $callerDisplay = $this->callerScopeDisplay($frame, $callerClassLc);
            MethodVisibility::assertConstructorCallable(
                $vis,
                $callerClassLc,
                strtolower($declaringClass->name),
                $declaringClass->name,
                false,
                fn (string $classLc, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLc, $ancestorLc),
                $callerDisplay
            );
        } catch (\LogicException $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }

        return null;
    }

    /**
     * Zend object handler clone_obj — copy extension-owned storage after shallow property clone
     * (#19803 ArrayObject, #19805 SplObjectStorage). Walks parentLc for subclass handlers.
     */
    protected function invokeCloneObjectHandler(ObjectEntry $src, ObjectEntry $dest): void
    {
        $class = $src->class;
        while (null !== $class) {
            if (null !== $class->cloneObjectHandler) {
                ($class->cloneObjectHandler)($src, $dest);

                return;
            }
            $parentLc = $class->parentLc;
            if (null === $parentLc || !isset($this->context->classes[$parentLc])) {
                return;
            }
            $class = $this->context->classes[$parentLc];
        }
    }

    /**
     * Zend zend_std_clone_object: shallow copy then user __clone() when defined (#3170).
     *
     * Must run on an isolated run stack with parent frame linkage — nested runFrames() from
     * invokePhpFunctionOnStack would pop the clone opcode caller off the shared stack (#10165).
     */
    /**
     * @return null when __clone completed, or a catch frame when throw bubbled from isolated stack (#12068)
     */
    protected function invokeCloneMagicMethod(ObjectEntry $object, Frame $parentFrame): ?Frame
    {
        $class = $object->class;
        if (!isset($class->methods['__clone'])) {
            return null;
        }
        $func = $class->methods['__clone'];
        $thisVar = new Variable(Variable::TYPE_OBJECT);
        $thisVar->object($object);
        $savedStack = null !== $this->context->currentFiber
            ? null
            : $this->context->swapRunStack(null);
        $savedExternalCatch = $this->context->cloneMagicExternalCatchFrame;
        $savedCallerFrame = $this->context->cloneMagicCallerFrame;
        $this->context->cloneMagicExternalCatchFrame = null;
        $this->context->cloneMagicCallerFrame = $parentFrame;
        $this->context->invokingCloneMagic = true;
        VM\CloneWithSupport::beginCloneMagicReinit(
            $object,
            fn (ObjectEntry $owner, string $prop): ?string => $this->readonlyPropertyDeclaringClass($owner, $prop)
        );
        try {
            $child = $func->getFrame($this->context, $parentFrame);
            $child->calledArgs = [$thisVar];
            if (null !== $func->block->func && null !== $func->block->func->class
                && !(($func->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC)) {
                $thisIdx = $func->block->slotIndexForVariableName('this');
                if (null !== $thisIdx) {
                    if (!isset($child->scope[$thisIdx])) {
                        $child->scope[$thisIdx] = new Variable();
                    }
                    $child->scope[$thisIdx]->copyFrom($thisVar);
                }
            }
            $out = new Variable();
            $child->returnVar = $out;
            $this->context->push($child);
            $result = $this->runFrames();
            if (null !== $this->context->cloneMagicExternalCatchFrame) {
                return $this->context->cloneMagicExternalCatchFrame;
            }
            if (self::FIBER_SUSPEND === $result) {
                throw new \LogicException('Fiber suspend during __clone() is not supported in this compiler build');
            }
            if (self::SUCCESS !== $result) {
                throw new \LogicException('__clone() invocation failed in this compiler build');
            }

            return null;
        } finally {
            VM\CloneWithSupport::endReinit($object);
            $this->context->invokingCloneMagic = false;
            $this->context->cloneMagicExternalCatchFrame = $savedExternalCatch;
            $this->context->cloneMagicCallerFrame = $savedCallerFrame;
            if (null !== $savedStack) {
                $this->context->swapRunStack($savedStack);
            }
        }
    }

    /**
     * Zend zend_std_read_property / __get slow path (#146).
     */
    protected function invokeMagicGet(ObjectEntry $object, string $name): Variable
    {
        if (!$this->hasInstanceMethod($object->class, '__get')) {
            throw new \LogicException('Undefined property access');
        }
        if (!$object->beginPropertyGuard($name, ObjectEntry::GUARD_IN_GET)) {
            // Already in __get for this prop — fall through to slot / undef path (zend guard).
            if ($object->hasProperty($name)) {
                return $object->getProperty($name);
            }
            $null = new Variable();
            $null->null();

            return $null;
        }
        try {
            $nameVar = new Variable(Variable::TYPE_STRING);
            $nameVar->string($name);

            return $this->invokeInstanceMethod($object, '__get', $nameVar);
        } finally {
            $object->endPropertyGuard($name, ObjectEntry::GUARD_IN_GET);
        }
    }

    /**
     * Zend zend_std_write_property / __set slow path (#146).
     */
    protected function invokeMagicSet(ObjectEntry $object, string $name, Variable $value): void
    {
        if (!$this->hasInstanceMethod($object->class, '__set')) {
            throw new \LogicException('Undefined property access');
        }
        if (!$object->beginPropertyGuard($name, ObjectEntry::GUARD_IN_SET)) {
            // Already in __set — assign directly to slot / allocate (zend IN_SET guard; #25810).
            $slot = $object->hasProperty($name)
                ? $object->getProperty($name)
                : $object->allocateProperty($name);
            $object->clearPropertyExplicitlyUnset($name);
            $slot->copyFrom($value);

            return;
        }
        try {
            $nameVar = new Variable(Variable::TYPE_STRING);
            $nameVar->string($name);
            $valueCopy = new Variable();
            $valueCopy->copyFrom($value);
            $this->invokeInstanceMethod($object, '__set', $nameVar, $valueCopy);
        } finally {
            $object->endPropertyGuard($name, ObjectEntry::GUARD_IN_SET);
        }
    }

    /**
     * True when zend_std_read_property must invoke __get (undeclared, inaccessible, or post-unset).
     * Scope-aware meta: in-frame private beats child shadow so __get does not recurse (#25795).
     * Post-unset declared slots use __get like Zend (#25810, zend_object_handlers.c).
     */
    protected function propertyReadUsesMagicGet(ObjectEntry $object, string $name, Frame $frame): bool
    {
        if (!$this->hasInstanceMethod($object->class, '__get')) {
            return false;
        }
        if ($object->isPropertyGuardActive($name, ObjectEntry::GUARD_IN_GET)) {
            return false;
        }
        $meta = $this->classPropertyMeta($object, $name, $frame);
        if (null === $meta) {
            return true;
        }
        if ($this->declaredPropertyInaccessibleFromCaller($object, $meta, $name, $frame, $meta->getVisibility)) {
            return true;
        }

        // unset($obj->prop) on a declared property → UNDEF; subsequent reads use __get (#25810).
        return $object->isPropertyExplicitlyUnset($name);
    }

    /**
     * True when zend_std_write_property must invoke __set (undeclared, inaccessible, or post-unset).
     * Shared by direct assign (#25686) and RMW ++/-- / assign-op (#25687).
     * Post-unset declared slots use __set like Zend (#25810); IN_SET guard prevents re-entry.
     */
    protected function propertyWriteUsesMagicSet(ObjectEntry $object, string $name, Frame $frame): bool
    {
        if (!$this->hasInstanceMethod($object->class, '__set')) {
            return false;
        }
        if ($object->isPropertyGuardActive($name, ObjectEntry::GUARD_IN_SET)) {
            return false;
        }
        $meta = $this->classPropertyMeta($object, $name, $frame);
        if (null === $meta) {
            return true;
        }

        // Symmetric visibility: inaccessible declared props route through __set (zend_object_handlers.c).
        // Asymmetric set visibility is handled separately via enforceAsymmetricPropertyWrite.
        if ($this->declaredPropertyInaccessibleFromCaller($object, $meta, $name, $frame, 0)) {
            return true;
        }

        // unset($obj->prop) → subsequent assigns use __set (#25810).
        return $object->isPropertyExplicitlyUnset($name);
    }

    /**
     * Declared private/protected prop not visible from the calling scope (zend_std_*_property).
     */
    private function declaredPropertyInaccessibleFromCaller(
        ObjectEntry $object,
        VM\ClassProperty $meta,
        string $name,
        Frame $frame,
        int $getOrSetVisibility
    ): bool {
        if ($this->isParentPrivatePropertyInvisibleFromCaller($meta, $frame, $object)) {
            return true;
        }
        $declaringDisplay = $this->context->classes[$meta->declaringClassLc]->name
            ?? $meta->declaringClassLc;
        try {
            PropertyVisibility::assertAccessible(
                $meta->visibility,
                $this->callerClassLc($frame),
                $meta->declaringClassLc,
                $declaringDisplay,
                $name,
                strtolower($object->class->name),
                fn (string $classLc, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLc, $ancestorLc),
                $getOrSetVisibility
            );

            return false;
        } catch (\LogicException $e) {
            return true;
        }
    }

    /**
     * isset/empty must not read an inaccessible declared slot (zend_std_has_property; #25668).
     */
    private function declaredPropertyIssetUsesOverload(
        ObjectEntry $object,
        VM\ClassProperty $meta,
        string $name,
        Frame $frame
    ): bool {
        return $this->declaredPropertyInaccessibleFromCaller(
            $object,
            $meta,
            $name,
            $frame,
            $meta->getVisibility
        );
    }

    /**
     * Inaccessible declared unset — __unset, silent no-op (parent private from child), or Error (#25668).
     *
     * @return Frame|false|null Frame on catch, null when handled, false when caller should continue
     */
    private function dispatchInaccessibleDeclaredPropertyUnset(
        ObjectEntry $object,
        string $propName,
        Frame $frame
    ): Frame|false|null {
        $meta = $this->classPropertyMeta($object, $propName, $frame);
        if (null === $meta) {
            return false;
        }
        $invisibleParent = $this->isParentPrivatePropertyInvisibleFromCaller($meta, $frame, $object);
        $inaccessible = $invisibleParent
            || $this->declaredPropertyInaccessibleFromCaller($object, $meta, $propName, $frame, 0);
        if (!$inaccessible) {
            return false;
        }
        if ($this->hasInstanceMethod($object->class, '__unset')) {
            $key = new Variable();
            $key->string($propName);
            $this->invokeInstanceMethod($object, '__unset', $key);

            return null;
        }
        if ($invisibleParent) {
            // Parent private is not in child scope — unset is a no-op (zend_get_property_offset).
            return null;
        }
        $declaringDisplay = $this->context->classes[$meta->declaringClassLc]->name
            ?? $meta->declaringClassLc;
        try {
            PropertyVisibility::assertAccessible(
                $meta->visibility,
                $this->callerClassLc($frame),
                $meta->declaringClassLc,
                $declaringDisplay,
                $propName,
                strtolower($object->class->name),
                fn (string $classLc, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLc, $ancestorLc),
                0
            );
        } catch (\LogicException $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }

        return null;
    }

    /**
     * Copy __get return into $result and mark for indirect-modify detection (#4673).
     */
    protected function deliverMagicGetRead(Variable $result, ObjectEntry $object, string $name): void
    {
        $result->copyFrom($this->invokeMagicGet($object, $name));
        $result->magicGetOverloadedTarget = $object;
        $result->magicGetOverloadedName = $name;
    }

    /**
     * `$r = &$obj->inaccessible` — Zend get_property_ptr_ptr fails; read_property(BP_VAR_W)
     * invokes __get (zend_object_handlers.c, #25688).
     *
     * By-ref `__get` binds the returned lvalue; by-value `__get` yields a notice and a
     * temporary (Indirect modification of overloaded property … has no effect).
     */
    protected function deliverInaccessiblePropertyFetchByRef(
        Variable $result,
        ObjectEntry $object,
        string $name,
        Frame $frame
    ): void {
        if ($this->instanceMethodReturnsByRef($object, '__get')) {
            $result->indirect($this->invokeMagicGet($object, $name));

            return;
        }
        $this->deliverMagicGetRead($result, $object, $name);
        $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
        $this->context->errors->indirectModificationOfOverloadedProperty(
            $object->class->name,
            $name,
            $this->context,
            $frame,
            $scriptFile
        );
    }

    /**
     * Notice + continue for []= / dim-write on a non-object value from __get (#29231, re-#4673).
     *
     * php-src zend_object_handlers.c: arrays returned by value from __get cannot be
     * written back — Zend emits E_NOTICE ("Indirect modification … has no effect") and
     * continues (write hits the temporary only). Objects from __get — including
     * SimpleXMLElement / ArrayAccess — keep write_dimension on the live instance, so
     * $sxe->child["attr"] = … must reach offsetSet (#20005, sxe_prop_dim_write).
     *
     * Hooked-property Indirect modification Error paths are separate (#28590 / #29215).
     */
    protected function rejectMagicGetIndirectModify(Variable $containerSlot, bool $forWrite, Frame $frame): ?Frame
    {
        if (!$forWrite) {
            return null;
        }
        if (null === $containerSlot->magicGetOverloadedTarget || null === $containerSlot->magicGetOverloadedName) {
            return null;
        }
        $resolved = $containerSlot->resolveIndirect();
        if (Variable::TYPE_OBJECT === $resolved->type) {
            return null;
        }
        $class = $containerSlot->magicGetOverloadedTarget->class->name;
        $prop = $containerSlot->magicGetOverloadedName;
        $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
        $this->context->errors->indirectModificationOfOverloadedProperty(
            $class,
            $prop,
            $this->context,
            $frame,
            $scriptFile
        );

        return null;
    }

    /**
     * Resolve an instance property write lvalue, including __set / dynamic properties (#146).
     * Inaccessible declared props with __set use the magic proxy (zend_std_write_property; #25686/#25687).
     */
    protected function fetchObjectPropertyWriteLvalue(ObjectEntry $object, string $name, Frame $frame): Variable
    {
        if ($this->propertyWriteUsesMagicSet($object, $name, $frame)) {
            $proxy = new Variable();
            $proxy->magicSetTarget = $object;
            $proxy->magicSetName = $name;

            return $proxy;
        }
        $meta = $this->classPropertyMeta($object, $name, $frame);
        if (null !== $meta && $object->hasPropertyForMeta($meta)) {
            $object->clearPropertyExplicitlyUnset($name);

            return $object->getPropertyForMeta($meta);
        }
        if ($object->hasProperty($name)) {
            $object->clearPropertyExplicitlyUnset($name);

            return $object->getProperty($name);
        }
        if (VM\ObjectReadonlySupport::isDynamicReadonly($object)) {
            // Stamp user site before raise (same class as #25556 / #29457).
            [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
            $thrown = VM\BuiltinExceptionSupport::materializeError(
                $this->context,
                VM\ObjectReadonlySupport::modifyObjectMessage($object),
                $file,
                $line
            );
            $this->raiseUncaughtException($thrown);
        }
        if ($object->class->readonly && !$this->hasInstanceMethod($object->class, '__set')) {
            [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
            $thrown = VM\BuiltinExceptionSupport::materializeError(
                $this->context,
                sprintf('Cannot create dynamic property %s::$%s', $object->class->name, $name),
                $file,
                $line
            );
            $this->raiseUncaughtException($thrown);
        }
        if ($this->hasInstanceMethod($object->class, '__set')) {
            // IN_SET re-entry: allocate/assign directly (zend_get_property_guard; #25810).
            if ($object->isPropertyGuardActive($name, ObjectEntry::GUARD_IN_SET)) {
                return $object->allocateProperty($name);
            }
            $proxy = new Variable();
            $proxy->magicSetTarget = $object;
            $proxy->magicSetName = $name;

            return $proxy;
        }
        if (SplArrayStorage::hasArrayAsProps($object)) {
            $proxy = new Variable();
            $proxy->arrayAsPropsTarget = $object;
            $proxy->arrayAsPropsName = $name;

            return $proxy;
        }
        if ($this->instanceMethodReturnsByRef($object, '__get')) {
            return $this->invokeMagicGet($object, $name);
        }
        // Defense in depth — primary gate is enforceInternalDynamicPropertyCreate (#26055, #26371).
        if ($object->class->noDynamicProperties) {
            // Stamp assignment site so uncaught Error does not cite ExceptionSupport (#29457).
            [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
            $thrown = VM\BuiltinExceptionSupport::materializeError(
                $this->context,
                sprintf('Cannot create dynamic property %s::$%s', $object->class->name, $name),
                $file,
                $line
            );
            $this->raiseUncaughtException($thrown);
        }
        if (!$object->class->allowsDynamicProperties) {
            if (\PHPCompiler\CompilerVersion::supportsDynamicPropertyCreationDeprecation()) {
                $scriptPath = $frame->scriptPath;
                $this->context->errors->deprecatedDynamicProperty(
                    $object->class->name,
                    $name,
                    '' !== $scriptPath && '-' !== $scriptPath ? $scriptPath : null,
                    $this->context,
                    $frame
                );
            }
        }

        return $object->allocateProperty($name);
    }

    /**
     * Invoke a closure from a VM builtin (isolated run stack; issue #72).
     */
    public function invokeClosure(ClosureState $closureState, Variable ...$args): Variable
    {
        return $this->invokeClosureWithCalledArgs($closureState, $args);
    }

    /**
     * @param array<int, Variable> $args possibly sparse (named optionals, #23388)
     */
    public function invokeClosureWithCalledArgs(ClosureState $closureState, array $args): Variable
    {
        return $this->invokeClosureFromWithCalledArgs(null, $closureState, true, $args);
    }

    /**
     * Invoke a closure; when $isolated is false, run on the active stack (#4927 Closure::call).
     */
    public function invokeClosureFrom(
        ?Frame $runParent,
        ClosureState $closureState,
        bool $isolated,
        Variable ...$args
    ): Variable {
        return $this->invokeClosureFromWithCalledArgs($runParent, $closureState, $isolated, $args);
    }

    /**
     * @param array<int, Variable> $args
     */
    private function invokeClosureFromWithCalledArgs(
        ?Frame $runParent,
        ClosureState $closureState,
        bool $isolated,
        array $args
    ): Variable {
        $savedStack = $isolated ? $this->context->swapRunStack(null) : null;
        try {
            $init = new Frame(null, $closureState->func->block, $runParent);
            $init->vmContext = $this->context;
            $this->initClosureCall($init, $closureState);
            if (null === $init->call) {
                throw new \LogicException('Closure invocation failed in this compiler build');
            }
            $parentForCallee = $runParent ?? (!empty($init->callArgs) ? $init : null);
            $child = $init->call->getFrame($this->context, $parentForCallee);
            $this->applyClosureBinding($child, $closureState);
            $child->calledArgs = $args;
            $out = new Variable();
            $child->returnVar = $out;
            if ($child->hasHandler()) {
                $child->vmContext = $this->context;
                $child->handler->execute($child);

                return $out->resolveIndirect();
            }
            if ($isolated) {
                $this->context->deferBuiltinCallbackCatchToOuterRunFrames = true;
            }
            try {
                $this->context->push($child);
                $result = $this->runFrames();
            } finally {
                if ($isolated) {
                    $this->context->deferBuiltinCallbackCatchToOuterRunFrames = false;
                }
            }
            if (self::SUCCESS !== $result) {
                throw new \LogicException('Closure invocation failed in this compiler build');
            }

            return $out->resolveIndirect();
        } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
            if ($isolated) {
                $this->context->swapRunStack($savedStack);
                $savedStack = null;
            }
            throw $redirect;
        } finally {
            if ($isolated && null !== $savedStack) {
                $this->context->swapRunStack($savedStack);
            }
        }
    }

    /**
     * Execute dynamically compiled eval() code in the caller variable scope (#3358).
     *
     * Outer try/catch handlers active before eval must not run inside this nested runFrames —
     * that resumes the try body after catch (#25816; same shape as #24138 / #14104).
     */
    public function executeEvalBlock(Block $block, Frame $caller): Variable
    {
        $out = new Variable();
        $child = $block->getFrame($this->context, $caller);
        $child->ephemeral = true;
        // Scope comes from getFrame($caller); parent must stay null so nested runFrames exits.
        $child->parent = null;
        $child->returnVar = $out;
        // Zend __FILE__/__DIR__: enclosing script path + call site (#25809, zend_eval_string).
        [$evalFile] = VM\ExceptionSupport::evalFatalSite($caller, 1);
        $child->scriptPath = $evalFile;
        $this->context->scriptStack->push($child->scriptPath);
        $prevDeferDepth = $this->context->deferCatchBelowTryHandlerDepth;
        $this->context->deferCatchBelowTryHandlerDepth = \count($this->context->activeTryHandlerFrames);
        try {
            $this->context->push($child);
            $result = $this->runFrames();
            if (self::SUCCESS !== $result) {
                throw new \LogicException('eval() execution failed in this compiler build');
            }
        } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
            throw $redirect;
        } finally {
            $this->context->deferCatchBelowTryHandlerDepth = $prevDeferDepth;
            $this->context->scriptStack->pop();
        }

        return $out->resolveIndirect();
    }

    /**
     * Start a new fiber (issue #3130).
     *
     * @param list<Variable> $startArgs
     */
    public function startFiber(FiberState $fiber, Variable ...$startArgs): Variable
    {
        if (FiberState::STATUS_INIT !== $fiber->status) {
            throw new VM\NativeFiberError('Cannot start a fiber that has already been started');
        }
        $fiber->resumeArgument->null();
        $child = $fiber->callback->func->getFrame($this->context, null);
        // Bound-closure / instance-method fibers need $this in scope (Zend/zend_fibers.c, #25777).
        // applyClosureBinding also installs use()-captures (same as invokeClosure / generators).
        $this->applyClosureBinding($child, $fiber->callback);
        $child->calledArgs = $startArgs;
        $child->fiberState = $fiber;
        $returnSlot = new Variable();
        $child->returnVar = $returnSlot;
        $fiber->frame = $child;
        $fiber->status = FiberState::STATUS_RUNNING;

        return $this->runFiberExecution($fiber, $returnSlot);
    }

    /**
     * Resume a suspended fiber (issue #3130).
     *
     * @param list<Variable> $resumeArgs
     */
    public function resumeFiber(FiberState $fiber, Variable ...$resumeArgs): Variable
    {
        if (FiberState::STATUS_TERMINATED === $fiber->status) {
            throw new VM\NativeFiberError('Cannot resume a fiber that is not suspended');
        }
        if (FiberState::STATUS_SUSPENDED !== $fiber->status) {
            throw new VM\NativeFiberError('Cannot resume a fiber that is not suspended');
        }
        if ([] !== $resumeArgs) {
            $fiber->resumeArgument->copyFrom($resumeArgs[0]->resolveIndirect());
        } else {
            $fiber->resumeArgument->null();
        }
        if (null !== $fiber->pendingSuspendReturnVar) {
            $fiber->pendingSuspendReturnVar->copyFrom($fiber->resumeArgument);
            $fiber->pendingSuspendReturnVar = null;
        }
        $child = $fiber->frame;
        if (null === $child) {
            throw new \LogicException('Fiber resume missing suspended frame');
        }
        $fiber->status = FiberState::STATUS_RUNNING;
        $returnSlot = new Variable();
        $savedReturn = $child->returnVar;
        $child->returnVar = $returnSlot;
        try {
            return $this->runFiberExecution($fiber, $returnSlot);
        } finally {
            $child->returnVar = $savedReturn;
        }
    }

    /**
     * Throw into a suspended fiber (Fiber->throw()) (Zend/zend_fibers.c parity, #4481).
     */
    public function throwFiber(FiberState $fiber, Variable $exception): Variable
    {
        if (FiberState::STATUS_TERMINATED === $fiber->status) {
            throw new VM\NativeFiberError('Cannot resume a fiber that is not suspended');
        }
        if (FiberState::STATUS_SUSPENDED !== $fiber->status) {
            throw new VM\NativeFiberError('Cannot resume a fiber that is not suspended');
        }
        $fiber->pendingThrow->copyFrom($exception->resolveIndirect());
        $fiber->hasPendingThrow = true;
        $fiber->resumeArgument->null();
        // Mirror resumeFiber: RUNNING so catch→suspend stays legal; returnSlot is wired
        // onto the catch/entry frames inside runFiberExecution after throw dispatch (#23041).
        if (null === $fiber->frame) {
            throw new \LogicException('Fiber throw missing suspended frame');
        }
        $fiber->status = FiberState::STATUS_RUNNING;
        $returnSlot = new Variable();

        return $this->runFiberExecution($fiber, $returnSlot);
    }

    /**
     * Point suspended/catch CFG frames at this invocation's return slot through the fiber entry.
     *
     * Fiber::throw() catch bodies are getFrame()-d from the fiber-entry frame (fiberState),
     * which may be a parent of the suspended try-body frame — wiring only the suspended
     * frame leaves getReturn() empty (Zend/zend_fibers.c, #23041).
     */
    private function wireFiberReturnSlot(FiberState $fiber, Variable $returnSlot): void
    {
        for ($frame = $fiber->frame; null !== $frame; $frame = $frame->parent) {
            $frame->returnVar = $returnSlot;
            if ($frame->fiberState === $fiber) {
                break;
            }
        }
    }

    private function runFiberExecution(FiberState $fiber, Variable $returnSlot): Variable
    {
        $savedFiber = $this->context->currentFiber;
        $this->context->currentFiber = $fiber;
        $savedStack = $this->context->swapRunStack(null);
        try {
            $this->applyFiberPendingThrow($fiber);
            if (null !== $fiber->propertyHookSuspendFrame) {
                $hookFrame = $fiber->propertyHookSuspendFrame;
                $fiber->propertyHookSuspendFrame = null;
                $this->context->push($hookFrame);
                try {
                    $hookStatus = $this->runFrames();
                } catch (VM\FiberUncaughtThrow $e) {
                    $this->terminateFiberAfterThrow($fiber);
                    throw $e;
                } catch (\Throwable $e) {
                    $this->terminateFiberAfterThrow($fiber);
                    throw $e;
                }
                if (self::FIBER_SUSPEND === $hookStatus) {
                    $fiber->propertyHookSuspendFrame = $hookFrame;
                    $fiber->status = FiberState::STATUS_SUSPENDED;
                    $out = new Variable();
                    $out->duplicateFrom($fiber->suspendReturn->resolveIndirect());

                    return $out;
                }
                if (self::SUCCESS !== $hookStatus) {
                    throw new \LogicException('Property hook fiber resume failed in this compiler build');
                }
                if (null === $hookFrame->returnVar) {
                    throw new \LogicException('Property hook fiber resume missing return slot');
                }
                $fiber->propertyHookResumeRead = new Variable();
                $fiber->propertyHookResumeRead->copyFrom($hookFrame->returnVar->resolveIndirect());
            }
            $child = $fiber->frame;
            if (null === $child) {
                throw new \LogicException('Fiber execution missing frame after throw dispatch');
            }
            $this->wireFiberReturnSlot($fiber, $returnSlot);
            $this->context->push($child);
            try {
                $result = $this->runFrames();
            } catch (VM\FiberUncaughtThrow $e) {
                $this->terminateFiberAfterThrow($fiber);
                throw $e;
            } catch (\Throwable $e) {
                $this->terminateFiberAfterThrow($fiber);
                throw $e;
            }
        } finally {
            $this->context->swapRunStack($savedStack);
            $this->context->currentFiber = $savedFiber;
        }
        if (self::FIBER_SUSPEND === $result) {
            $fiber->status = FiberState::STATUS_SUSPENDED;
            $out = new Variable();
            $out->duplicateFrom($fiber->suspendReturn->resolveIndirect());

            return $out;
        }
        if (self::SUCCESS === $result) {
            $fiber->status = FiberState::STATUS_TERMINATED;
            $fiber->frame = null;
            $resolved = $returnSlot->resolveIndirect();
            $fiber->returnValue->copyFrom($resolved);
            $fiber->hasReturnValue = true;
            $fiber->threw = false;
            $out = new Variable();
            // Zend/zend_fibers.c: resume()/start() return NULL when fiber is dead (#10149).
            $out->null();

            return $out;
        }

        throw new \LogicException('Fiber execution failed in this compiler build');
    }

    private function terminateFiberAfterThrow(FiberState $fiber): void
    {
        $fiber->status = FiberState::STATUS_TERMINATED;
        $fiber->frame = null;
        $fiber->pendingSuspendReturnVar = null;
        $fiber->propertyHookSuspendFrame = null;
        $fiber->propertyHookResumeRead = null;
        $fiber->hasReturnValue = false;
        $fiber->threw = true;
    }

    private function findFiberState(Frame $frame): ?FiberState
    {
        while (null !== $frame) {
            if (null !== $frame->fiberState) {
                return $frame->fiberState;
            }
            $frame = $frame->parent;
        }

        return null;
    }

    private function applyFiberPendingThrow(FiberState $fiber): void
    {
        if (!$fiber->hasPendingThrow) {
            return;
        }
        $thrown = new Variable();
        $thrown->copyFrom($fiber->pendingThrow);
        $fiber->hasPendingThrow = false;
        $fiber->pendingThrow->null();
        $frame = $fiber->frame;
        if (null === $frame) {
            $fiber->status = FiberState::STATUS_TERMINATED;
            $fiber->hasReturnValue = false;
            $fiber->threw = true;
            throw new VM\FiberUncaughtThrow($thrown);
        }
        $catchFrame = $this->findCatchFrameForFiberThrow($fiber, $thrown);
        if (null !== $catchFrame) {
            $catchFrame->fiberState = $fiber;
            $fiber->frame = $catchFrame;

            return;
        }
        $fiber->status = FiberState::STATUS_TERMINATED;
        $fiber->frame = null;
        $fiber->hasReturnValue = false;
        $fiber->threw = true;
        throw new VM\FiberUncaughtThrow($thrown);
    }

    /**
     * Compile and execute a PHP file once (require_once semantics for manifest includes / PSR-4).
     */
    public function executeCompileUnit(string $path): void
    {
        $resolved = VM\ScriptStack::normalize($path);
        if ('' === $resolved || !is_file($resolved)) {
            return;
        }
        if ($this->context->isCompileUnitLoaded($resolved)) {
            return;
        }
        $this->context->markCompileUnitLoaded($resolved);
        $this->context->recordIncludedFile($resolved);

        $savedStack = $this->context->swapRunStack(null);
        try {
            $this->context->scriptStack->push($resolved);
            $block = $this->context->runtime->parseAndCompileFile($resolved);
            if (null === $block) {
                return;
            }
            $this->run($block);
        } finally {
            $this->context->swapRunStack($savedStack);
        }
    }

    /**
     * Materialize a Traversable (array, Generator, or Iterator) into a new array (ext/spl iterator_to_array parity, #3100, #4244).
     */
    public function iteratorToArray(Variable $iterator, bool $preserveKeys = false, ?Frame $frame = null): HashTable
    {
        $iterator = VmIteratorWalk::assertTraversable(
            $iterator,
            $this->context,
            'iterator_to_array',
            'iterator'
        );
        $iterator = $iterator->resolveIndirect();
        $out = new HashTable();
        if (Variable::TYPE_ARRAY === $iterator->type) {
            $index = 0;
            foreach ($iterator->toArray()->iterateKeyed(true) as [$key, $value]) {
                if ($preserveKeys) {
                    self::appendHashTableEntry($out, $key, $value);
                } else {
                    $packedKey = new Variable();
                    $packedKey->int($index++);
                    self::appendHashTableEntry($out, $packedKey, $value);
                }
            }

            return $out;
        }
        if ($this->variableIsGenerator($iterator)) {
            $gen = $iterator->toObject()->generatorState;
            VmIteratorWalk::assertGeneratorIterableForRewind($gen);
            $gen->rewind();
            $index = 0;
            // After rewind the generator is on the opening yield — collect before advance (#23713).
            while ($gen->hasCurrent && !$gen->done) {
                if ($preserveKeys) {
                    self::appendHashTableEntry($out, $gen->currentKey, $gen->currentValue);
                } else {
                    $packedKey = new Variable();
                    $packedKey->int($index++);
                    self::appendHashTableEntry($out, $packedKey, $gen->currentValue);
                }
                if (!$this->advanceGeneratorIteration($gen)) {
                    break;
                }
            }

            return $out;
        }
        if (Variable::TYPE_OBJECT === $iterator->type) {
            if (null === $frame) {
                throw new \LogicException('iterator_to_array() on Traversable object requires VM frame');
            }
            $arrayObjectCopy = $this->iteratorArrayObjectToArray($iterator, $preserveKeys);
            if (null !== $arrayObjectCopy) {
                return $arrayObjectCopy;
            }

            return $this->iteratorObjectToArray($frame, $iterator, $preserveKeys);
        }

        throw new \TypeError(
            'iterator_to_array(): Argument #1 ($iterator) must be of type '.IterableCheck::TYPE_LABEL
        );
    }

    private function iteratorArrayObjectToArray(Variable $iterable, bool $preserveKeys): ?HashTable
    {
        $iterable = $iterable->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $iterable->type) {
            return null;
        }
        $entry = $iterable->toObject();
        if (ArrayObjectBuiltin::CLASS_LC !== strtolower(ltrim($entry->class->name, '\\'))) {
            return null;
        }
        if (!SplArrayStorage::hasState($entry)) {
            return null;
        }
        $table = SplArrayStorage::getArrayCopy($entry);
        if ($preserveKeys) {
            return $table;
        }
        $out = new HashTable();
        $index = 0;
        foreach ($table->iterateKeyed(true) as [, $value]) {
            $packedKey = new Variable();
            $packedKey->int($index++);
            self::appendHashTableEntry($out, $packedKey, $value);
        }

        return $out;
    }

    private function iteratorObjectToArray(Frame $frame, Variable $iterable, bool $preserveKeys): HashTable
    {
        $out = new HashTable();
        $object = ForeachIterator::resolveTraversableObject($this, $frame, $iterable);
        $this->invokeForeachInstanceMethod($frame, $object, 'rewind');
        $index = 0;
        while ($this->invokeForeachInstanceMethod($frame, $object, 'valid')->toBool()) {
            $value = $this->invokeForeachInstanceMethod($frame, $object, 'current')->resolveIndirect();
            if ($preserveKeys) {
                $key = $this->invokeForeachInstanceMethod($frame, $object, 'key')->resolveIndirect();
                self::appendHashTableEntry($out, $key, $value);
            } else {
                $packedKey = new Variable();
                $packedKey->int($index++);
                self::appendHashTableEntry($out, $packedKey, $value);
            }
            $before = $value;
            $this->invokeForeachInstanceMethod($frame, $object, 'next');
            if (!$this->invokeForeachInstanceMethod($frame, $object, 'valid')->toBool()) {
                break;
            }
            $after = $this->invokeForeachInstanceMethod($frame, $object, 'current')->resolveIndirect();
            if (self::iteratorStepStalled($before, $after) && $index > 0) {
                break;
            }
        }

        return $out;
    }

    private static function iteratorStepStalled(Variable $before, Variable $after): bool
    {
        $before = $before->resolveIndirect();
        $after = $after->resolveIndirect();
        if ($before->type !== $after->type) {
            return false;
        }
        if (Variable::TYPE_INTEGER === $before->type) {
            return $before->toInt() === $after->toInt();
        }
        if (Variable::TYPE_STRING === $before->type) {
            return $before->toString() === $after->toString();
        }

        return false;
    }

    private static function appendHashTableEntry(HashTable $out, Variable $key, Variable $value): void
    {
        $copy = new Variable();
        $copy->copyFrom($value);
        // Zend iterator_to_array / hashtable writes reject array|object|enum keys (#23573).
        $key = HashTable::normalizeIndexKey($key->resolveIndirect());
        if (Variable::TYPE_INTEGER === $key->type) {
            $out->updateIndex($key->toInt(), $copy);

            return;
        }
        $keyStr = $key->toString();
        $intKey = HashTable::tryIntFromNumericString($keyStr);
        if (null !== $intKey) {
            $out->updateIndex($intKey, $copy);

            return;
        }
        $out->update($keyStr, $copy);
    }

    private function seedScriptPath(Frame $frame): void
    {
        if ('' !== $frame->scriptPath) {
            $this->context->scriptStack->push($frame->scriptPath);
            $this->context->recordIncludedFile($frame->scriptPath);
            if ('-' !== $frame->scriptPath) {
                VmString::realpath($frame->scriptPath);
            }
        }
    }

    private function maybeRunTick(): void
    {
        if (VM\TickQueue::isRunning() || $this->context->tickInterval <= 0) {
            return;
        }
        --$this->context->tickCounter;
        if ($this->context->tickCounter > 0) {
            return;
        }
        $this->context->tickCounter = $this->context->tickInterval;
        VM\TickQueue::run($this->context);
    }

    private function runFrames(): int
    {
        $previous = self::$running;
        self::$running = $this;
        try {
            return $this->runFramesInner();
        } finally {
            self::$running = $previous;
        }
    }

    /**
     * Build a catchable VM Error object for engine-thrown failures (#3429).
     */
    public function makeEngineError(string $message, string $className = 'Error'): Variable
    {
        $lc = strtolower($className);
        if (!isset($this->context->classes[$lc])) {
            throw new \LogicException("Engine error class {$className} is not registered");
        }
        $obj = new ObjectEntry($this->context->classes[$lc]);
        $obj->constructed = true;
        $obj->getProperty('message')->string($message);
        $thrown = new Variable();
        $thrown->object($obj);

        return $thrown;
    }

    private function normalizeThrownVariable(Variable $thrown): Variable
    {
        if (VM\ExceptionSupport::isThrowableVariable($thrown, $this->context)) {
            return $thrown;
        }

        return $this->makeEngineError(
            VM\ExceptionSupport::throwNormalizeErrorMessage($thrown),
            VM\ExceptionSupport::CLASS_ERROR
        );
    }

    private function dispatchEngineThrow(Frame $frame, Variable $thrown): ?Frame
    {
        $thrown = $this->normalizeThrownVariable($thrown);
        VM\ExceptionTrace::captureOnThrow($this->context, $frame, $thrown);
        // Zend: throw in finally discards a pending return (#5331).
        $inFinally = $this->frameIsInFinallyBody($frame);
        if ($inFinally) {
            $this->clearPendingReturnState();
        }
        $pendingBeforeThrow = null;
        if (null !== $this->context->pendingException) {
            $pendingBeforeThrow = new Variable();
            $pendingBeforeThrow->copyFrom($this->context->pendingException);
        }
        $gen = $this->findGeneratorState($frame);
        if (null !== $gen) {
            $catchFrame = $this->findCatchFrameForGeneratorThrow($gen, $thrown);
            VM\ExceptionTrace::captureGeneratorThrowSite($this->context, $frame, $thrown);
            if (null !== $catchFrame) {
                $catchFrame->generatorState = $gen;
                $gen->frame = $catchFrame;

                return $catchFrame;
            }
            $gen->frame = null;
            $gen->markClosedWithoutReturn();
            throw new VM\GeneratorUncaughtThrow($thrown, $frame);
        }
        // Zend/zend_fibers.c: uncaught throw inside a fiber transfers to the resume()/throw()
        // caller — never jump into the caller's try/catch while still inside runFiberExecution
        // (#19592; mirrors GeneratorUncaughtThrow above).
        $fiber = $this->context->currentFiber;
        if (null !== $fiber && $this->findFiberState($frame) === $fiber) {
            $catchFrame = $this->findCatchFrameForFiberThrow($fiber, $thrown);
            if (null !== $catchFrame) {
                $catchFrame->fiberState = $fiber;
                $fiber->frame = $catchFrame;

                return $catchFrame;
            }
            throw new VM\FiberUncaughtThrow($thrown);
        }
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            if ($this->context->isolatedDestructorInvoke) {
                throw new VM\DestructorThrowCatchSignal($catchFrame);
            }

            return $catchFrame;
        }
        // Zend: finally-over-try uncaught fatal cites pending try exception first (#5867, #6457, #7342).
        if ($inFinally && null !== $pendingBeforeThrow) {
            $this->raiseUncaughtExceptionWithNext($pendingBeforeThrow, $thrown);

            return null;
        }
        $uncaught = $this->context->pendingException ?? $thrown;
        $this->raiseUncaughtException($uncaught);

        return null;
    }

    private function runFramesInner(): int
    {
nextframe:
        $frame = $this->context->pop();

        if (is_null($frame)) {
            return self::SUCCESS;
        }
restart:
        $this->popTryHandlerIfAtMergeBlock($frame);
        if ($this->context->pendingReturnDispatch) {
            $this->context->pendingReturnDispatch = false;
            $frame = $this->context->pendingReturnResumeFrame;
            $isVoid = $this->context->pendingReturnIsVoid;
            $returnValue = $this->context->pendingReturnValue;
            $this->clearPendingReturnState();
            if ($isVoid) {
                goto return_void_complete;
            }
            goto return_value_complete;
        }

        while ($frame->pos < $frame->block->nOpCodes) {
            $this->executingFrame = $frame;
            $this->context->executionLimits->check($this->context, $frame);
            $op = $frame->block->opCodes[$frame->pos++];
            try {
                $this->assertDeferredDefinitionsBeforeRuntime($op->type);
            } catch (\Error $deferredParentError) {
                // Missing extends parent — Zend Error (catchable); was LogicException soft message (#25627).
                $catchFrame = $this->dispatchVmError($deferredParentError->getMessage(), $frame);
                if (null !== $catchFrame) {
                    $frame = $catchFrame;
                    goto restart;
                }
                break;
            }
            try {
                switch ($op->type) {
                case OpCode::TYPE_TYPE_ASSERT:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    $arg1->copyFrom($arg2); 
                    break;
                case OpCode::TYPE_ASSIGN:
                    $catchFrame = $this->dispatchThisReassignFatalIfNeeded($frame, $op->arg2);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    if (!isset($frame->block->constants[$op->arg3])) {
                        $catchFrame = $this->guardUnboundThisRead($frame, (int) $op->arg3);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    }
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    if (null !== $op->arg3) {
                        $arg3 = isset($frame->block->constants[$op->arg3])
                            ? $frame->block->constants[$op->arg3]
                            : $this->readRuntimeOperandPreferringInitializedCv($frame, (int) $op->arg3);
                    } else {
                        // ?: merge assigns omit arg3; legacy lowering reads slot 0 (#9159, re-#14134).
                        $arg3 = $this->readScopeOperandForRuntimeRead($frame, 0);
                    }
                    // Direct `$obj->prop =` (propertyAssignLvalue) checks visibility. Writes through
                    // an already-acquired reference (`$r =& …; $r =`) must not — Zend (#29456).
                    if ($arg2->propertyAssignLvalue) {
                        $catchFrame = $this->enforcePropertyVisibilityWrite($arg2, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        $catchFrame = $this->enforceStaticPropertyVisibilityWrite($arg2, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    }
                    $catchFrame = $this->enforceReadonlyPropertyWrite($arg2, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $catchFrame = $this->enforceFinalPropertyWrite($arg2, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $catchFrame = $this->enforceAsymmetricPropertyWrite($arg2, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $this->emitPropertyWriteDeprecation($arg2, $frame);
                    try {
                        if (
                            !$this->assignDefersHookedPropertyDimWriteBack($arg2)
                            && $this->dispatchPropertySetHookAssign($arg2, $arg3, $frame)
                        ) {
                            $this->deliverPropertySetHookAssignResult($arg1, $arg3);
                            break;
                        }
                    } catch (VM\PropertyHookRefWriteSignal $signal) {
                        $frame = $signal->catchFrame;
                        goto restart;
                    }
                    if ($this->context->propertyHookSetAborted) {
                        $this->context->propertyHookSetAborted = false;
                        break;
                    }
                    $catchFrame = $this->enforceVirtualPropertyHookWrite($arg2, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $writeTarget = $arg2->resolveIndirect();
                    if (null !== $writeTarget->magicSetTarget && null !== $writeTarget->magicSetName) {
                        $this->invokeMagicSet($writeTarget->magicSetTarget, $writeTarget->magicSetName, $arg3);
                        $arg1->copyFrom($arg3);
                        break;
                    }
                    if (null !== $writeTarget->arrayAsPropsTarget && null !== $writeTarget->arrayAsPropsName) {
                        $key = new Variable(Variable::TYPE_STRING);
                        $key->string($writeTarget->arrayAsPropsName);
                        SplArrayStorage::offsetSet($writeTarget->arrayAsPropsTarget, $key, $arg3);
                        $arg1->copyFrom($arg3);
                        break;
                    }
                    if (null !== ($msg = $this->asymmetricPropertyWriteMessage($arg2, $frame))) {
                        $catchFrame = $this->dispatchVmError($msg, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    }
                    $writeTarget = $arg2->resolveIndirect();
                    if (
                        $this->context->isGlobalStorage($writeTarget)
                        && !VM\EnumCaseSupport::arrayContainsRuntimeRefs($arg3)
                    ) {
                        $resolvedArg = $arg3->resolveIndirect();
                        if (!$resolvedArg->isUndefined()) {
                            $stored = VM\EnumCaseSupport::materializeGlobalVariableValue($this->context, $arg3);
                            $arg2->copyFrom($stored);
                            $arg1->copyFrom($stored);
                            // materializeGlobalVariableValue returns a non-scope Variable; its
                            // object/array ref must be dropped or script-global assign leaks and
                            // defers __destruct until shutdown (#23484, re-#6456).
                            $stored->reset();
                        } else {
                            $arg2->copyFrom($arg3);
                            $arg1->copyFrom($arg3);
                        }
                    } else {
                        $catchFrame = $this->assignCopyFrom($arg2, $arg3, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        $arg1->copyFrom($arg3);
                    }
                    $catchFrame = $this->flushHookedPropertyDimWriteBackAfterAssign($arg2, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    ext\dom\VmDom::retainUserHandleFromVariable($arg2);
                    if (
                        !$this->shouldDeferVmDeadTempRelease($frame)
                        && $op->arg2 !== $op->arg3
                        && $frame->block->assignTempSlotIsDead((int) $op->arg3)
                    ) {
                        $this->releaseVmDeadScopeSlot($frame, (int) $op->arg3);
                    }
                    if (
                        !$this->shouldDeferVmDeadTempRelease($frame)
                        && $op->arg1 !== $op->arg2
                        && $op->arg1 !== $op->arg3
                        && $frame->block->assignTempSlotIsDead((int) $op->arg1)
                    ) {
                        $this->releaseVmDeadScopeSlot($frame, (int) $op->arg1);
                    }
                    $strict = null !== $frame->parent
                        ? $frame->parent->block->strictTypes
                        : $frame->block->strictTypes;
                    try {
                        TypeCheck::coercePropertyWrite($arg2, $strict);
                        if (null !== $writeTarget->dnfArms) {
                            $dnfCtx = $this->context;
                            $viaRef = TypeCheck::destIsTypedPropertyByRefWrite($arg2);
                            TypeCheck::withTypedPropertyByRefAssign(
                                $viaRef,
                                static function () use ($arg3, $writeTarget, $dnfCtx, $strict): void {
                                    DnfCheck::assertMatches(
                                        $arg3,
                                        $writeTarget->dnfArms,
                                        $dnfCtx,
                                        'Property',
                                        $writeTarget,
                                        $strict
                                    );
                                }
                            );
                        }
                    } catch (\TypeError $e) {
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    }
                    $this->markScopeSlotInitialized($frame, (int) $op->arg2);
                    $this->releaseVmStatementDeadTemps($frame, (int) $op->arg2);
                    break;
                case OpCode::TYPE_ASSIGN_REF:
                    $catchFrame = $this->dispatchThisReassignFatalIfNeeded($frame, $op->arg1);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    if (null !== $op->arg3 && 1 === (int) $op->arg3) {
                        $catchFrame = $this->dispatchVmError(
                            'Cannot assign reference to non referenceable value',
                            $frame
                        );
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    $lhs = $frame->scope[$op->arg1];
                    $catchFrame = $this->enforcePropertyVisibilityWrite($lhs, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $catchFrame = $this->enforceStaticPropertyVisibilityWrite($lhs, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $catchFrame = $this->enforceReadonlyPropertyWrite($lhs, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $catchFrame = $this->enforceFinalPropertyWrite($lhs, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    if (null !== ($msg = $this->asymmetricPropertyWriteMessage($lhs, $frame))) {
                        $catchFrame = $this->dispatchVmError($msg, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    }
                    $this->emitPropertyWriteDeprecation($lhs, $frame);
                    $rhsSlot = $frame->scope[$op->arg2];
                    // `$r = &$obj->readonlyProp` — also guard here when fetch temp carries owner (#25620).
                    $catchFrame = $this->enforceReadonlyPropertyFetchByRef($rhsSlot, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    // Reference acquisition via `$r = &$obj->prop` follows set visibility (#7070).
                    // Already-acquired by-ref call returns (`$r = &$obj->getPriv()`) must not
                    // re-check — Zend aliases the returned reference (#29456).
                    if ($rhsSlot->propertyRefAcquisition) {
                        $catchFrame = $this->enforcePropertyVisibilityWrite($rhsSlot, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        $catchFrame = $this->enforceStaticPropertyVisibilityWrite($rhsSlot, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        if (null !== ($msg = $this->asymmetricPropertyWriteMessage($rhsSlot, $frame))) {
                            $catchFrame = $this->dispatchVmError($msg, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                        }
                    }
                    $catchFrame = $this->guardUnboundThisRead($frame, (int) $op->arg2);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $rhs = $rhsSlot->resolveIndirect();
                    // ArrayDimFetch / property fetch temps are indirect to live storage; write the
                    // reference into that cell instead of redirecting the temp (#5349).
                    $lhsPeel = $lhs->isIndirect() ? $lhs->directIndirectTarget() : $lhs;
                    // Zend: cannot create references to/from string offsets (#21910).
                    if (
                        Variable::TYPE_STRING_OFFSET === $rhs->type
                        || Variable::TYPE_STRING_OFFSET === $lhsPeel->resolveIndirect()->type
                    ) {
                        $catchFrame = $this->dispatchVmError(Variable::STRING_OFFSET_REF_ERROR, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    if (null !== $lhsPeel->objectPropertyOwner) {
                        // $obj->prop =& $v — bind into declared property storage (#5370).
                        $writeTarget = $lhsPeel;
                    } elseif (null !== $rhs->objectPropertyOwner) {
                        // $ref = &$obj->prop — bind variable slot, not peeled global wrapper (#13559).
                        // Inline array `[&$obj->hook]` — bind the array bucket behind dim-fetch temp (#17353).
                        if (
                            $lhs->isIndirect()
                            && null === $lhsPeel->objectPropertyOwner
                            && !$this->context->isGlobalStorage($lhsPeel)
                        ) {
                            $writeTarget = $lhsPeel;
                        } else {
                            $writeTarget = $lhs;
                        }
                    } else {
                        $writeTarget = $lhs->isIndirect() ? $lhsPeel : $lhs;
                    }
                    // Zend BIND_STATIC + ASSIGN_REF: `$s = &$param` rebinds the CV only; the
                    // static_variables HT keeps its prior value and next BIND restores it (#21993).
                    if (
                        $lhs->isIndirect()
                        && null !== $lhsPeel
                        && null !== $this->context->functionStaticKeyForStorage($lhsPeel)
                    ) {
                        $writeTarget = $lhs;
                    }
                    // Zend ASSIGN_REF: named CV `$a =& $x` rebinds the local symbol only —
                    // disconnects by-ref params from the caller, local aliases from their prior
                    // referent, and `global $g` inside functions (#22546). Main-script globals
                    // still peel into the symbol-table cell so `$GLOBALS` stays linked.
                    // Unnamed dim/$GLOBALS fetch temps keep peeling into live storage (#5349).
                    if (
                        $lhs->isIndirect()
                        && $writeTarget === $lhsPeel
                        && null !== $lhsPeel
                        && null !== $this->resolveScopeSlotVariableName($frame, (int) $op->arg1)
                        && (
                            !$this->context->isGlobalStorage($lhsPeel)
                            || !$frame->block->isMainScript()
                        )
                    ) {
                        $writeTarget = $lhs;
                    }
                    if (
                        null !== $op->arg3
                        && OpCode::ASSIGN_REF_FOREACH_PROPERTY_HOOK === (int) $op->arg3
                    ) {
                        $lhsHookRefLvalue = $this->resolvePropertyHookRefWriteLvalue($lhs, $frame);
                        if (null === $lhsHookRefLvalue) {
                            $hookTarget = $writeTarget->resolveIndirect();
                            $owner = $hookTarget->objectPropertyOwner;
                            $propName = $hookTarget->objectPropertyName;
                            if (null !== $owner && null !== $propName) {
                                $proxy = new Variable();
                                $proxy->objectPropertyOwner = $owner;
                                $proxy->objectPropertyName = $propName;
                                $lhsHookRefLvalue = $proxy;
                            }
                        }
                        if (null !== $lhsHookRefLvalue) {
                            if (!$this->propertyWriteHasSetHook($lhsHookRefLvalue)) {
                                $catchFrame = $this->enforceVirtualPropertyHookWrite($lhsHookRefLvalue, $frame);
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }
                                break;
                            }
                            // Zend FE_FETCH_R: iteration value to hook backing; in-loop writes use hooks (#6435).
                            $this->writeHookedPropertyForeachIterationValue(
                                $lhsHookRefLvalue,
                                $rhs,
                                $frame
                            );
                        }
                        break;
                    }
                    // Zend: Class::$prop = &Class::$prop stores NULL, not a circular ref (#5405).
                    if ($writeTarget === $rhs && $this->isStaticPropertyStorageCell($writeTarget)) {
                        $writeTarget->null();
                        break;
                    }
                    // Zend: `$obj->hooked =& $v` — get_property_ptr_ptr fails for hooked props (#22475).
                    $lhsHookAssignLvalue = $this->resolvePropertyHookRefWriteLvalue($lhs, $frame);
                    if (null === $lhsHookAssignLvalue) {
                        $lhsHookAssignLvalue = $this->resolvePropertyHookRefWriteLvalue($writeTarget, $frame);
                    }
                    if (null !== $lhsHookAssignLvalue) {
                        $catchFrame = $this->dispatchVmError(
                            'Cannot assign by reference to overloaded object',
                            $frame
                        );
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    // Zend: `$r = &$obj->hooked` requires `&get` (#22475, zend_object_handlers.c).
                    $hookRefLvalue = $this->resolvePropertyHookRefWriteLvalue($rhsSlot, $frame);
                    if (null !== $hookRefLvalue) {
                        if (!$this->propertyHookGetIsByRef($hookRefLvalue)) {
                            $catchFrame = $this->dispatchVmError(
                                $this->indirectModificationOfHookedPropertyMessage($hookRefLvalue),
                                $frame
                            );
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        }
                        $catchFrame = $this->bindAssignRefToByRefGetHook(
                            $writeTarget,
                            $hookRefLvalue,
                            $frame
                        );
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    // Object property / static / nested ref slots are live storage (Zend FE_FETCH_R,
                    // #5245). Main-script globals use an indirect wrapper — still need a shared ref
                    // cell so unset($a) does not destroy $b (#5368).
                    // HashTable bucket cells are destroyed with the array: promote to a shared
                    // IS_REFERENCE-style cell so `$b =& $a[$k]; unset($a);` keeps the residual (#22027).
                    if (null !== $rhs->objectPropertyOwner) {
                        $writeTarget->indirect($rhs);
                        $this->markTypedPropertyByRefAlias($writeTarget, $rhs);
                        break;
                    }
                    if (
                        null !== $rhs->staticPropertyClassLc
                        && null !== $rhs->objectPropertyName
                    ) {
                        $writeTarget->indirect($rhs);
                        $this->markTypedPropertyByRefAlias($writeTarget, $rhs);
                        break;
                    }
                    if (
                        $rhsSlot->isIndirect()
                        && !$this->context->isGlobalStorage($rhs)
                        && !$rhs->hashTableBucketCell
                    ) {
                        $writeTarget->indirect($rhs);
                        $this->markTypedPropertyByRefAlias($writeTarget, $rhs);
                        break;
                    }
                    if (Variable::TYPE_INDIRECT !== $rhs->type) {
                        $ref = new Variable();
                        $ref->copyFrom($rhs);
                        $rhs->indirect($ref);
                    }
                    $writeTarget->indirect($rhs->resolveIndirect());
                    $this->markTypedPropertyByRefAlias($writeTarget, $rhs->resolveIndirect());
                    break;
                case OpCode::TYPE_VAR_FETCH:
                    $dest = $frame->scope[$op->arg1];
                    $nameSlot = (int) $op->arg2;
                    $nameHolder = $frame->scope[$nameSlot]->resolveIndirect();
                    $nameOperand = $frame->block->operandForScopeSlot($nameSlot);
                    $nameVarLabel = null !== $nameOperand ? Block::resolveVariableName($nameOperand) : null;
                    if (
                        null !== $nameVarLabel
                        && (Variable::TYPE_NULL === $nameHolder->type || Variable::TYPE_UNDEFINED === $nameHolder->type)
                    ) {
                        $this->context->errors->undefinedVariable(
                            $nameVarLabel,
                            $this->context,
                            $frame,
                            '' !== $frame->scriptPath ? $frame->scriptPath : null
                        );
                    }
                    [$name, $catchFrame] = $this->coerceRuntimeOperandToString($nameHolder, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    if ('this' === strtolower($name)) {
                        if (null !== $frame->block->func && null !== $frame->block->func->class) {
                            $isStatic = (($frame->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) !== 0;
                            $thisIdx = $frame->block->slotIndexForVariableName('this');
                            if ($isStatic || null === $thisIdx || !isset($frame->scope[$thisIdx])) {
                                $catchFrame = $this->dispatchVmError(
                                    'Using $this when not in object context',
                                    $frame
                                );
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }
                                break;
                            }
                        }
                    }
                    $forWrite = $this->varFetchDestUsedAsAssignLvalue($frame, $op);
                    if ('' === $name) {
                        $dest->indirect(new Variable());
                        break;
                    }
                    if (VmVarFetch::isSuperglobalName($name)) {
                        $target = $this->context->ensureSuperglobal($name);
                    } elseif ($forWrite) {
                        $target = $frame->block->ensureVariableByRuntimeName($name, $frame);
                    } else {
                        $target = $frame->block->findVariableByRuntimeName($name, $frame);
                        if (null === $target) {
                            $this->context->errors->undefinedVariable(
                                $name,
                                $this->context,
                                $frame,
                                '' !== $frame->scriptPath ? $frame->scriptPath : null
                            );
                            $target = new Variable();
                        }
                    }
                    $dest->indirect($target);
                    break;
                case OpCode::TYPE_DECLARE_GLOBAL:
                    if (!isset($frame->block->constants[$op->arg2])) {
                        throw new \LogicException('Global name must be a compile-time constant');
                    }
                    $globalName = $frame->block->constants[$op->arg2]->toString();
                    $frame->scope[$op->arg1]->indirect($this->context->ensureGlobal($globalName));
                    // Zend: `global $x` installs $x in the active symbol table (compact /
                    // get_defined_vars see it). Same as TYPE_DECLARE_FUNCTION_STATIC (#25898).
                    $this->markScopeSlotInitialized($frame, (int) $op->arg1);
                    break;
                case OpCode::TYPE_DECLARE_FUNCTION_STATIC:
                    if (!isset($frame->block->constants[$op->arg2])) {
                        throw new \LogicException('Function static key must be a compile-time constant');
                    }
                    $storageKey = $frame->block->constants[$op->arg2]->toString();
                    $storage = $this->ensureFunctionStaticForFrame($frame, $storageKey);
                    if (!$this->isFunctionStaticInitializedForFrame($frame, $storageKey)) {
                        if (null !== $op->arg3 && isset($frame->block->constants[$op->arg3])) {
                            $storage->copyFrom($frame->block->constants[$op->arg3]);
                            $catchFrame = $this->enforceFunctionStaticWrite(
                                $storage,
                                $frame,
                                $op->functionStaticVarName
                            );
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            $this->markFunctionStaticInitializedForFrame($frame, $storageKey);
                        }
                    }
                    $this->applyFunctionStaticTypeMetadata($storage, $frame, $op);
                    $frame->scope[$op->arg1]->indirect($storage);
                    $this->markScopeSlotInitialized($frame, (int) $op->arg1);
                    break;
                case OpCode::TYPE_JUMPIF_FUNCTION_STATIC_INITIALIZED:
                    if (!isset($frame->block->constants[$op->arg2])) {
                        throw new \LogicException('Function static key must be a compile-time constant');
                    }
                    $jumpKey = $frame->block->constants[$op->arg2]->toString();
                    if ($this->isFunctionStaticInitializedForFrame($frame, $jumpKey)) {
                        $frame = $this->frameForBranch($frame, $op->block1);
                        goto restart;
                    }
                    break;
                case OpCode::TYPE_FUNCTION_STATIC_INIT_STORE:
                    if (!isset($frame->block->constants[$op->arg2])) {
                        throw new \LogicException('Function static key must be a compile-time constant');
                    }
                    if (null === $op->arg3) {
                        throw new \LogicException('Function static init store requires a value slot');
                    }
                    $storeKey = $frame->block->constants[$op->arg2]->toString();
                    $store = $this->ensureFunctionStaticForFrame($frame, $storeKey);
                    $this->applyFunctionStaticTypeMetadata($store, $frame, $op);
                    $store->copyFrom($frame->scope[$op->arg3]->resolveIndirect());
                    $catchFrame = $this->enforceFunctionStaticWrite(
                        $store,
                        $frame,
                        $op->functionStaticVarName
                    );
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $this->markFunctionStaticInitializedForFrame($frame, $storeKey);
                    break;
                case OpCode::TYPE_LIST_UNPACK_CHECK:
                    $unpackSlot = $frame->scope[$op->arg2];
                    $unpack = $unpackSlot->resolveIndirect();
                    if (null !== $op->block1) {
                        if (!$this->variableIsListDestructUnpackable($unpack)) {
                            // Plain / Traversable-only objects: Zend FETCH_LIST Error (#25096).
                            if (Variable::TYPE_OBJECT === $unpack->type) {
                                $className = $unpack->toObject()->class->name;
                                $catchFrame = $this->dispatchVmError(
                                    'Cannot use object of type ' . $className . ' as array',
                                    $frame
                                );
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }
                                break;
                            }
                            // By-ref list / `$r =& $s[$i]`: do not skip — FETCH_DIM_W + ASSIGN_REF
                            // raise Zend string-offset or scalar-as-array Errors (#21910).
                            if ($op->listUnpackHasByRef) {
                                break;
                            }
                            foreach ($op->listUnpackNullInitSlots as $destSlot) {
                                $dest = $frame->scope[(int) $destSlot];
                                $dest->resolveIndirect()->null();
                                $this->markScopeSlotInitialized($frame, (int) $destSlot);
                            }
                            if (null !== $op->block1) {
                                foreach ($op->listUnpackNullInitSlots as $destSlot) {
                                    unset($op->block1->constants[(int) $destSlot]);
                                }
                            }
                            // String and other non-array RHS: skip slot binds, targets read as NULL (#4325, #10486).
                            $frame = $this->frameForBranch($frame, $op->block1);
                            goto restart;
                        }
                        $catchFrame = $this->materializeListDestructIterableRhs($unpackSlot, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        $frame->listUnpackAssignMergeBlock = $op->block1;
                        break;
                    }
                    break;
                case OpCode::TYPE_LIST_SPREAD_ASSIGN:
                    if (!CompilerVersion::supportsListDestructuringSpreadAssign()) {
                        throw new \Error('Spread operator is not supported in assignments');
                    }
                    $dest = $frame->scope[$op->arg1];
                    $src = $frame->scope[$op->arg2]->resolveIndirect();
                    if (Variable::TYPE_ARRAY !== $src->type) {
                        if (null !== $op->block1) {
                            $frame = $this->frameForBranch($frame, $op->block1);
                            goto restart;
                        }
                        break;
                    }
                    if (!isset($frame->block->constants[$op->arg3])) {
                        throw new \LogicException('list spread assign requires compile-time offset');
                    }
                    $offset = $frame->block->constants[$op->arg3]->toInt();
                    $ht = $src->toArray();
                    $excludedKeys = $op->listSpreadExcludedKeys;
                    if ([] !== $excludedKeys) {
                        $tail = $ht->copyListSpreadTail($offset, $excludedKeys);
                    } else {
                        if (!\PHPCompiler\ext\standard\VmArray::isList($ht)) {
                            $catchFrame = $this->dispatchVmTypeError(
                                new \TypeError('Cannot unpack array with string keys'),
                                $frame
                            );
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        }
                        $tail = $ht->sliceCopy($offset, null);
                    }
                    $dest->array($tail);
                    break;
                case OpCode::TYPE_ARRAY_DIM_FETCH:
                case OpCode::TYPE_ARRAY_DIM_FETCH_WRITE:
                    $arg1 = $frame->scope[$op->arg1];
                    $containerSlot = $frame->scope[$op->arg2];
                    $container = $containerSlot->resolveIndirect();
                    $forWrite = OpCode::TYPE_ARRAY_DIM_FETCH_WRITE === $op->type;
                    $fetchIs = !$forWrite && $op->arrayDimFetchIs;
                    $catchFrame = $this->rejectMagicGetIndirectModify($containerSlot, $forWrite, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    if ($container->isArrayAccessOffset()) {
                        // Nested dim through ArrayAccess (#5460 / #20005): materialize via
                        // offsetGet. Objects (SimpleXMLElement, ArrayObject, …) accept further
                        // write_dimension; arrays returned by value cannot be written back.
                        try {
                            $materialized = $container->readArrayAccessOffsetValue();
                        } catch (VM\ArrayAccessOffsetSignal $signal) {
                            $frame = $signal->catchFrame;
                            goto restart;
                        }
                        if ($forWrite || is_null($op->arg3)) {
                            if (Variable::TYPE_OBJECT === $materialized->type) {
                                $container = $materialized;
                            } else {
                                $this->context->errors->indirectModificationOfOverloadedElement(
                                    $container->arrayAccessOffsetClassName(),
                                    $this->context,
                                    $frame,
                                    '' !== $frame->scriptPath ? $frame->scriptPath : null
                                );
                                $arg1->null();
                                break;
                            }
                        } else {
                            $container = $materialized;
                        }
                    }
                    // ZEND_FETCH_DIM_W: null/undefined/false containers auto-vivify (#21992, #22650).
                    // false→[] also emits E_DEPRECATED since PHP 8.1 (zend_execute.c / #22828).
                    if ($forWrite && TypeCheck::isNullContainerForDimAutovivify($container)) {
                        if (TypeCheck::isFalseContainerForDimAutovivify($container)) {
                            $this->context->errors->internalDeprecated(
                                TypeCheck::FALSE_TO_ARRAY_DEPRECATED_MESSAGE,
                                $this->context,
                                $frame,
                                '' !== $frame->scriptPath ? $frame->scriptPath : null
                            );
                        }
                        $container->array(new HashTable());
                        // Zend defines the CV on FETCH_DIM_W — mark script globals / locals so a
                        // later bare read does not emit Undefined variable (#29146, re-#21992).
                        $this->markScopeSlotInitialized($frame, (int) $op->arg2);
                    }
                    $isGlobals = Variable::TYPE_ARRAY === $container->type
                        && $this->context->isGlobalsTable($container);
                    if ($forWrite && Variable::TYPE_ARRAY === $container->type && !$isGlobals) {
                        $container->separateArrayForWrite();
                        $container = $containerSlot->resolveIndirect();
                    }
                    if (is_null($op->arg3)) {
                        if (TypeCheck::isScalarUsedAsArray($container)) {
                            $catchFrame = $this->dispatchVmError(
                                TypeCheck::SCALAR_USED_AS_ARRAY_MESSAGE,
                                $frame
                            );
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        }
                        if ($container->type !== Variable::TYPE_ARRAY) {
                            if (Variable::TYPE_STRING === $container->type) {
                                $catchFrame = $this->dispatchVmError(
                                    TypeCheck::STRING_APPEND_UNSUPPORTED_MESSAGE,
                                    $frame
                                );
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }
                                break;
                            }
                            if (
                                Variable::TYPE_OBJECT === $container->type
                                && $this->objectImplementsArrayAccess($container->toObject())
                            ) {
                                if (!$forWrite) {
                                    throw new \LogicException('[] is only supported for arrays');
                                }
                                $object = $container->toObject();
                                $nullKey = new Variable(Variable::TYPE_NULL);
                                $nullKey->null();
                                $dim = new Variable();
                                $dim->arrayAccessDimension(
                                    new VM\ArrayAccessDimension($this, $object, $nullKey, $frame)
                                );
                                $arg1->indirect($dim);
                                break;
                            }
                            if (
                                Variable::TYPE_OBJECT === $container->type
                                && !$this->objectImplementsArrayAccess($container->toObject())
                            ) {
                                $className = $container->toObject()->class->name;
                                $catchFrame = $this->dispatchVmError(
                                    'Cannot use object of type ' . $className . ' as array',
                                    $frame
                                );
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }
                                break;
                            }
                            throw new \LogicException('[] is only supported for arrays');
                        }
                        try {
                            $appendCell = $container->toArray()->append(new Variable);
                        } catch (\Error $e) {
                            $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        }
                        $arg1->indirect($appendCell);
                        $this->tagHookedPropertyDimWriteLvalue($arg1, $containerSlot);
                        break;
                    }
                    $arg3 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg3);
                    if (Variable::TYPE_STRING_OFFSET === $container->type) {
                        $catchFrame = $this->dispatchVmError(
                            'Cannot use string offset as an array',
                            $frame
                        );
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    if ($container->type === Variable::TYPE_STRING) {
                        $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
                        try {
                            $byteIndex = Variable::stringOffsetIndexFromDim(
                                $arg3,
                                $this->context->errors,
                                $this->context,
                                $frame,
                                $scriptFile
                            );
                        } catch (\TypeError $e) {
                            $catchFrame = $this->dispatchVmTypeError($e, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        }
                        if ($forWrite) {
                            $offset = new Variable(Variable::TYPE_STRING_OFFSET);
                            $offset->stringOffset(
                                $container,
                                $byteIndex,
                                $this->context->errors,
                                $this->context,
                                $frame,
                                $scriptFile
                            );
                            $arg1->indirect($offset);
                            break;
                        }
                        $readShell = new Variable(Variable::TYPE_STRING_OFFSET);
                        $readShell->stringOffset(
                            $container,
                            $byteIndex,
                            $this->context->errors,
                            $this->context,
                            $frame,
                            $scriptFile
                        );
                        $arg1->string($readShell->toString());
                    } elseif ($container->type === Variable::TYPE_ARRAY) {
                        if ($this->context->isGlobalsTable($container)) {
                            if (!$forWrite && !$fetchIs && Variable::TYPE_STRING === $arg3->type
                                && !$this->context->globalsTableOffsetIsSet($arg3)) {
                                $this->context->errors->undefinedGlobalVariable(
                                    $arg3->toString(),
                                    $this->context,
                                    $frame,
                                    '' !== $frame->scriptPath ? $frame->scriptPath : null
                                );
                            }
                            $arg1->indirect($this->context->globalsTableOffsetFetch($arg3, $forWrite));
                            break;
                        }
                        $table = $container->toArray();
                        try {
                            if (!$forWrite && !$fetchIs && !$table->keyExists($arg3, false, $frame, false)) {
                                $this->context->errors->undefinedArrayKey(
                                    $arg3,
                                    $this->context,
                                    $frame,
                                    '' !== $frame->scriptPath ? $frame->scriptPath : null
                                );
                            }
                            $arg1->indirect($table->findVariable($arg3, $forWrite, $this->context, $frame));
                            if ($forWrite) {
                                $this->tagHookedPropertyDimWriteLvalue($arg1, $containerSlot);
                            }
                        } catch (\TypeError $e) {
                            $catchFrame = $this->dispatchVmTypeError($e, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                        }
                    } elseif (
                        Variable::TYPE_OBJECT === $container->type
                        && VmDomCollectionDimension::isCollection($container->toObject())
                    ) {
                        // DOMNodeList / DOMNamedNodeMap read_dimension (php-src php_dom.c; #20311).
                        // Not ArrayAccess — writes stay "Cannot use object of type … as array".
                        if ($forWrite) {
                            $className = $container->toObject()->class->name;
                            $catchFrame = $this->dispatchVmError(
                                'Cannot use object of type ' . $className . ' as array',
                                $frame
                            );
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        }
                        try {
                            VmDomCollectionDimension::readDimension(
                                $container->toObject(),
                                $arg3,
                                $arg1
                            );
                        } catch (\ValueError $e) {
                            $catchFrame = $this->dispatchVmValueError($e, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                        } catch (\TypeError $e) {
                            // Dom\TokenList illegal offset (php-src token_list.c; #23006).
                            $catchFrame = $this->dispatchVmTypeError($e, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                        }
                    } elseif (
                        Variable::TYPE_OBJECT === $container->type
                        && VmResourceBundle::isResourceBundleObject($container->toObject())
                    ) {
                        // ResourceBundle read_dimension (php-src resourcebundle_class.c; #25145).
                        // No has_dimension / write_dimension — isset/write/unset stay Error.
                        if ($forWrite) {
                            $className = $container->toObject()->class->name;
                            $catchFrame = $this->dispatchVmError(
                                'Cannot use object of type ' . $className . ' as array',
                                $frame
                            );
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        }
                        VmResourceBundle::readDimension(
                            $this->context,
                            $container->toObject(),
                            $arg3,
                            $arg1
                        );
                    } elseif (
                        Variable::TYPE_OBJECT === $container->type
                        && $this->objectImplementsArrayAccess($container->toObject())
                    ) {
                        $object = $container->toObject();
                        if ($forWrite) {
                            $dim = new Variable();
                            $dim->arrayAccessDimension(new VM\ArrayAccessDimension($this, $object, $arg3, $frame));
                            $arg1->indirect($dim);
                        } else {
                            $readOut = new Variable();
                            $catchFrame = $this->invokeArrayAccessOffsetGet($object, $arg3, $frame, $readOut);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            $arg1->copyFrom($readOut);
                        }
                    } else {
                        $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
                        if (!$forWrite && TypeCheck::isScalarNonContainerDimRead($container)) {
                            if (!$fetchIs) {
                                $resolved = $container->resolveIndirect();
                                $this->context->errors->arrayOffsetOnNonContainer(
                                    TypeCheck::typeNameForConstraint($resolved->type),
                                    $this->context,
                                    $frame,
                                    $scriptFile
                                );
                            }
                            $arg1->null();
                            break;
                        }
                        if (
                            Variable::TYPE_OBJECT === $container->type
                            && !$this->objectImplementsArrayAccess($container->toObject())
                        ) {
                            $className = $container->toObject()->class->name;
                            $catchFrame = $this->dispatchVmError(
                                'Cannot use object of type ' . $className . ' as array',
                                $frame
                            );
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        }
                        if (TypeCheck::isScalarUsedAsArray($container)) {
                            $catchFrame = $this->dispatchVmError(
                                TypeCheck::SCALAR_USED_AS_ARRAY_MESSAGE,
                                $frame
                            );
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        }
                        throw new \LogicException('Illegal offset');
                    }
                    break;
                case OpCode::TYPE_CAST_BOOL:
                    try {
                        $frame->scope[$op->arg1]->castFrom(
                            Variable::TYPE_BOOLEAN,
                            $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2),
                            $this
                        );
                    } catch (\TypeError $e) {
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    }
                    break;
                case OpCode::TYPE_CAST_INT:
                    try {
                        $frame->scope[$op->arg1]->castFrom(
                            Variable::TYPE_INTEGER,
                            $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2),
                            $this,
                            $frame
                        );
                    } catch (\TypeError $e) {
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    }
                    break;
                case OpCode::TYPE_CAST_FLOAT:
                    try {
                        $frame->scope[$op->arg1]->castFrom(
                            Variable::TYPE_FLOAT,
                            $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2),
                            $this,
                            $frame
                        );
                    } catch (\TypeError $e) {
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    }
                    break;
                case OpCode::TYPE_CAST_STRING:
                    $savedCallSiteLine = $frame->callSiteLine;
                    if (null !== $op->arg3 && $op->arg3 > 0) {
                        $frame->callSiteLine = $op->arg3;
                    }
                    $castStringSrc = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2);
                    try {
                        $frame->scope[$op->arg1]->castFrom(
                            Variable::TYPE_STRING,
                            $castStringSrc,
                            $this,
                            $frame
                        );
                    } catch (\Error $e) {
                        $frame->callSiteLine = $savedCallSiteLine;
                        $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    } catch (\TypeError $e) {
                        $frame->callSiteLine = $savedCallSiteLine;
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    } catch (\BadMethodCallException $e) {
                        // SPL CachingIterator::__toString without CALL_TOSTRING (#24907).
                        $frame->callSiteLine = $savedCallSiteLine;
                        $catchFrame = $this->dispatchVmBadMethodCallException($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
                        $frame->callSiteLine = $savedCallSiteLine;
                        $frame = $this->resumeAfterBuiltinCallbackCatchRedirect($redirect);
                        goto restart;
                    } catch (VM\MagicMethodInvocationAborted) {
                        $frame->callSiteLine = $savedCallSiteLine;
                        $this->clearTryCatchUnwindState();
                        ++$frame->pos;
                        break;
                    }
                    $frame->callSiteLine = $savedCallSiteLine;
                    break;
                case OpCode::TYPE_CAST_ARRAY:
                    $frame->scope[$op->arg1]->copyFrom(
                        CastSupport::toArray(
                            $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2),
                            $this->context->classes
                        )
                    );
                    break;
                case OpCode::TYPE_CAST_OBJECT:
                    $src = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2)->resolveIndirect();
                    $dst = $frame->scope[$op->arg1];
                    if (Variable::TYPE_OBJECT === $src->type) {
                        $dst->copyFrom($src);
                        break;
                    }
                    if (Variable::TYPE_ENUM_CASE === $src->type) {
                        $dst->copyFrom(VM\EnumCaseSupport::receiverForInstanceMethod($src));
                        break;
                    }
                    if (!isset($this->context->classes['stdclass'])) {
                        throw new \LogicException('stdClass is not registered');
                    }
                    $object = new VM\ObjectEntry($this->context->classes['stdclass']);
                    $object->constructed = true;
                    $dst->object($object);
                    if (Variable::TYPE_ARRAY === $src->type) {
                        foreach ($src->toArray()->iterateKeyed(true) as [$keyVar, $valueVar]) {
                            $propName = $keyVar->is(Variable::TYPE_INTEGER)
                                ? (string) $keyVar->toInt()
                                : $keyVar->toString();
                            $object->allocateProperty($propName)->copyFrom(
                                VM\ClassConstMaterializer::detachConstantValue($valueVar)
                            );
                        }
                    }
                    $this->markScopeSlotInitialized($frame, (int) $op->arg1);
                    break;
                case OpCode::TYPE_CAST_UNSET:
                    $src = $frame->scope[$op->arg2];
                    if ($this->slotIsReferenceBinding($src, $frame->scope)) {
                        $src->reset();
                        $src->type = Variable::TYPE_UNDEFINED;
                    }
                    $frame->scope[$op->arg1]->null();
                    break;
                case OpCode::TYPE_CAST_VOID:
                    $frame->scope[$op->arg1]->null();
                    break;
                case OpCode::TYPE_IDENTICAL:
                    // Match arms lower to IDENTICAL — warn on undefined CV reads (#26147, #10358).
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2);
                    $arg3 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg3);
                    $arg1->bool($arg2->identicalTo($arg3));
                    break;
                case OpCode::TYPE_NOT_IDENTICAL:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2);
                    $arg3 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg3);
                    $arg1->bool(!$arg2->identicalTo($arg3));
                    $this->releaseVmBinaryOpOperandTemp($frame, (int) $op->arg2, (int) $op->arg1, (int) $op->arg3);
                    $this->releaseVmBinaryOpOperandTemp($frame, (int) $op->arg3, (int) $op->arg1, (int) $op->arg2);
                    break;
                case OpCode::TYPE_EQUAL:
                    // Switch cases lower to EQUAL — same undefined-CV warning path (#26147).
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2);
                    $arg3 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg3);
                    try {
                        $arg1->bool($arg2->equals($arg3, $this));
                    } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
                        $frame = $this->resumeAfterBuiltinCallbackCatchRedirect($redirect);
                        goto restart;
                    } catch (VM\MagicMethodInvocationAborted) {
                        $this->clearTryCatchUnwindState();
                        ++$frame->pos;
                        break;
                    }
                    break;
                case OpCode::TYPE_NOT_EQUAL:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2);
                    $arg3 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg3);
                    try {
                        $arg1->bool(!$arg2->equals($arg3, $this));
                    } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
                        $frame = $this->resumeAfterBuiltinCallbackCatchRedirect($redirect);
                        goto restart;
                    } catch (VM\MagicMethodInvocationAborted) {
                        $this->clearTryCatchUnwindState();
                        ++$frame->pos;
                        break;
                    }
                    break;
                case OpCode::TYPE_LOGICAL_XOR:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2);
                    $arg3 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg3);
                    $arg1->bool($arg2->toBool($this) !== $arg3->toBool($this));
                    break;
                case OpCode::TYPE_SMALLER:
                case OpCode::TYPE_GREATER:
                case OpCode::TYPE_SMALLER_OR_EQUAL:
                case OpCode::TYPE_GREATER_OR_EQUAL:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2);
                    $arg3 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg3);
                    try {
                        $arg1->compareOp($op->type, $arg2, $arg3, $this);
                    } catch (\TypeError $e) {
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
                        // __toString throw during relational compare (#29534).
                        $frame = $this->resumeAfterBuiltinCallbackCatchRedirect($redirect);
                        goto restart;
                    }
                    break;
                case OpCode::TYPE_SPACESHIP:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2);
                    $arg3 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg3);
                    try {
                        $arg1->spaceshipOp($arg2, $arg3, $this);
                    } catch (\TypeError $e) {
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
                        // __toString throw during <=> (#29534).
                        $frame = $this->resumeAfterBuiltinCallbackCatchRedirect($redirect);
                        goto restart;
                    }
                    break;
                case OpCode::TYPE_POST_INC:
                    $catchFrame = $this->executeIncDec($frame, $op, true, false);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    break;
                case OpCode::TYPE_PRE_INC:
                    $catchFrame = $this->executeIncDec($frame, $op, true, true);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    break;
                case OpCode::TYPE_POST_DEC:
                    $catchFrame = $this->executeIncDec($frame, $op, false, false);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    break;
                case OpCode::TYPE_PRE_DEC:
                    $catchFrame = $this->executeIncDec($frame, $op, false, true);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    break;
                case OpCode::TYPE_PLUS:
                case OpCode::TYPE_MINUS:
                case OpCode::TYPE_MUL:
                case OpCode::TYPE_DIV:
                case OpCode::TYPE_MODULO:
                case OpCode::TYPE_POW:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    $arg3 = $frame->scope[$op->arg3];
                    $readArg2 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2);
                    $readArg3 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg3);
                    $catchFrame = $this->enforceReadonlyForCompoundAssign($frame, $op, $arg2);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    if ($op->arg1 === $op->arg2) {
                        $hookedRead = $this->fetchHookedPropertyValueForIncDec($arg2, $frame);
                        if (null !== $hookedRead) {
                            $catchFrame = $this->executeHookedPropertyInPlaceCompound($frame, $op, $hookedRead);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        }
                    }
                    try {
                        $numericArg2 = $op->arg1 !== $op->arg2 ? $readArg2 : $arg2;
                        $numericArg3 = $readArg3;
                        if (
                            $op->isIncDec
                            && (OpCode::TYPE_PLUS === $op->type || OpCode::TYPE_MINUS === $op->type)
                        ) {
                            $arg1->incDecOp($op->type, $numericArg2, $numericArg3, $this, $frame);
                        } else {
                            $arg1->numericOp($op->type, $numericArg2, $numericArg3, $this, $frame);
                        }
                    } catch (\TypeError $e) {
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    } catch (\DivisionByZeroError $e) {
                        $catchFrame = $this->dispatchVmDivisionByZeroError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    } catch (\ArithmeticError $e) {
                        $catchFrame = $this->dispatchVmArithmeticError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    } catch (\Error $e) {
                        $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    $this->markScopeSlotInitializedIfNamedLocal($frame, (int) $op->arg1);
                    break;
                case OpCode::TYPE_BITWISE_AND:
                case OpCode::TYPE_BITWISE_OR:
                case OpCode::TYPE_BITWISE_XOR:
                case OpCode::TYPE_SHIFT_LEFT:
                case OpCode::TYPE_SHIFT_RIGHT:
                    $arg1 = $frame->scope[$op->arg1];
                    $readArg2 = $this->readRuntimeOperandForBitwise($frame, (int) $op->arg2);
                    $readArg3 = $this->readRuntimeOperandForBitwise($frame, (int) $op->arg3);
                    $arg2 = $op->arg1 !== $op->arg2 ? $readArg2 : $frame->scope[$op->arg2];
                    $arg3 = $readArg3;
                    $catchFrame = $this->enforceReadonlyForCompoundAssign($frame, $op, $arg2);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    if ($op->arg1 === $op->arg2) {
                        $hookedRead = $this->fetchHookedPropertyValueForIncDec($arg2, $frame);
                        if (null !== $hookedRead) {
                            $catchFrame = $this->executeHookedPropertyInPlaceCompound($frame, $op, $hookedRead);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        }
                    }
                    try {
                        $arg1->bitwiseOp($op->type, $arg2, $arg3, $this, $frame);
                    } catch (\TypeError $e) {
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    } catch (\ArithmeticError $e) {
                        // Negative << / >> — Zend ArithmeticError must be user-catchable (#21912).
                        $catchFrame = $this->dispatchVmArithmeticError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    } catch (\Error $e) {
                        $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    $this->markScopeSlotInitializedIfNamedLocal($frame, (int) $op->arg1);
                    break;

                case OpCode::TYPE_UNARY_MINUS:
                case OpCode::TYPE_UNARY_PLUS:
                case OpCode::TYPE_BITWISE_NOT:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2);
                    try {
                        $arg1->unaryOp($op->type, $arg2, $this, $frame);
                    } catch (\TypeError $e) {
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    break;
                case OpCode::TYPE_CONCAT:
                    $arg1 = $frame->scope[$op->arg1];
                    $catchFrame = $this->enforceReadonlyForCompoundAssign($frame, $op, $arg1);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    if ($op->arg1 === $op->arg2) {
                        $hookedRead = $this->fetchHookedPropertyValueForIncDec($arg1, $frame);
                        if (null !== $hookedRead) {
                            $catchFrame = $this->executeHookedPropertyInPlaceCompound($frame, $op, $hookedRead);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        }
                    }
                    try {
                        // Zend: assign-op on string offsets before concat (#22897).
                        Variable::rejectAssignOpOnStringOffset(
                            $arg1,
                            $frame->scope[(int) $op->arg2]
                        );
                        $left = $op->arg1 === $op->arg2
                            ? $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2)
                            : $this->readRuntimeOperandForConcat($frame, (int) $op->arg2);
                        $right = $this->readRuntimeOperandForConcat($frame, (int) $op->arg3);
                        $result = new Variable();
                        $result->string(
                            $this->coerceVariableToString($left, $frame)
                            . $this->coerceVariableToString($right, $frame)
                        );
                        $arg1->copyFrom($result);
                    } catch (\Error $e) {
                        $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    } catch (\TypeError $e) {
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
                        // __toString throw during concat — resume catch on outer stack (#29521).
                        $frame = $this->resumeAfterBuiltinCallbackCatchRedirect($redirect);
                        goto restart;
                    } catch (VM\MagicMethodInvocationAborted) {
                        $this->clearTryCatchUnwindState();
                        ++$frame->pos;
                        $frame->suppressNextEcho = true;
                        break;
                    }
                    $this->markScopeSlotInitializedIfNamedLocal($frame, (int) $op->arg1);
                    break;
                case OpCode::TYPE_ECHO:
                    if ($frame->suppressNextEcho) {
                        $frame->suppressNextEcho = false;
                        break;
                    }
                    try {
                        if (!VM\SapiOutput::headersSent()) {
                            VM\HeaderCallbackQueue::runBeforeOutput($this->context);
                        }
                        $printed = $this->valueToPrintString(
                            $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg1),
                            $frame
                        );
                    } catch (\Error $e) {
                        $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    } catch (\TypeError $e) {
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
                        // __toString throw during echo — do not continue try body (#29521).
                        $frame = $this->resumeAfterBuiltinCallbackCatchRedirect($redirect);
                        goto restart;
                    } catch (VM\MagicMethodInvocationAborted) {
                        break;
                    }
                    $this->releaseVmStatementDeadTemps($frame, (int) $op->arg1);
                    $echoFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
                    VM\OutputBuffer::append($printed, $echoFile, (int) ($op->arg2 ?? 0));
                    break;
                case OpCode::TYPE_PRINT:
                    try {
                        if (!VM\SapiOutput::headersSent()) {
                            VM\HeaderCallbackQueue::runBeforeOutput($this->context);
                        }
                        $printFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
                        VM\OutputBuffer::append(
                            $this->valueToPrintString($frame->scope[$op->arg2], $frame),
                            $printFile,
                            (int) ($op->arg3 ?? 0)
                        );
                        $frame->scope[$op->arg1]->int(1);
                    } catch (\Error $e) {
                        $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    } catch (\TypeError $e) {
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
                        // __toString throw during print — do not continue try body (#29521).
                        $frame = $this->resumeAfterBuiltinCallbackCatchRedirect($redirect);
                        goto restart;
                    } catch (VM\MagicMethodInvocationAborted) {
                        break;
                    }
                    break;
                case OpCode::TYPE_EVAL:
                    $codeVar = $frame->scope[$op->arg2]->resolveIndirect();
                    $dest = $frame->scope[$op->arg1];
                    if (Variable::TYPE_STRING !== $codeVar->type) {
                        return $this->raise('eval() expects a string argument', $frame);
                    }
                    try {
                        $evalResult = VmEval::evalCodeInFrame(
                            $this,
                            $frame,
                            $codeVar->toString()
                        );
                    } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
                        // Outer try matched from nested eval runFrames — resume catch here (#25816).
                        $frame = $this->resumeAfterBuiltinCallbackCatchRedirect($redirect);
                        goto restart;
                    } catch (\ParseError $e) {
                        $catchFrame = $this->dispatchVmParseError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }

                        return null;
                    } catch (\CompileError $e) {
                        // php-src: zend_throw_exception(CompileError) is catchable in eval (#25114);
                        // zend_inheritance.c zend_error_noreturn(E_COMPILE_ERROR) is not (#22922, #22329).
                        if (!VmEval::isCatchableCompileError($e)) {
                            $this->raiseEvalCompileFatal($e, $frame);
                        }
                        $catchFrame = $this->dispatchVmEvalCompileError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }

                        return null;
                    }
                    $dest->copyFrom($evalResult);
                    break;
                case OpCode::TYPE_COALESCE:
                    $check = $frame->scope[$op->arg2]->resolveIndirect();
                    if (Variable::TYPE_BOOLEAN === $check->type) {
                        $takeLeft = $check->toBool($this);
                    } else {
                        $takeLeft = VM\CoalesceJitHelper::takeLeftBranchFromTypeByte($check->type);
                    }
                    $frame = ($takeLeft ? $op->block1 : $op->block2)->getFrame(
                        $this->context,
                        $frame
                    );
                    goto restart;
                case OpCode::TYPE_NULLSAFE:
                    $receiver = $frame->scope[$op->arg2];
                    $frame = (
                        VM\TypedPropertyCheck::nullsafeShortCircuitReceiver(
                            $receiver,
                            $op->nullsafeMethodCall
                        )
                            ? $op->block1
                            : $op->block2
                    )->getFrame($this->context, $frame);
                    goto restart;
                case OpCode::TYPE_BEGIN_SILENCE:
                    $this->context->errors->beginSilence();
                    break;
                case OpCode::TYPE_END_SILENCE:
                    $this->context->errors->endSilence();
                    break;
                case OpCode::TYPE_EXIT:
                    $exitArg = null;
                    if (null !== $op->arg2) {
                        $exitArg = $frame->scope[$op->arg2];
                    }
                    $exitMessage = null;
                    if (null !== $op->exitMessageSlot) {
                        $exitMessage = $frame->scope[$op->exitMessageSlot];
                    }
                    $savedCallSiteLine = $frame->callSiteLine;
                    if (null !== $op->arg3 && $op->arg3 > 0) {
                        $frame->callSiteLine = $op->arg3;
                    }
                    try {
                        ext\standard\VmExit::terminate($exitArg, $frame, $exitMessage);
                    } catch (\TypeError $e) {
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        $frame->callSiteLine = $savedCallSiteLine;
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    } catch (\Error $e) {
                        $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                        $frame->callSiteLine = $savedCallSiteLine;
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    }
                    $frame->callSiteLine = $savedCallSiteLine;
                    break;
                case OpCode::TYPE_JUMP:
                    if ($this->completeActiveFinallyUnwind($frame)) {
                        goto restart;
                    }
                    $finallyFrame = $this->beginCatchExitFinallyUnwind($frame, $op->block1);
                    if (null !== $finallyFrame) {
                        $frame = $finallyFrame;
                        goto restart;
                    }
                    $finallyFrame = $this->beginGotoFinallyUnwind($frame, $op->block1);
                    if (null !== $finallyFrame) {
                        $frame = $finallyFrame;
                        goto restart;
                    }
                    if (
                        null !== $frame->listUnpackAssignMergeBlock
                        && $op->block1 === $frame->listUnpackAssignMergeBlock
                    ) {
                        $frame->listUnpackAssignMergeBlock = null;
                    }
                    $frame = $this->frameForBranch($frame, $op->block1);
                    goto restart;
                case OpCode::TYPE_JUMPIF:
                    $condSlot = (int) $op->arg1;
                    $arg1 = $frame->scope[$condSlot]->toBool();
                    $this->releaseVmStatementDeadTemps($frame, $condSlot);
                    $this->releaseVmJumpIfCondTemps($frame, $condSlot);
                    $branchTarget = $arg1 ? $op->block1 : $op->block2;
                    // break/continue lower to JumpIf edges that leave the try body; run finally
                    // before the branch target (Zend ZEND_BRK/ZEND_CONT, #25240).
                    if ($this->completeActiveFinallyUnwind($frame)) {
                        goto restart;
                    }
                    $finallyFrame = $this->beginCatchExitFinallyUnwind($frame, $branchTarget);
                    if (null !== $finallyFrame) {
                        $frame = $finallyFrame;
                        goto restart;
                    }
                    $finallyFrame = $this->beginGotoFinallyUnwind($frame, $branchTarget);
                    if (null !== $finallyFrame) {
                        $frame = $finallyFrame;
                        goto restart;
                    }
                    $frame = $this->frameForBranch($frame, $branchTarget);
                    goto restart;
                case OpCode::TYPE_CASE:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    try {
                        if ($arg1->equals($arg2, $this)) {
                            $frame = $op->block1->getFrame($this->context, $frame);
                            goto restart;
                        }
                    } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
                        $frame = $this->resumeAfterBuiltinCallbackCatchRedirect($redirect);
                        goto restart;
                    } catch (VM\MagicMethodInvocationAborted) {
                        $this->clearTryCatchUnwindState();
                        ++$frame->pos;
                        break;
                    }
                    break;
                case OpCode::TYPE_CONST_FETCH:
                    $value = null;
                    if (!is_null($op->arg3)) {
                        // try NS constant fetch
                        $value = $this->context->constantFetch($frame->scope[$op->arg3]->toString());
                    }
                    if (is_null($value)) {
                        $value = $this->context->constantFetch($frame->scope[$op->arg2]->toString());
                    }
                    if (is_null($value)) {
                        // arg3 is php-cfg's namespace-qualified name (N\NAME), not bare namespace (#10510).
                        $constName = null !== $op->arg3
                            ? $frame->scope[$op->arg3]->toString()
                            : $frame->scope[$op->arg2]->toString();
                        $catchFrame = $this->dispatchVmError(
                            sprintf('Undefined constant "%s"', $constName),
                            $frame
                        );
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }

                        return self::EXCEPTION;
                    }
                    $constName = null !== $op->arg3
                        ? $frame->scope[$op->arg3]->toString()
                        : $frame->scope[$op->arg2]->toString();
                    $this->emitGlobalConstFetchDeprecation($constName, $frame);
                    $frame->scope[$op->arg1]->copyFrom($value);
                    $this->markScopeSlotInitialized($frame, (int) $op->arg1);
                    break;
                case OpCode::TYPE_STATICCALL_INIT:
                    $instanceScopeCall = false;
                    $scopeClassName = null;
                    $staticCallMethodName = '';
                    $selfKeywordScope = false;
                    try {
                        $classOperand = $frame->scope[$op->arg1]->resolveIndirect();
                        $staticCallMethodName = $frame->scope[$op->arg2]->toString();
                        $parentKeywordScope = $op->staticCallParentScope;
                        $enumScopeClass = VM\EnumCaseSupport::enumClassForCaseVariable($classOperand);
                        if (null !== $enumScopeClass) {
                            // (E::A)::staticMethod() — enum case scope resolves to enum type (#6408, zend_enum.c).
                            $instanceScopeCall = true;
                            $scopeClassName = $enumScopeClass->name;
                            $callableName = $scopeClassName.'::'.$staticCallMethodName;
                        } elseif (Variable::TYPE_OBJECT === $classOperand->type) {
                            $instanceScopeCall = true;
                            $scopeClassName = $classOperand->toObject()->class->name;
                            $callableName = $scopeClassName.'::'.$staticCallMethodName;
                        } else {
                            $className = $classOperand->toString();
                            if (!$parentKeywordScope) {
                                $parentKeywordScope = 'parent' === strtolower($className);
                            }
                            // Lexical self:: (php-cfg may keep the keyword) — preserve LSB (#21983).
                            $selfKeywordScope = 'self' === strtolower($className);
                            $lcClass = $this->resolveClassScopeName($className, $frame);
                            $resolvedClassName = isset($this->context->classes[$lcClass])
                                ? $this->context->classes[$lcClass]->name
                                : $className;
                            $callableName = $resolvedClassName.'::'.$staticCallMethodName;
                        }
                        $this->initStaticCallable(
                            $frame,
                            $callableName,
                            $parentKeywordScope,
                            $selfKeywordScope
                        );
                    } catch (\Error $e) {
                        $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        return self::EXCEPTION;
                    } catch (\LogicException $e) {
                        if ($instanceScopeCall && str_starts_with($e->getMessage(), 'Call to undefined static method ')) {
                            $catchFrame = $this->dispatchVmError(
                                "Call to undefined method {$scopeClassName}::{$staticCallMethodName}()",
                                $frame
                            );
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            return self::EXCEPTION;
                        }
                        $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        return self::EXCEPTION;
                    }
                    break;
                case OpCode::TYPE_CLASS_CONST_FETCH:
                    $memberNameRaw = $frame->scope[$op->arg3]->toString();
                    $classOperand = $frame->scope[$op->arg2]->resolveIndirect();
                    if ($op->classConstFetchOnObject) {
                        if ('class' === strtolower($memberNameRaw)) {
                            $fqcn = $this->resolveClassPseudoConstFromOperand($classOperand);
                            if (null !== $fqcn) {
                                $frame->scope[$op->arg1]->string($fqcn);
                                break;
                            }
                            $catchFrame = $this->dispatchVmTypeError(
                                new \TypeError(
                                    VM\EnumCaseSupport::classPseudoConstTypeErrorMessage($classOperand)
                                ),
                                $frame
                            );
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        }
                        if (Variable::TYPE_OBJECT !== $classOperand->type) {
                            $catchFrame = $this->dispatchVmTypeError(
                                new \TypeError(
                                    VM\EnumCaseSupport::classPseudoConstTypeErrorMessage($classOperand)
                                ),
                                $frame
                            );
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        }
                        $frame->scope[$op->arg1]->string($classOperand->toObject()->class->name);
                        break;
                    }
                    if (Variable::TYPE_OBJECT === $classOperand->type) {
                        $classEntry = $classOperand->toObject()->class;
                        $traitConstFrame = $this->enforceDirectTraitConstAccess($classEntry, $memberNameRaw, $frame);
                        if (null !== $traitConstFrame) {
                            $frame = $traitConstFrame;
                            goto restart;
                        }
                        $constKey = ClassConstName::key($memberNameRaw);
                        if (isset($classEntry->constants[$constKey])
                            && ClassConstName::matchesDeclared(
                                $memberNameRaw,
                                $this->declaredClassConstName($classEntry, $constKey)
                            )
                        ) {
                            $visFrame = $this->enforceClassConstVisibility($classEntry, $memberNameRaw, $frame);
                            if (null !== $visFrame) {
                                $frame = $visFrame;
                                goto restart;
                            }
                        }
                        $staticVisFrame = $this->enforceStaticPropertyReadVisibility(
                            strtolower($classEntry->name),
                            $memberNameRaw,
                            $frame
                        );
                        if (null !== $staticVisFrame) {
                            $frame = $staticVisFrame;
                            goto restart;
                        }
                        try {
                            if (!$this->copyClassConstOrStaticPropertyByName(
                                $classEntry,
                                $memberNameRaw,
                                $frame->scope[$op->arg1],
                                $frame
                            )) {
                                $catchFrame = $this->dispatchVmError(
                                    "Undefined constant {$classEntry->name}::{$memberNameRaw}",
                                    $frame
                                );
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }

                                return self::EXCEPTION;
                            }
                        } catch (\Error $e) {
                            $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }

                            return self::EXCEPTION;
                        }
                        break;
                    }
                    try {
                        $classOperand = $frame->scope[$op->arg2]->resolveIndirect();
                        $constName = strtolower($frame->scope[$op->arg3]->toString());
                        if (Variable::TYPE_OBJECT === $classOperand->type && 'class' === $constName) {
                            $frame->scope[$op->arg1]->string($classOperand->toObject()->class->name);
                            break;
                        }
                        $lcClass = $this->resolveClassScopeName(
                            $classOperand->toString(),
                            $frame
                        );
                    } catch (\LogicException $e) {
                        $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        return self::EXCEPTION;
                    }
                    $className = $frame->scope[$op->arg2]->resolveIndirect()->toString();
                    if (!isset($this->context->classes[$lcClass])) {
                        if ('self' !== strtolower($className) && 'static' !== strtolower($className)) {
                            $this->context->autoloadClass($className);
                        }
                    }
                    if (!isset($this->context->classes[$lcClass])) {
                        // `ConstName::class` when ConstName is a user constant (Zend zend_compile.c; #5440).
                        if ('class' === $constName && null !== $this->context->constantFetch($className)) {
                            $frame->scope[$op->arg1]->string($className);
                            break;
                        }
                        if ('class' === $constName) {
                            $builtinName = BuiltinTypeClassConstant::classNameForTypeOperand($className);
                            if (null !== $builtinName) {
                                $frame->scope[$op->arg1]->string($builtinName);
                                break;
                            }
                            $builtinFn = BuiltinFunctionClassConstant::functionNameForClassOperand($className);
                            if (null !== $builtinFn) {
                                $frame->scope[$op->arg1]->string($builtinFn);
                                break;
                            }
                            if ('self' !== strtolower($className) && 'static' !== strtolower($className)) {
                                // Foo::class is a pure name literal — Zend resolves it
                                // without the class being declared (#16828).
                                $frame->scope[$op->arg1]->string(ltrim($className, '\\'));
                                break;
                            }
                        }

                        // Missing class on Class::CONST / Enum::Case — catchable Error
                        // (zend_execute.c), not LogicException via raise() (#28480).
                        $catchFrame = $this->dispatchVmError(
                            $this->classNotFoundMessage($className),
                            $frame
                        );
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }

                        return self::EXCEPTION;
                    }
                    $classEntry = $this->context->classes[$lcClass];
                    $traitConstFrame = $this->enforceDirectTraitConstAccess($classEntry, $memberNameRaw, $frame);
                    if (null !== $traitConstFrame) {
                        $frame = $traitConstFrame;
                        goto restart;
                    }
                    $constKey = ClassConstName::key($memberNameRaw);
                    if (isset($classEntry->constants[$constKey])
                        && ClassConstName::matchesDeclared(
                            $memberNameRaw,
                            $this->declaredClassConstName($classEntry, $constKey)
                        )
                    ) {
                        $visFrame = $this->enforceClassConstVisibility($classEntry, $memberNameRaw, $frame);
                        if (null !== $visFrame) {
                            $frame = $visFrame;
                            goto restart;
                        }
                    }
                    $staticVisFrame = $this->enforceStaticPropertyReadVisibility($lcClass, $memberNameRaw, $frame);
                    if (null !== $staticVisFrame) {
                        $frame = $staticVisFrame;
                        goto restart;
                    }
                    if ('class' === $constName) {
                        $frame->scope[$op->arg1]->string(
                            $this->resolveClassPseudoConstDisplayName($className, $frame)
                        );
                        break;
                    }
                    try {
                        if (!$this->copyClassConstOrStaticPropertyByName(
                            $classEntry,
                            $memberNameRaw,
                            $frame->scope[$op->arg1],
                            $frame
                        )) {
                            $catchFrame = $this->dispatchVmError(
                                "Undefined constant {$className}::{$memberNameRaw}",
                                $frame
                            );
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }

                            return self::EXCEPTION;
                        }
                    } catch (\Error $e) {
                        $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }

                        return self::EXCEPTION;
                    }
                    break;
                case OpCode::TYPE_INSTANCEOF:
                    try {
                        $value = $frame->scope[$op->arg2];
                        $matches = false;
                        $unionEncoded = $op->instanceofUnionTypes;
                        if (null !== $unionEncoded && '' !== $unionEncoded) {
                            foreach (explode('|', $unionEncoded) as $typeName) {
                                if ('' === $typeName) {
                                    continue;
                                }
                                if ($this->valueInstanceOfClassName($value, $typeName)) {
                                    $matches = true;
                                    break;
                                }
                            }
                        } else {
                            $className = VM\InstanceOfClassName::resolveClassName($frame->scope[$op->arg3]);
                            $matches = $this->valueInstanceOfClassName($value, $className);
                        }
                        $frame->scope[$op->arg1]->bool($matches);
                    } catch (\Error $e) {
                        $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }

                        return self::EXCEPTION;
                    }
                    break;
                case OpCode::TYPE_IN:
                    try {
                        $found = VM\InOperator::contains(
                            $frame->scope[$op->arg2],
                            $frame->scope[$op->arg3]
                        );
                        $frame->scope[$op->arg1]->bool($found);
                    } catch (\TypeError $e) {
                        return $this->raise($e->getMessage(), $frame);
                    }
                    break;
                case OpCode::TYPE_STATIC_PROPERTY_FETCH:
                    $classOperand = $frame->scope[$op->arg2]->resolveIndirect();
                    $lcClass = $this->resolveStaticPropertyClassLc($frame->scope[$op->arg2], $frame);
                    if (!isset($this->context->classes[$lcClass])) {
                        $rawClass = Variable::TYPE_OBJECT === $classOperand->type
                            ? $classOperand->toObject()->class->name
                            : $classOperand->toString();
                        if ('self' !== strtolower($rawClass) && 'static' !== strtolower($rawClass)) {
                            $this->context->autoloadClass($rawClass);
                        }
                    }
                    if (!isset($this->context->classes[$lcClass])) {
                        $rawClass = Variable::TYPE_OBJECT === $classOperand->type
                            ? $classOperand->toObject()->class->name
                            : $classOperand->toString();

                        return $this->raise("Unknown class for static property fetch: {$rawClass}", $frame);
                    }
                    $propNameRaw = $frame->scope[$op->arg3]->toString();
                    $propName = strtolower($propNameRaw);
                    $forWrite = $this->propertyFetchDestUsedAsAssignLvalue($frame, $op);
                    $forIncDec = $this->propertyFetchDestUsedAsIncDec($frame, $op);
                    $mutates = $forWrite || $forIncDec;
                    if (!$mutates) {
                        $visFrame = $this->enforceStaticPropertyReadVisibility($lcClass, $propNameRaw, $frame);
                        if (null !== $visFrame) {
                            $frame = $visFrame;
                            goto restart;
                        }
                    }
                    $storage = $this->resolveStaticPropertyStorage($lcClass, $propName);
                    if (null === $storage) {
                        $classLabel = $this->context->classes[$lcClass]->name;
                        $catchFrame = $this->dispatchVmError(
                            "Access to undeclared static property {$classLabel}::\${$propNameRaw}",
                            $frame
                        );
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }

                        return self::EXCEPTION;
                    }
                    if ($mutates) {
                        $writeVisFrame = $this->enforceStaticPropertyWriteVisibility($lcClass, $propNameRaw, $frame);
                        if (null !== $writeVisFrame) {
                            $frame = $writeVisFrame;
                            goto restart;
                        }
                        $writeMsg = $this->asymmetricStaticPropertyWriteMessage($lcClass, $propNameRaw, $frame);
                        if (null !== $writeMsg) {
                            $writeVisFrame = $this->dispatchVmError($writeMsg, $frame);
                            if (null !== $writeVisFrame) {
                                $frame = $writeVisFrame;
                                goto restart;
                            }
                        }
                    }
                    $readBeforeAssign = $forWrite && $this->propertyFetchDestUsedAsReadBeforeAssign($frame, $op);
                    $hooks = $this->resolveStaticPropertyHooks($lcClass, $propName);
                    if ($op->propertyHookCoalesceRead && !$mutates) {
                        // Static ?? also rejects virtual write-only (#29240, zend_object_handlers.c).
                        $catchFrame = $this->enforceWriteOnlyVirtualStaticPropertyRead(
                            $lcClass,
                            $propNameRaw,
                            $frame
                        );
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        $dest = $frame->scope[$op->arg1];
                        try {
                            $this->fetchStaticPropertyForCoalesce($lcClass, $propNameRaw, $dest, $frame);
                        } catch (VM\PropertyHookRefWriteSignal $signal) {
                            $frame = $signal->catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    if (
                        !$mutates
                        && null !== $hooks
                        && isset($hooks['get'])
                        && !$this->isPropertyHookRawWrite($frame, $propNameRaw)
                    ) {
                        $hookValue = $this->fetchStaticPropertyWithHooks($lcClass, $propNameRaw, $hooks['get'], $frame);
                        $dest = $frame->scope[$op->arg1];
                        if (
                            $this->propertyFetchDestUsedAsDimWriteContainer($frame, $op)
                            && isset($hooks['set'])
                        ) {
                            $catchFrame = $this->deliverHookedStaticPropertyDimWriteContainer(
                                $dest,
                                $hookValue,
                                $lcClass,
                                $propNameRaw,
                                $frame
                            );
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                        } else {
                            $dest->copyFrom($hookValue);
                        }
                        if (!$forWrite) {
                            $this->emitStaticPropertyAccessDeprecation($lcClass, $propNameRaw, $frame);
                        }
                        break;
                    }
                    if (
                        $forWrite
                        && null !== $hooks
                        && isset($hooks['set'])
                        && !$this->isPropertyHookRawWrite($frame, $propNameRaw)
                    ) {
                        if ($readBeforeAssign && isset($hooks['get'])) {
                            $hookValue = $this->fetchStaticPropertyWithHooks($lcClass, $propNameRaw, $hooks['get'], $frame);
                            $dest = $frame->scope[$op->arg1];
                            $dest->copyFrom($hookValue);
                            $dest->staticPropertyClassLc = $lcClass;
                            $dest->objectPropertyName = $propNameRaw;
                            $this->emitStaticPropertyAccessDeprecation($lcClass, $propNameRaw, $frame);
                            break;
                        }
                        $dest = $frame->scope[$op->arg1];
                        $dest->indirect($storage);
                        if ($this->propertyFetchDestUsedAsLiveRefBinding($frame, $op)) {
                            $dest->propertyRefAcquisition = true;
                        } else {
                            $dest->propertyAssignLvalue = true;
                        }
                        $dest->staticPropertyClassLc = $lcClass;
                        $dest->objectPropertyName = $propNameRaw;
                        $storage->staticPropertyClassLc = $lcClass;
                        $storage->objectPropertyName = $propNameRaw;
                        break;
                    }
                    $dest = $frame->scope[$op->arg1];
                    if (
                        !$mutates
                        && $this->isPropertyHookRawWrite($frame, $propNameRaw)
                    ) {
                        $backing = $this->hookedStaticPropertyBackingValue($lcClass, $propNameRaw);
                        if (false !== $backing) {
                            $dest->copyFromForClone($backing);
                        } else {
                            $dest->copyFromForClone($storage);
                        }
                        break;
                    }
                    if (
                        !$mutates
                        && $this->propertyFetchDestUsedAsDimWriteContainer($frame, $op)
                    ) {
                        $writeMsg = $this->asymmetricStaticPropertyWriteMessage($lcClass, $propNameRaw, $frame);
                        if (null !== $writeMsg) {
                            $writeVisFrame = $this->dispatchVmError($writeMsg, $frame);
                            if (null !== $writeVisFrame) {
                                $frame = $writeVisFrame;
                                goto restart;
                            }
                        }
                    }
                    if (!$mutates) {
                        VM\TypedPropertyCheck::assertReadable($storage);
                    }
                    $dest->indirect($storage);
                    if ($forWrite) {
                        if ($this->propertyFetchDestUsedAsLiveRefBinding($frame, $op)) {
                            $dest->propertyRefAcquisition = true;
                        } else {
                            $dest->propertyAssignLvalue = true;
                        }
                    }
                    $dest->staticPropertyClassLc = $lcClass;
                    $dest->objectPropertyName = $propNameRaw;
                    if (!$mutates) {
                        $this->emitStaticPropertyAccessDeprecation($lcClass, $propNameRaw, $frame);
                    }
                    break;
                case OpCode::TYPE_STATIC_PROPERTY_UNSET:
                    $classOperand = $frame->scope[$op->arg2]->resolveIndirect();
                    $lcClass = $this->resolveStaticPropertyClassLc($frame->scope[$op->arg2], $frame);
                    if (!isset($this->context->classes[$lcClass])) {
                        $rawClass = Variable::TYPE_OBJECT === $classOperand->type
                            ? $classOperand->toObject()->class->name
                            : $classOperand->toString();
                        if ('self' !== strtolower($rawClass) && 'static' !== strtolower($rawClass)) {
                            $this->context->autoloadClass($rawClass);
                        }
                    }
                    if (!isset($this->context->classes[$lcClass])) {
                        $rawClass = Variable::TYPE_OBJECT === $classOperand->type
                            ? $classOperand->toObject()->class->name
                            : $classOperand->toString();

                        return $this->raise("Unknown class for static property unset: {$rawClass}", $frame);
                    }
                    $propNameRaw = $frame->scope[$op->arg3]->toString();
                    $propName = strtolower($propNameRaw);
                    $storage = $this->resolveStaticPropertyStorage($lcClass, $propName);
                    if (null === $storage) {
                        $classLabel = $this->context->classes[$lcClass]->name;
                        $catchFrame = $this->dispatchVmError(
                            "Access to undeclared static property {$classLabel}::\${$propNameRaw}",
                            $frame
                        );
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }

                        return self::EXCEPTION;
                    }
                    $catchFrame = $this->enforceVirtualStaticPropertyHookUnset($lcClass, $propName, $propNameRaw, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    // Zend zend_std_unset_static_property: Error for all statics (#23691), not only typed (#6648).
                    // Raw writes inside property-hook methods may still clear backing storage.
                    $catchFrame = $this->enforceStaticPropertyUnset($lcClass, $propNameRaw, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $catchFrame = $this->dispatchHookedStaticPropertyUnset($lcClass, $propName, $propNameRaw, $storage, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    break;
                case OpCode::TYPE_UNSET:
                    if (null === $op->arg3) {
                        $this->releaseVmStatementDeadTemps($frame, (int) $op->arg2);
                        if (null !== $op->arg2 && isset($frame->scope[$op->arg2])) {
                            $slot = $frame->scope[$op->arg2];
                            $unsetTarget = $slot->resolveIndirect();
                            $globalBinding = $slot->directIndirectTarget();
                            $ownedNamedUnset = null !== $frame->block
                                && $frame->block->isNamedVariableSlot((int) $op->arg2)
                                && (
                                    !$slot->isIndirect()
                                    || (
                                        null !== $globalBinding
                                        && $this->context->isGlobalStorage($globalBinding)
                                    )
                                );
                            if ($ownedNamedUnset) {
                                ObjectLifetime::invokeUnsetDestructor($this, $unsetTarget);
                            }
                            if (null !== $frame->block && $frame->block->isMainScript()) {
                                foreach ($frame->block->eachNamedScopeSlot() as [$globalName, $namedSlot]) {
                                    if ($namedSlot === (int) $op->arg2) {
                                        $this->context->clearGlobalByName($globalName);
                                        break;
                                    }
                                }
                            } elseif (
                                Variable::TYPE_OBJECT === $unsetTarget->type
                                && isset($unsetTarget->object)
                                && $unsetTarget->object->refCount <= 1
                            ) {
                                WeakRefRegistry::clearForObject($unsetTarget->toObject()->id);
                            }
                            // Break the local/reference binding only — never destroy the shared
                            // target (Zend unset on ref; foreach &$v cleanup #4997, #3517).
                            if (
                                null !== $globalBinding
                                && $this->context->isGlobalStorage($globalBinding)
                            ) {
                                $globalBinding->reset();
                                $globalBinding->type = Variable::TYPE_UNDEFINED;
                            }
                            $slot->reset();
                            $slot->type = Variable::TYPE_UNDEFINED;
                        }
                        break;
                    }
                    $containerSlot = $frame->scope[$op->arg2];
                    $container = $containerSlot->resolveIndirect();
                    $key = isset($frame->block->constants[$op->arg3])
                        ? $frame->block->constants[$op->arg3]
                        : $frame->scope[$op->arg3];
                    if (Variable::TYPE_ENUM_CASE === $container->type) {
                        [$propName, $catchFrame] = $this->coerceRuntimeOperandToString($key, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        $catchFrame = $this->enforcePropertyName($propName, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        $enumEntry = $container->toEnumCase()->enumClass;
                        $readonlyMsg = EnumCaseSupport::readonlyPseudoPropertyViolationMessage(
                            $enumEntry,
                            $propName,
                            true
                        );
                        if (null !== $readonlyMsg) {
                            $catchFrame = $this->dispatchVmError($readonlyMsg, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }

                            return self::EXCEPTION;
                        }
                        break;
                    }
                    if (Variable::TYPE_OBJECT === $container->type) {
                        $object = $container->toObject();
                        if (!$op->unsetOnProperty) {
                            // unset($obj[$k]) — ArrayAccess::offsetUnset, else Zend Error
                            // (DOMNodeList/DOMNamedNodeMap have no unset_dimension; #23304).
                            if ($this->objectImplementsArrayAccess($object)) {
                                $catchFrame = $this->invokeArrayAccessOffsetUnset($object, $key, $frame);
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }
                                break;
                            }
                            $catchFrame = $this->dispatchVmError(
                                VM\VmUnset::cannotUseObjectAsArrayMessage($object->class->name),
                                $frame
                            );
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        }
                        if (
                            $op->unsetOnProperty
                            && ext\simplexml\VmSimpleXml::CLASS_LC === strtolower($object->class->name)
                            && ext\simplexml\SimpleXmlRegistry::has($object)
                        ) {
                            [$propName, $catchFrame] = $this->coerceRuntimeOperandToString($key, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            $catchFrame = $this->enforcePropertyName($propName, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            ext\simplexml\VmSimpleXml::unsetChildProperty($object, $propName);
                            break;
                        }
                        [$propName, $catchFrame] = $this->coerceRuntimeOperandToString($key, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        $catchFrame = $this->enforcePropertyName($propName, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        // unset($incomplete->prop) — Error like write (#19632).
                        if (VM\IncompleteClassSupport::isIncomplete($object)) {
                            $catchFrame = $this->dispatchVmError(
                                VM\IncompleteClassSupport::modifyErrorMessage($object),
                                $frame
                            );
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }

                            return self::EXCEPTION;
                        }
                        if (EnumCaseSupport::isEnumCase($object)) {
                            $readonlyMsg = EnumCaseSupport::readonlyPseudoPropertyViolationMessage(
                                $object->class,
                                $propName,
                                true
                            );
                            if (null !== $readonlyMsg) {
                                $catchFrame = $this->dispatchVmError($readonlyMsg, $frame);
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }

                                return self::EXCEPTION;
                            }
                            break;
                        }
                        // Readonly beats asymmetric set-visibility on unset (zend_object_handlers.c, #29273).
                        // PHP 8.4 implicit protected(set) on readonly must not win the Error wording.
                        $catchFrame = $this->enforceReadonlyPropertyUnset($object, $propName, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        // unset() follows set-visibility (zend_object_handlers.c, #23338).
                        $catchFrame = $this->enforceAsymmetricPropertyUnset($object, $propName, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        $catchFrame = $this->enforceVirtualPropertyHookUnset($object, $propName, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        $catchFrame = $this->dispatchHookedInstancePropertyUnset($object, $propName, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    if (Variable::TYPE_ARRAY === $container->type) {
                        $keyResolved = $key->resolveIndirect();
                        if (
                            Variable::TYPE_STRING === $keyResolved->type
                            && null !== $frame->block
                            && $this->isGlobalsSuperglobalUnset($frame, (int) $op->arg2, $keyResolved->toString())
                        ) {
                            $this->context->unsetGlobalsTableKey($keyResolved->toString());
                            break;
                        }
                        try {
                            $container->separateArrayForWrite();
                            $container = $containerSlot->resolveIndirect();
                            $container->toArray()->offsetUnset($key, $frame);
                        } catch (\TypeError $e) {
                            $catchFrame = $this->dispatchVmTypeError($e, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                        }
                        break;
                    }
                    $unsetDimMsg = Variable::TYPE_STRING === $container->type
                        ? 'Cannot unset string offsets'
                        : 'Cannot unset offset in a non-array variable';
                    $catchFrame = $this->dispatchUnsetDimNonContainerError($frame, $unsetDimMsg);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    break;
                case OpCode::TYPE_FROM_CALLABLE:
                    if (isset($frame->scope[$op->arg2])) {
                        $callable = $frame->scope[$op->arg2]->resolveIndirect();
                    } elseif (isset($frame->block->constants[$op->arg2])) {
                        $callable = $frame->block->constants[$op->arg2];
                    } else {
                        throw new \LogicException('TYPE_FROM_CALLABLE missing callable slot');
                    }
                    try {
                        $entry = VM\ClosureSupport::fromCallable(
                            $this->context,
                            $frame,
                            $callable,
                            $op->fromCallableScope,
                            $op->fromCallableApi
                        );
                        $frame->scope[$op->arg1]->object($entry);
                    } catch (\TypeError $e) {
                        // TypeError extends Error — must precede catch (\Error) (#27138).
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    } catch (\Error $e) {
                        $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    }
                    break;
                case OpCode::TYPE_CLOSURE:
                    if (null === $op->block1) {
                        $frame->scope[$op->arg1]->null();
                        break;
                    }
                    $funcName = null !== $op->block1->func
                        ? $op->block1->func->name
                        : '{closure}';
                    $closureFunc = new Func\PHP($funcName, $op->block1);
                    $closureFunc->sourceLocation = $op->sourceLocation;
                    if ([] !== $op->parameterMetadata) {
                        $closureFunc->parameterMetadata = $op->parameterMetadata;
                    }
                    if ([] !== $op->attributeNames) {
                        $closureFunc->attributeNames = $op->attributeNames;
                    }
                    if ([] !== $op->attributeEntries) {
                        $closureFunc->attributeEntries = $op->attributeEntries;
                    }
                    $captures = $this->bindClosureCaptures($frame, $op->closureCaptures);
                    $state = new ClosureState($closureFunc, $captures);
                    $state->applyDefinitionSite($op->sourceLocation, $op->block1);
                    if (
                        null !== $frame->block->func
                        && null !== $frame->block->func->class
                        && null !== $frame->block->func->class->value
                        && '' !== $frame->block->func->class->value
                    ) {
                        // Scope (ce) = declaring class; called_scope (LSB) = creation called class
                        // (#25793, zend_closures.c / zend_object_handlers.c).
                        $declaring = $frame->block->func->class->value;
                        if (null !== $op->block1->func) {
                            $op->block1->func->class = $frame->block->func->class;
                        }
                        $state->boundScopeClass = $declaring;
                        $called = $this->inferCalledClass($frame);
                        if (null !== $called && '' !== $called) {
                            $state->boundCalledScopeClass = $called;
                        }
                        $isStaticClosure = null !== $op->block1->func
                            && (($op->block1->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) !== 0;
                        if (!$isStaticClosure) {
                            $thisVar = $this->resolveCallerThis($frame);
                            if (null !== $thisVar) {
                                $bound = new Variable();
                                $bound->copyFrom($thisVar->resolveIndirect());
                                $state->boundThis = $bound;
                            }
                        }
                    }
                    $frame->scope[$op->arg1]->object($state->wrapObject($this->context));
                    break;
                case OpCode::TYPE_RETURN_VOID:
                    $frame->returnSiteLine = (int) ($op->arg1 ?? 0);
                    // Explicit `return;` in a distinct finally body overrides pending try return
                    // and suppresses a pending exception (#25239). Fused empty-finally epilogues
                    // share the merge block and must keep exception unwind (#24728).
                    if ($this->frameIsInDistinctFinallyBody($frame) && null !== $op->arg1) {
                        if ($this->applyReturnInsideFinally($frame, null, true)) {
                            goto restart;
                        }
                        goto return_void_complete;
                    }
                    $finallyFrame = $this->beginReturnFinallyUnwind($frame, null, true);
                    if (null !== $finallyFrame) {
                        $frame = $finallyFrame;
                        goto restart;
                    }
                    // Empty finally may fuse with merge and end in RETURN_VOID instead of JUMP (#15738).
                    if ($this->completeActiveFinallyUnwind($frame)) {
                        goto restart;
                    }
                    goto return_void_complete;
                case OpCode::TYPE_RETURN:
                    $frame->returnSiteLine = (int) ($op->arg2 ?? 0);
                    if (null !== $op->arg1 && isset($frame->scope[$op->arg1])) {
                        $catchFrame = $this->guardUnboundThisRead($frame, (int) $op->arg1);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    }
                    $returnValue = $this->resolveVmReturnValue($frame, $op);
                    // Explicit return inside a real finally body (finally block != merge) overrides
                    // pending try return / pending exception (#25239). Fused empty finally shares
                    // the merge block and must keep exception unwind (#24728).
                    if ($this->frameIsInDistinctFinallyBody($frame)) {
                        if ($this->applyReturnInsideFinally($frame, $returnValue, false)) {
                            goto restart;
                        }
                        goto return_value_complete;
                    }
                    // Empty finally may fuse with merge and end in TYPE_RETURN instead of JUMP (#24728).
                    // Check exception-unwind completion BEFORE beginReturnFinallyUnwind so the
                    // pending exception propagates to the outer catch instead of being swallowed
                    // by a spurious return-finally chain.
                    if (null !== $this->context->pendingException && $this->completeActiveFinallyUnwind($frame)) {
                        goto restart;
                    }
                    $finallyFrame = $this->beginReturnFinallyUnwind($frame, $returnValue, false);
                    if (null !== $finallyFrame) {
                        $frame = $finallyFrame;
                        goto restart;
                    }
                    if ($this->completeActiveFinallyUnwind($frame)) {
                        goto restart;
                    }
                    goto return_value_complete;
                case OpCode::TYPE_FUNCDEF:
                    VM\RedundantTrueFalseUnionCheck::assertFunctionBlock(
                        $op->block1,
                        $frame,
                        $op->sourceLocation
                    );
                    VM\RedundantIterableUnionCheck::assertFunctionBlock(
                        $op->block1,
                        $frame,
                        $op->sourceLocation
                    );
                    $name = $frame->scope[$op->arg1]->toString();
                    $lcname = strtolower($name);
                    if (isset($this->context->functions[$lcname])) {
                        throw new \LogicException("Duplicate function definition for $lcname()");
                    }
                    $func = new Func\PHP($name, $op->block1);
                    $func->sourceLocation = $op->sourceLocation;
                    $func->deprecated = $op->deprecatedMetadata;
                    if ([] !== $op->parameterMetadata) {
                        $func->parameterMetadata = $op->parameterMetadata;
                    }
                    if ([] !== $op->attributeNames) {
                        $func->attributeNames = $op->attributeNames;
                    }
                    if ([] !== $op->attributeEntries) {
                        $func->attributeEntries = $op->attributeEntries;
                    }
                    $this->context->declareFunction($func);
                    break;
                case OpCode::TYPE_FUNCCALL_INIT:
                    $callee = $frame->scope[$op->arg1]->resolveIndirect();
                    if (Variable::TYPE_NULL === $callee->type) {
                        $catchFrame = $this->dispatchVmError(
                            'Value of type null is not callable',
                            $frame
                        );
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }

                        return self::EXCEPTION;
                    }
                    if (Variable::TYPE_INTEGER === $callee->type
                        || Variable::TYPE_FLOAT === $callee->type
                        || Variable::TYPE_BOOLEAN === $callee->type) {
                        $catchFrame = $this->dispatchVmError(
                            VM\CallableCheck::scalarNotCallableMessage($callee),
                            $frame
                        );
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }

                        return self::EXCEPTION;
                    }
                    if (Variable::TYPE_OBJECT === $callee->type) {
                        $closureState = $callee->toObject()->closureState;
                        if (null !== $closureState) {
                            $this->initClosureCall($frame, $closureState);
                            $frame->closureCallableSlot = $op->arg1;
                            break;
                        }
                        if (!$this->hasInstanceMethod($callee->toObject()->class, '__invoke')) {
                            $catchFrame = $this->dispatchVmError(
                                VM\CallableCheck::objectNotCallableMessage($callee),
                                $frame
                            );
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }

                            return self::EXCEPTION;
                        }
                        $catchFrame = $this->initMethodCall($frame, $callee, '__invoke', true);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    if (Variable::TYPE_ENUM_CASE === $callee->type) {
                        $receiver = VM\EnumCaseSupport::receiverForInstanceMethod($callee);
                        if (!$this->hasInstanceMethod($receiver->toObject()->class, '__invoke')) {
                            $catchFrame = $this->dispatchVmError(
                                VM\CallableCheck::objectNotCallableMessage($callee),
                                $frame
                            );
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }

                            return self::EXCEPTION;
                        }
                        $catchFrame = $this->initMethodCall($frame, $receiver, '__invoke', true);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    if (Variable::TYPE_ARRAY === $callee->type) {
                        $catchFrame = $this->initArrayCallable($frame, $callee);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    $name = $callee->toString();
                    if (str_contains($name, '::')) {
                        try {
                            // Dynamic "$c()" / array callables do not resolve parent/self/static
                            // as scope keywords — Zend Errors with Class "parent" not found (#25625).
                            $this->initStaticCallable($frame, $name, false, false, false);
                        } catch (\Error $e) {
                            $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            return self::EXCEPTION;
                        } catch (\LogicException $e) {
                            $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            return self::EXCEPTION;
                        }
                        break;
                    }
                    $lcname = $this->context->resolveFunctionCallLc($name);
                    if (null === $lcname) {
                        // Zend preserves source spelling (FCC / $fn(), zend_execute_API.c) (#26690).
                        $catchFrame = $this->dispatchVmError(
                            'Call to undefined function '.$name.'()',
                            $frame
                        );
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }

                        return self::EXCEPTION;
                    }
                    // ZEND_ACC_FORBIDDEN_WHEN_DYNAMIC — variable/$fn() calls only (#23591).
                    if (
                        $op->funcCallDynamic
                        && VM\VariableFunctionCall::isForbiddenWhenDynamic($lcname)
                    ) {
                        $catchFrame = $this->dispatchVmError(
                            VM\VariableFunctionCall::forbiddenWhenDynamicMessage($lcname),
                            $frame
                        );
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }

                        return self::EXCEPTION;
                    }
                    $this->savePendingOutboundCallForInlineNew($frame);
                    $frame->call = $this->context->functions[$lcname];
                    $frame->callArgs = [];
                    $frame->callArgEntries = [];
                    // Drop leftover Class::__construct from a prior `new` (#10009).
                    $frame->builtinCalleeQualifiedMethod = null;
                    break;
                case OpCode::TYPE_METHODCALL_INIT:
                    $catchFrame = $this->guardUnboundThisRead($frame, (int) $op->arg1);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $receiver = $frame->scope[$op->arg1]->resolveIndirect();
                    $methodName = $frame->scope[$op->arg2]->toString();
                    if (Variable::TYPE_OBJECT !== $receiver->type
                        && Variable::TYPE_ENUM_CASE !== $receiver->type) {
                        if (Variable::TYPE_NULL === $receiver->type
                            && '__invoke' === strtolower($methodName)) {
                            $catchFrame = $this->dispatchVmError(
                                'Value of type null is not callable',
                                $frame
                            );
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }

                            return self::EXCEPTION;
                        }
                        $catchFrame = $this->dispatchVmError(
                            sprintf(
                                'Call to a member function %s() on %s',
                                $methodName,
                                $this->valueDebugTypeLabel($receiver)
                            ),
                            $frame
                        );
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }

                        return self::EXCEPTION;
                    }
                    $receiver = VM\EnumCaseSupport::receiverForInstanceMethod($receiver);
                    $catchFrame = $this->initMethodCall(
                        $frame,
                        $receiver,
                        $methodName,
                        $op->objectCallInvoke
                    );
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    if (
                        '__invoke' === strtolower($methodName)
                        && null !== $receiver->toObject()->closureState
                    ) {
                        $frame->closureCallableSlot = $op->arg1;
                    }
                    break;
                case OpCode::TYPE_ARG_SEND:
                    $catchFrame = $this->guardUnboundThisRead($frame, (int) $op->arg1);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $argSlot = (int) $op->arg1;
                    // Implicit $this / new() prefix occupies low call-arg indices (#6739, #11844).
                    $argIndex = \count($frame->callArgs) + \count($frame->callArgEntries);
                    $value = $this->resolveOutgoingCallArgValue($frame, $argSlot);
                    // Named sends use definition-order param index for ZEND_SEND_REF (count: $n skips limit, #19697).
                    if (
                        null !== $op->arg2
                        && null === $op->arg3
                        && isset($frame->block->constants[$op->arg2])
                        && $frame->call instanceof Func\Internal
                    ) {
                        $namedParam = $frame->block->constants[$op->arg2]->toString();
                        $calleeName = $frame->builtinCalleeQualifiedMethod ?? $frame->call->getName();
                        $paramNames = BuiltinParamNames::paramNamesForInternalFunction($calleeName) ?? [];
                        $namedIdx = BuiltinParamNames::lookupNamedParamIndex(
                            $paramNames,
                            $namedParam,
                            $calleeName
                        );
                        if (false !== $namedIdx) {
                            $argIndex = \count($frame->callArgs) + $namedIdx;
                        }
                    }
                    $needsRef = $this->outgoingCallArgNeedsReference($frame, $argIndex, $value);
                    if (!$needsRef) {
                        $this->warnUndefinedVariableForScopeRead($frame, $argSlot);
                    }
                    if (
                        !$needsRef
                        && $this->isUnboundLocalScopeRead($frame, $argSlot)
                    ) {
                        $resolved = $value->resolveIndirect();
                        if ($resolved->isUndefined()) {
                            $sent = new Variable();
                            $sent->null();
                            $value = $sent;
                        }
                    } elseif ($needsRef && $this->isUnboundLocalScopeRead($frame, $argSlot)) {
                        // Zend creates CV on ZEND_SEND_REF; no E_WARNING on later reads (#10403).
                        $this->markScopeSlotInitialized($frame, $argSlot);
                    }
                    if (!$needsRef) {
                        $snapshot = new Variable();
                        if ($value->isIndirect()) {
                            // CV/indirect send-by-value must not share cells with the snapshot (#16331).
                            $snapshot->copyFrom($value->resolveIndirect());
                        } else {
                            $snapshot->duplicateFrom($value);
                        }
                        $value = $snapshot;
                    }
                    if (null !== $op->arg3) {
                        $frame->callArgEntries[] = ['u', $value, $needsRef ? null : $argSlot];
                        break;
                    }
                    if (null !== $op->arg2 && isset($frame->block->constants[$op->arg2])) {
                        $frame->callArgEntries[] = [
                            'n',
                            $frame->block->constants[$op->arg2]->toString(),
                            $value,
                            $needsRef ? null : $argSlot,
                        ];
                    } else {
                        $frame->callArgEntries[] = ['p', $value, $needsRef ? null : $argSlot];
                    }
                    break;
                case OpCode::TYPE_FUNCCALL_EXEC_RETURN:
                case OpCode::TYPE_FUNCCALL_EXEC_NORETURN:
                    if (is_null($frame->call)) {
                        // Used for null constructors, etc
                        $this->markPendingNewObjectConstructed($frame);
                        if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type && is_int($op->arg1)) {
                            $this->markScopeSlotInitialized($frame, (int) $op->arg1);
                        }
                        $frame->callArgs = [];
                        $frame->callArgEntries = [];
                        // Null ctor stub: drop Class::__construct so later builtins use real names (#10009).
                        if (null === $frame->pendingOutboundCallRestore) {
                            $frame->builtinCalleeQualifiedMethod = null;
                        }
                        $this->restorePendingOutboundCallAfterInlineNew($frame);
                        break;
                    }
                    $frame->callSiteLine = OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type
                        ? (int) ($op->arg2 ?? 0)
                        : (int) ($op->arg1 ?? 0);
                    $this->emitCallDeprecationNotice($frame);
                    $this->emitCallNoDiscardNotice($frame, $op);
                    if ($frame->call instanceof Func\PHP && $frame->call->block->isGenerator) {
                        try {
                            $calledArgs = $this->resolveOutgoingCallArgs($frame);
                            ReferencableCheck::assertOutgoingCallArgs($frame->call, $frame, $calledArgs);
                        } catch (\ArgumentCountError $e) {
                            $catchFrame = $this->dispatchVmArgumentCountError($e, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        } catch (\TypeError $e) {
                            $catchFrame = $this->dispatchVmTypeError($e, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        } catch (\Error $e) {
                            $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        } catch (\LogicException $e) {
                            return $this->raise($e->getMessage(), $frame);
                        }
                        $closureState = $this->resolvePendingClosureState($frame);
                        $state = new GeneratorState($this, $frame->call, $calledArgs);
                        if (
                            null !== $closureState
                            && $frame->call instanceof Func\PHP
                            && $frame->call === $closureState->func
                        ) {
                            $state->closureCall = $closureState;
                        }
                        if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                            $this->scopeSlot($frame, (int) $op->arg1)->object($state->wrapObject());
                        }
                        $frame->call = null;
                        $this->clearOutgoingCallState($frame);
                        break;
                    }
                    try {
                        $calledArgs = $this->resolveOutgoingCallArgs($frame);
                        ReferencableCheck::assertOutgoingCallArgs($frame->call, $frame, $calledArgs);
                        // Zend strict_types is a *caller* (call-site) rule; standalone literal types
                        // (`true`/`false`/`null`) always exact-match (issue #7057).
                        if (
                            $frame->call instanceof Func\PHP
                            && [] !== $calledArgs
                        ) {
                            $callSiteLine = OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type
                                ? (int) ($op->arg2 ?? 0)
                                : (int) ($op->arg1 ?? 0);
                            $calleeBlock = $frame->call->block;
                            $callerStrict = $frame->block->strictTypes;
                            $thisArgOffset = 0;
                            if (
                                null !== $calleeBlock->func
                                && null !== $calleeBlock->func->class
                                && !(($calleeBlock->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC)
                                && !(($calleeBlock->func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE)
                            ) {
                                $thisArgOffset = 1;
                            }
                            foreach ($calleeBlock->opCodes as $recv) {
                                if (OpCode::TYPE_ARG_RECV !== $recv->type) {
                                    continue;
                                }
                                $paramIdx = (int) $recv->arg2;
                                $argIndex = $paramIdx + $thisArgOffset;
                                if (!array_key_exists($argIndex, $calledArgs)) {
                                    continue;
                                }
                                $slot = (int) $recv->arg1;
                                if (
                                    !$callerStrict
                                    && !$calleeBlock->paramRequiresExactLiteralMatch($slot)
                                ) {
                                    continue;
                                }
                                $arg = $calledArgs[$argIndex];
                                if (
                                    TypeCheck::skipParameterTypeCheckForImplicitNullable(
                                        $calleeBlock,
                                        $slot,
                                        $arg
                                    )
                                ) {
                                    continue;
                                }
                                if (isset($calleeBlock->paramNeverSlots[$slot])) {
                                    $paramName = $calleeBlock->paramNames[$paramIdx] ?? 'param'.$paramIdx;
                                    throw VM\ParamTypeError::forUserCallWithExpectedType(
                                        SourcePreprocessor\PropertyHooks::zendTypeErrorCallableName(
                                            $frame->call->getName()
                                        ),
                                        $paramIdx,
                                        $paramName,
                                        'never',
                                        $arg,
                                        $frame->scriptPath,
                                        $callSiteLine
                                    );
                                }
                                if (isset($calleeBlock->paramIterableSlots[$slot])) {
                                    if (!IterableCheck::isIterable($arg, $this->context)) {
                                        $paramName = $calleeBlock->paramNames[$paramIdx] ?? 'param'.$paramIdx;
                                        throw VM\ParamTypeError::forUserCallWithExpectedType(
                                            SourcePreprocessor\PropertyHooks::zendTypeErrorCallableName(
                                                $frame->call->getName()
                                            ),
                                            $paramIdx,
                                            $paramName,
                                            IterableCheck::TYPE_LABEL,
                                            $arg,
                                            $frame->scriptPath,
                                            $callSiteLine
                                        );
                                    }
                                    continue;
                                }
                                if (isset($calleeBlock->paramCallableSlots[$slot])) {
                                    if (!CallableCheck::isCallable($arg, $this->context, $frame)) {
                                        $paramName = $calleeBlock->paramNames[$paramIdx] ?? 'param'.$paramIdx;
                                        throw VM\ParamTypeError::forUserCallWithExpectedType(
                                            SourcePreprocessor\PropertyHooks::zendTypeErrorCallableName(
                                                $frame->call->getName()
                                            ),
                                            $paramIdx,
                                            $paramName,
                                            CallableCheck::TYPE_LABEL,
                                            $arg,
                                            $frame->scriptPath,
                                            $callSiteLine
                                        );
                                    }
                                    continue;
                                }
                                if (isset($calleeBlock->paramIntersectionConstraints[$slot])) {
                                    $paramName = $calleeBlock->paramNames[$paramIdx] ?? 'param'.$paramIdx;
                                    $expected = $calleeBlock->paramIntersectionDisplayLabels[$slot]
                                        ?? implode('&', $calleeBlock->paramIntersectionConstraints[$slot]);
                                    try {
                                        TypeCheck::assertParamIntersection(
                                            $arg,
                                            $calleeBlock->paramIntersectionConstraints[$slot],
                                            $this->context,
                                            $expected
                                        );
                                    } catch (\TypeError $e) {
                                        throw VM\ParamTypeError::forUserCallWithExpectedType(
                                            SourcePreprocessor\PropertyHooks::zendTypeErrorCallableName(
                                                $frame->call->getName()
                                            ),
                                            $paramIdx,
                                            $paramName,
                                            $expected,
                                            $arg,
                                            $frame->scriptPath,
                                            $callSiteLine
                                        );
                                    }
                                    continue;
                                }
                                $constraint = $calleeBlock->paramTypeConstraints[$slot] ?? null;
                                if (null === $constraint) {
                                    continue;
                                }
                                $literalBool = $calleeBlock->paramLiteralBoolTypes[$slot] ?? null;
                                if (!TypeCheck::parameterMatchesType($arg, $constraint, $literalBool)) {
                                    $paramName = $calleeBlock->paramNames[$paramIdx] ?? 'param'.$paramIdx;
                                    throw VM\ParamTypeError::forUserCall(
                                        SourcePreprocessor\PropertyHooks::zendTypeErrorCallableName(
                                            $frame->call->getName()
                                        ),
                                        $paramIdx,
                                        $paramName,
                                        $constraint,
                                        $arg,
                                        $frame->scriptPath,
                                        $callSiteLine,
                                        $literalBool
                                    );
                                }
                            }
                        }
                    } catch (\ArgumentCountError $e) {
                        $catchFrame = $this->dispatchVmArgumentCountError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    } catch (\TypeError $e) {
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    } catch (\Error $e) {
                        $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    } catch (\LogicException $e) {
                        return $this->raise($e->getMessage(), $frame);
                    }
                    $new = $frame->call->getFrame(
                        $this->context,
                        $frame
                    );
                    $closureState = $this->resolvePendingClosureState($frame);
                    $frame->closureCallableSlot = null;
                    $ownClosureState = $frame->closureCall;
                    $preserveOwnClosureCall = null !== $ownClosureState
                        && null !== $frame->block->func
                        && (($frame->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE) !== 0;
                    if (!$preserveOwnClosureCall) {
                        $frame->closureCall = null;
                    }
                    $frame->pendingClosureInvoke = null;
                    // Only bind captures/$this/called-scope when entering the closure body, not nested
                    // $this->method() (#4927). wrappedFunc is the fromCallable/FCC static-method target
                    // (stub $closureState->func differs) — still apply boundScopeClass for LSB (#24431).
                    if (
                        null !== $closureState
                        && (
                            ($frame->call instanceof Func\PHP && $frame->call === $closureState->func)
                            || (null !== $closureState->wrappedFunc && $frame->call === $closureState->wrappedFunc)
                        )
                    ) {
                        $this->applyClosureBinding($new, $closureState);
                    }
                    if (null === $new->calledClass || '' === $new->calledClass) {
                        $new->calledClass = $this->inferCalledClass($frame);
                    }
                    $new->returnVar = null;
                    if ($op->type === OpCode::TYPE_FUNCCALL_EXEC_RETURN) {
                        $new->returnVar = $this->scopeSlot($frame, (int) $op->arg1);
                    } else {
                        $new->returnVar = null;
                    }
                    $new->calledArgs = $calledArgs;
                    if ($new->hasHandler()) {
                        $new->parent = $frame;
                        $new->vmContext = $this->context;
                        $catchFrame = $this->executeInternalHandler($new, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        if ($frame->fiberSuspend) {
                            $frame->fiberSuspend = false;
                            // Pos already advanced past FUNCCALL_EXEC_*; stale callArgEntries
                            // would replay the prior suspend operand on the next resume (#18162).
                            $frame->call = null;
                            $this->clearOutgoingCallState($frame);
                            $this->restorePendingOutboundCallAfterInlineNew($frame);

                            return self::FIBER_SUSPEND;
                        }
                        $frame->call = null;
                        $keepReturnSlot = OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type
                            ? (int) $op->arg1
                            : null;
                        $this->clearOutgoingCallState($frame, $keepReturnSlot);
                        $this->restorePendingOutboundCallAfterInlineNew($frame);
                        if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                            $this->releaseVmStatementDeadTemps($frame, (int) $op->arg1);
                        }
                        break;
                    }
                    $catchFrame = $this->guardFiberStackBeforeCall($frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $this->context->push($frame);
                    $frame = $new;
                    goto restart;
                case OpCode::TYPE_ARG_RECV:
                    $arg1 = $frame->scope[$op->arg1];
                    $recvIdx = $op->arg2;
                    if (
                        null !== $frame->block->func
                        && null !== $frame->block->func->class
                        && !(($frame->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC)
                        && !(($frame->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE)
                    ) {
                        ++$recvIdx;
                    }
                    $isVariadicSlot = null !== $frame->block->variadicParamIndex
                        && $frame->block->variadicParamIndex === (int) $op->arg2;
                    if ($isVariadicSlot) {
                        $variadicSlot = (int) $op->arg1;
                        $variadicParamIdx = (int) $op->arg2;
                        $paramCount = count($frame->block->paramNames);
                        $strict = null !== $frame->parent
                            ? $frame->parent->block->strictTypes
                            : $frame->block->strictTypes;
                        $maxArgIdx = -1;
                        foreach (array_keys($frame->calledArgs) as $argKey) {
                            if ($argKey > $maxArgIdx) {
                                $maxArgIdx = $argKey;
                            }
                        }
                        $hasTrailingFixedAfterVariadic = $variadicParamIdx < $paramCount - 1;
                        if ($hasTrailingFixedAfterVariadic) {
                            $trailingCount = $paramCount - $variadicParamIdx - 1;
                            $numProvided = $maxArgIdx + 1;
                            $numToTrailing = min(
                                $trailingCount,
                                max(0, $numProvided - $variadicParamIdx - 1)
                            );
                            $variadicEndIdx = $numProvided - $numToTrailing - 1;
                        } else {
                            $variadicEndIdx = $maxArgIdx;
                        }
                        try {
                            $variadicArgCount = 0;
                            for ($i = $recvIdx; $i <= $variadicEndIdx; ++$i) {
                                if (array_key_exists($i, $frame->calledArgs)) {
                                    ++$variadicArgCount;
                                }
                            }
                            $needsElementChecks = TypeCheck::variadicSlotNeedsElementChecks(
                                $frame->block,
                                $variadicSlot
                            );
                            $namedVariadicPack = null;
                            $untypedNamedPassthrough = null;
                            if (
                                1 === $variadicArgCount
                                && array_key_exists($recvIdx, $frame->calledArgs)
                            ) {
                                $sole = $frame->calledArgs[$recvIdx]->resolveIndirect();
                                if (Variable::TYPE_ARRAY === $sole->type) {
                                    if ($sole->namedVariadicPack) {
                                        $namedVariadicPack = $sole;
                                    } elseif (
                                        !$needsElementChecks
                                        && !$sole->toArray()->isPackedList()
                                    ) {
                                        $untypedNamedPassthrough = $sole;
                                    }
                                }
                            }
                            if ($needsElementChecks) {
                                $trailing = [];
                                $trailingArgIndexes = [];
                                if (null !== $namedVariadicPack) {
                                    $packOffset = 0;
                                    foreach ($namedVariadicPack->toArray()->iterate(true) as $value) {
                                        $trailing[] = $value;
                                        // Zend Argument #N is call-site order; named packs start at the variadic slot (#19695).
                                        $trailingArgIndexes[] = $variadicParamIdx + $packOffset;
                                        ++$packOffset;
                                    }
                                } else {
                                    for ($i = $recvIdx; $i <= $variadicEndIdx; ++$i) {
                                        if (array_key_exists($i, $frame->calledArgs)) {
                                            $trailing[] = $frame->calledArgs[$i];
                                            $trailingArgIndexes[] = $i;
                                        }
                                    }
                                }
                                $vmContext = $this->context;
                                TypeCheck::withParamErrorContext(
                                    \PHPCompiler\VM\UserParamErrorContext::forRecvFrame($frame, $variadicParamIdx, true),
                                    static function () use (
                                        $trailing,
                                        $trailingArgIndexes,
                                        $strict,
                                        $frame,
                                        $variadicSlot,
                                        $vmContext
                                    ): void {
                                        TypeCheck::verifyVariadicElements(
                                            $trailing,
                                            $strict,
                                            $frame->block->paramVariadicElementTypeConstraints[$variadicSlot] ?? null,
                                            $frame->block->paramVariadicElementGenericArrayTypeSpecs[$variadicSlot] ?? null,
                                            $frame->block->paramVariadicElementIntersectionConstraints[$variadicSlot] ?? null,
                                            $frame->block->paramVariadicElementDnfConstraints[$variadicSlot] ?? null,
                                            $vmContext,
                                            isset($frame->block->paramIterableSlots[$variadicSlot]),
                                            isset($frame->block->paramNeverSlots[$variadicSlot]),
                                            $frame->block->paramVariadicElementIntersectionDisplayLabels[$variadicSlot] ?? null,
                                            $trailingArgIndexes
                                        );
                                    }
                                );
                            }
                            if (null !== $namedVariadicPack || null !== $untypedNamedPassthrough) {
                                $arg1->copyFrom($namedVariadicPack ?? $untypedNamedPassthrough);
                                $this->markScopeSlotInitialized($frame, (int) $op->arg1);
                                break;
                            }
                            $arg1->newArray();
                            $packed = $arg1->toArray();
                            $variadicByRef = isset($frame->block->paramByRef[$variadicParamIdx]);
                            for ($i = $recvIdx; $i <= $variadicEndIdx; ++$i) {
                                if (!array_key_exists($i, $frame->calledArgs)) {
                                    continue;
                                }
                                $copy = new Variable();
                                if ($variadicByRef) {
                                    $src = $frame->calledArgs[$i];
                                    if ($copy !== $src) {
                                        $copy->indirect($src);
                                    } else {
                                        $copy->copyFrom($src);
                                    }
                                } else {
                                    $copy->copyFrom($frame->calledArgs[$i]);
                                }
                                $packed->append($copy);
                            }
                        } catch (\TypeError $e) {
                            $catchFrame = $this->dispatchVmTypeError($e, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                        }
                        $this->markScopeSlotInitialized($frame, (int) $op->arg1);
                        break;
                    }
                    if (array_key_exists($recvIdx, $frame->calledArgs)) {
                        if (isset($frame->block->paramByRef[(int) $op->arg2])) {
                            $src = $frame->calledArgs[$recvIdx];
                            // Avoid self-indirect when callee param slot aliases the argument (#5023).
                            if ($arg1 !== $src) {
                                $arg1->indirect($src);
                            }
                        } else {
                            $arg1->copyFrom($frame->calledArgs[$recvIdx]);
                        }
                    } elseif (
                        (
                            (null !== $op->arg3 && isset($frame->block->constants[$op->arg3]))
                            || isset($frame->block->paramRuntimeDefaultInitBlocks[(int) $op->arg2])
                        )
                        && VM\ParamArgumentCountError::parameterIsEffectivelyRequired(
                            $frame->block,
                            (int) $op->arg2
                        )
                    ) {
                        // Optional-before-required: do not apply the syntactic default (#25728).
                        // Named hole (later arg present) → "Argument #N ($name) not passed";
                        // otherwise Zend too-few wording.
                        $error = VM\ParamArgumentCountError::calledArgsHaveIndexAbove(
                            $frame->calledArgs,
                            $recvIdx
                        )
                            ? VM\ParamArgumentCountError::forNamedArgNotPassed($frame, (int) $op->arg2)
                            : VM\ParamArgumentCountError::forTooFewAtReceive($frame, (int) $op->arg2);
                        $catchFrame = $this->dispatchVmArgumentCountError($error, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    } elseif (null !== $op->arg3 && isset($frame->block->constants[$op->arg3])) {
                        $default = $frame->block->constants[$op->arg3];
                        if (VM\EnumCaseSupport::isEnumCaseVariable($default)) {
                            $arg1->copyFrom(
                                VM\EnumCaseSupport::materializeConstantValue($this->context, $default)
                            );
                        } else {
                            $arg1->copyFrom($default);
                        }
                    } elseif (isset($frame->block->paramRuntimeDefaultInitBlocks[(int) $op->arg2])) {
                        $paramIdx = (int) $op->arg2;
                        $initBlock = $frame->block->paramRuntimeDefaultInitBlocks[$paramIdx];
                        $resultSlot = $frame->block->paramRuntimeDefaultResultSlots[$paramIdx]
                            ?? throw new \LogicException('Missing runtime parameter default result slot');
                        $value = $this->executePropertyDefaultInitBlock($initBlock, $resultSlot);
                        $arg1->copyFrom($value);
                    } else {
                        // Named/unpack omission of a required param (no default): Zend uses
                        // "Argument #N ($name) not passed" when a later slot was supplied (#29095).
                        $error = VM\ParamArgumentCountError::calledArgsHaveIndexAbove(
                            $frame->calledArgs,
                            $recvIdx
                        )
                            ? VM\ParamArgumentCountError::forNamedArgNotPassed($frame, (int) $op->arg2)
                            : VM\ParamArgumentCountError::forTooFewAtReceive($frame, (int) $op->arg2);
                        $catchFrame = $this->dispatchVmArgumentCountError($error, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    $strict = null !== $frame->parent
                        ? $frame->parent->block->strictTypes
                        : $frame->block->strictTypes;
                    $arraySpec = $frame->block->paramGenericArrayTypeSpecs[$op->arg1] ?? null;
                    $paramIdx = (int) $op->arg2;
                    $vmContext = $this->context;
                    try {
                        TypeCheck::withParamErrorContext(
                            \PHPCompiler\VM\UserParamErrorContext::forRecvFrame($frame, $paramIdx),
                            function () use ($frame, $op, $arg1, $strict, $arraySpec, $vmContext): void {
                                if (
                                    !TypeCheck::skipParameterTypeCheckForImplicitNullable(
                                        $frame->block,
                                        (int) $op->arg1,
                                        $arg1
                                    )
                                ) {
                                    if (isset($frame->block->paramNeverSlots[$op->arg1])) {
                                        TypeCheck::assertNeverParameter($arg1);
                                    } elseif (isset($frame->block->paramIterableSlots[$op->arg1])) {
                                        IterableCheck::assertParameter($arg1, $vmContext);
                                    } elseif (isset($frame->block->paramCallableSlots[$op->arg1])) {
                                        CallableCheck::assertParameter($arg1, $vmContext, $frame);
                                    } elseif (isset($frame->block->paramDnfConstraints[$op->arg1])) {
                                        DnfCheck::assertMatches(
                                            $arg1,
                                            $frame->block->paramDnfConstraints[$op->arg1],
                                            $vmContext,
                                            'Argument',
                                            null,
                                            $strict
                                        );
                                    } elseif (isset($frame->block->paramIntersectionConstraints[$op->arg1])) {
                                        TypeCheck::assertParamIntersection(
                                            $arg1,
                                            $frame->block->paramIntersectionConstraints[$op->arg1],
                                            $vmContext,
                                            $frame->block->paramIntersectionDisplayLabels[$op->arg1] ?? null
                                        );
                                    } else {
                                        TypeCheck::coerceParameter($arg1, $strict, $arraySpec);
                                    }
                                }
                            }
                        );
                    } catch (\TypeError $e) {
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        if (null !== $catchFrame) {
                            if (null !== $frame->propertyHookRawProperty) {
                                $this->context->propertyHookExternalCatchFrame = $catchFrame;
                                $this->context->propertyHookSetAborted = true;

                                return self::FAILURE;
                            }
                            $frame = $catchFrame;
                            goto restart;
                        }
                    }
                    $this->markScopeSlotInitialized($frame, (int) $op->arg1);
                    break;
                case OpCode::TYPE_DECLARE_INTERFACE:
                    $name = $frame->scope[$op->arg1]->toString();
                    $lcname = strtolower($name);
                    if (isset($this->context->classes[$lcname])) {
                        throw new \LogicException("Duplicate interface definition for $name");
                    }
                    $ifaceEntry = new VM\ClassEntry($name);
                    $ifaceEntry->isInterface = true;
                    \PHPCompiler\ext\standard\VmReflection::markCompilerBootstrapClassInternal($ifaceEntry);
                    $ifaceEntry->interfaces = $op->classImplements;
                    $ifaceEntry->attributeNames = $op->attributeNames;
                    $ifaceEntry->attributeEntries = $op->attributeEntries;
                    $ifaceEntry->classDeprecated = $op->deprecatedMetadata;
                    if ($op->isSealed) {
                        $ifaceEntry->sealed = true;
                        $ifaceEntry->sealedPermits = $this->normalizeSealedPermits($name, $op->sealedPermits);
                    }
                    if (null !== $op->block1) {
                        self::defineClass($ifaceEntry, $op->block1, $frame);
                    }
                    try {
                        $this->inheritFromInterfaces($ifaceEntry);
                    } catch (\CompileError $e) {
                        // Ambiguous iface constants on `interface K extends I, J` (#26672).
                        if (VmEval::EVAL_FILENAME === $frame->scriptPath
                            || str_ends_with((string) $frame->scriptPath, VmEval::EVAL_FILENAME)
                        ) {
                            throw $e;
                        }
                        $this->raiseClassDeclareCompileFatal($e, $frame);
                    }
                    $this->context->classes[$lcname] = $ifaceEntry;
                    $this->propagateInterfaceConstantsToImplementors($lcname);
                    $this->flushDeferredTraitUses($frame);
                    $this->flushDeferredClassConstants();
                    break;
                case OpCode::TYPE_DECLARE_TRAIT:
                    $name = $frame->scope[$op->arg1]->toString();
                    $lcname = strtolower($name);
                    if (isset($this->context->classes[$lcname])) {
                        throw new \LogicException("Duplicate trait definition for $name");
                    }
                    $traitEntry = new ClassEntry($name);
                    $traitEntry->isTrait = true;
                    \PHPCompiler\ext\standard\VmReflection::markCompilerBootstrapClassInternal($traitEntry);
                    $traitEntry->attributeNames = $op->attributeNames;
                    $traitEntry->attributeEntries = $op->attributeEntries;
                    $traitEntry->classDeprecated = $op->deprecatedMetadata;
                    self::defineClass($traitEntry, $op->block1, $frame);
                    $this->context->classes[$lcname] = $traitEntry;
                    $this->flushDeferredTraitUses($frame);
                    $this->flushDeferredClassConstants();
                    break;
                case OpCode::TYPE_DECLARE_GLOBAL_CONST:
                    $name = $frame->scope[$op->arg1]->toString();
                    if (isset($frame->block->constants[$op->arg2])) {
                        $constValue = new Variable();
                        $constValue->copyFrom($frame->block->constants[$op->arg2]);
                    } elseif (isset($frame->scope[$op->arg2])) {
                        $constValue = new Variable();
                        $constValue->copyFrom($frame->scope[$op->arg2]);
                    } else {
                        throw new \LogicException('Global constant value must be a compile-time constant');
                    }
                    $constValue = VM\EnumCaseSupport::materializeConstantValue($this->context, $constValue);
                    $constFilename = '' !== $frame->scriptPath ? $frame->scriptPath : 'Command line code';
                    if (!$this->context->defineConstant($name, $constValue, false, $constFilename)) {
                        $line = (int) ($op->globalConstStartLine ?? 0);
                        $this->context->errors->triggerError(
                            "Constant {$name} already defined",
                            VM\ErrorReporter::E_WARNING,
                            '' !== $frame->scriptPath ? $frame->scriptPath : null,
                            $this->context,
                            $frame,
                            $line > 0 ? $line : 0
                        );
                    }
                    if (null !== $op->deprecatedMetadata) {
                        $this->context->globalConstDeprecated[strtolower($name)] = $op->deprecatedMetadata;
                    }
                    // PHP 8.5+ attributes on file/namespace constants (#23882).
                    if ([] !== $op->attributeEntries) {
                        $this->context->globalConstAttributeEntries[strtolower($name)] = $op->attributeEntries;
                    } elseif ([] !== $op->attributeNames) {
                        $entries = [];
                        foreach ($op->attributeNames as $attrName) {
                            $entries[] = new \PHPCompiler\Compiler\AttributeEntry((string) $attrName);
                        }
                        $this->context->globalConstAttributeEntries[strtolower($name)] = $entries;
                    }
                    break;
                case OpCode::TYPE_DECLARE_ENUM:
                    $name = $frame->scope[$op->arg1]->toString();
                    $lcname = strtolower($name);
                    if (isset($this->context->classes[$lcname]) || isset($this->context->enums[$lcname])) {
                        throw new \LogicException("Duplicate enum definition for $name");
                    }
                    $classEntry = new ClassEntry($name);
                    $classEntry->isEnum = true;
                    // Enums never grow dynamic properties (zend_enum.c / #26588).
                    $classEntry->noDynamicProperties = true;
                    $classEntry->allowsDynamicProperties = false;
                    \PHPCompiler\ext\standard\VmReflection::markCompilerBootstrapClassInternal($classEntry);
                    if (null !== $op->arg2 && isset($frame->block->constants[$op->arg2])) {
                        $classEntry->backedType = $frame->block->constants[$op->arg2]->toString();
                    }
                    $classEntry->interfaces = $op->classImplements;
                    $classEntry->isAbstract = $op->classIsAbstract;
                    $classEntry->attributeNames = $op->attributeNames;
                    $classEntry->attributeEntries = $op->attributeEntries;
                    $classEntry->classDeprecated = $op->deprecatedMetadata;
                    $classEntry->sourceLocation = $op->sourceLocation;
                    VM\ImplementsHierarchyRuntimeCheck::assertAllowed(
                        $name,
                        $op->classImplements,
                        $this->context,
                        $frame,
                        $op->sourceLocation,
                        null,
                        true
                    );
                    if ([] !== $op->classImplements) {
                        $missingIface = VM\ImplementsHierarchyRuntimeCheck::missingInterfaceMessage(
                            $op->classImplements,
                            $op->classImplementsDisplay,
                            $this->context
                        );
                        if (null !== $missingIface) {
                            $catchFrame = $this->dispatchVmError($missingIface, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        }
                        $notIface = VM\ImplementsHierarchyRuntimeCheck::notInterfaceMessage(
                            $name,
                            $op->classImplements,
                            $op->classImplementsDisplay,
                            $this->context
                        );
                        if (null !== $notIface) {
                            $this->raiseClassDeclareCompileFatal(new \CompileError($notIface), $frame);
                        }
                    }
                    self::defineClass($classEntry, $op->block1, $frame);
                    try {
                        $this->inheritFromInterfaces($classEntry);
                    } catch (\CompileError $e) {
                        if (VmEval::EVAL_FILENAME === $frame->scriptPath
                            || str_ends_with((string) $frame->scriptPath, VmEval::EVAL_FILENAME)
                        ) {
                            throw $e;
                        }
                        $this->raiseClassDeclareCompileFatal($e, $frame);
                    }
                    VM\EnumSupport::ensureBuiltinCasesMethod($classEntry);
                    VM\EnumSupport::ensureBuiltinEnumInterfaces($classEntry);
                    $this->context->classes[$lcname] = $classEntry;
                    $this->context->enums[$lcname] = true;
                    $this->flushDeferredTraitUses($frame);
                    $this->flushDeferredClassConstants();
                    break;
                case OpCode::TYPE_DECLARE_CLASS:
                    $name = $frame->scope[$op->arg1]->toString();
                    $lcname = strtolower($name);
                    if (isset($this->context->classes[$lcname])) {
                        throw new \LogicException("Duplicate class definition for $name");
                    }
                    $classEntry = new ClassEntry($name);
                    \PHPCompiler\ext\standard\VmReflection::markCompilerBootstrapClassInternal($classEntry);
                    $classEntry->interfaces = $op->classImplements;
                    $parentPending = false;
                    if (null !== $op->arg2) {
                        $parentName = $frame->scope[$op->arg2]->toString();
                        $parentLc = strtolower($parentName);
                        if (!isset($this->context->classes[$parentLc])) {
                            $this->context->autoloadClass($parentName);
                        }
                        if (!isset($this->context->classes[$parentLc])) {
                            $parentPending = true;
                        }
                        $classEntry->parentLc = $parentLc;
                    }
                    if (null !== $op->arg3 && isset($frame->block->constants[$op->arg3])) {
                        $classFlags = $frame->block->constants[$op->arg3]->toInt();
                        $classEntry->readonly = VM\ClassFlags::isReadonly($classFlags);
                        $classEntry->isAbstract = VM\ClassFlags::isAbstract($classFlags);
                        $classEntry->isStatic = VM\ClassFlags::isStatic($classFlags);
                        $classEntry->isFinal = VM\ClassFlags::isFinal($classFlags);
                    }
                    if ($op->isSealed) {
                        $classEntry->sealed = true;
                        $classEntry->sealedPermits = $this->normalizeSealedPermits($name, $op->sealedPermits);
                    }
                    $this->assertAllowedBySealedParents($name, $classEntry->parentLc, $classEntry->interfaces);
                    $classEntry->attributeNames = $op->attributeNames;
                    $classEntry->isAbstract = $op->classIsAbstract;
                    $classEntry->allowsDynamicProperties = AttributeNames::hasAllowDynamicProperties(
                        $op->attributeNames
                    );
                    $classEntry->attributeEntries = $op->attributeEntries;
                    $classEntry->classDeprecated = $op->deprecatedMetadata;
                    $classEntry->sourceLocation = $op->sourceLocation;
                    VM\ImplementsHierarchyRuntimeCheck::assertAllowed(
                        $name,
                        $op->classImplements,
                        $this->context,
                        $frame,
                        $op->sourceLocation,
                        $classEntry->parentLc,
                        false
                    );
                    if ([] !== $op->classImplements) {
                        $missingIface = VM\ImplementsHierarchyRuntimeCheck::missingInterfaceMessage(
                            $op->classImplements,
                            $op->classImplementsDisplay,
                            $this->context
                        );
                        if (null !== $missingIface) {
                            $catchFrame = $this->dispatchVmError($missingIface, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        }
                        $notIface = VM\ImplementsHierarchyRuntimeCheck::notInterfaceMessage(
                            $name,
                            $op->classImplements,
                            $op->classImplementsDisplay,
                            $this->context
                        );
                        if (null !== $notIface) {
                            $this->raiseClassDeclareCompileFatal(new \CompileError($notIface), $frame);
                        }
                    }
                    self::defineClass($classEntry, $op->block1, $frame);
                    try {
                        if (!$parentPending && null !== $classEntry->parentLc) {
                            $this->inheritFromParent($classEntry);
                        }
                        // Inherited static properties arrive after defineClass(); relink hooks (#6566).
                        if (!$parentPending) {
                            $this->linkStaticPropertyHooks($classEntry);
                        }
                        $this->inheritFromInterfaces($classEntry);
                        if (VM\LazyGhostTraitSupport::classUsesLazyGhostTrait($classEntry, $this->context)) {
                            VM\LazyGhostTraitSupport::ensureBuiltinLazyGhostMethods($classEntry);
                        }
                        if (!$parentPending) {
                            VM\ClassValidator::finalizeClassDefinition($classEntry, $this->context, $frame);
                        }
                    } catch (\CompileError $e) {
                        // Inside eval(): rethrow so TYPE_EVAL can raiseEvalCompileFatal with the
                        // caller site (Zend "file(line) : eval()'d code"). Outside eval, print and
                        // ScriptExit — cli_driver otherwise exits 255 without a message (#25384).
                        if (VmEval::EVAL_FILENAME === $frame->scriptPath
                            || str_ends_with((string) $frame->scriptPath, VmEval::EVAL_FILENAME)
                        ) {
                            throw $e;
                        }
                        $this->raiseClassDeclareCompileFatal($e, $frame);
                    }
                    $this->context->classes[$lcname] = $classEntry;
                    if ($parentPending) {
                        $this->context->deferredParentInheritance[] = [
                            'childLc' => $lcname,
                            'parentName' => $parentName,
                        ];
                    }
                    try {
                        $this->flushDeferredParentInheritance($frame);
                    } catch (\CompileError $e) {
                        $this->raiseClassDeclareCompileFatal($e, $frame);
                    }
                    $this->flushDeferredTraitUses($frame);
                    $this->flushDeferredClassConstants();
                    break;
                case OpCode::TYPE_NEW:
                    $result = $frame->scope[$op->arg1];
                    $rawName = $frame->scope[$op->arg2]->toString();
                    try {
                        $lcname = $this->resolveClassScopeName($rawName, $frame);
                    } catch (\LogicException $e) {
                        throw new \LogicException($e->getMessage());
                    }
                    if (!isset($this->context->classes[$lcname])) {
                        $rawLc = strtolower($rawName);
                        if (!in_array($rawLc, ['self', 'static', 'parent'], true)) {
                            $this->context->autoloadClass($rawName);
                        }
                    }
                    if (!isset($this->context->classes[$lcname])) {
                        $catchFrame = $this->dispatchVmError(
                            $this->classNotFoundMessage($rawName),
                            $frame
                        );
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    $class = $this->context->classes[$lcname];
                    $reservedMsg = VM\ReservedBuiltinClass::userInstantiationErrorMessage($lcname);
                    if (null !== $reservedMsg) {
                        $catchFrame = $this->dispatchVmError($reservedMsg, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    if ($class->isEnum || $class->isAbstract) {
                        $msg = $class->isEnum
                            ? "Cannot instantiate enum {$class->name}"
                            : "Cannot instantiate abstract class {$class->name}";
                        $catchFrame = $this->dispatchVmError($msg, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    if ($class->isInterface) {
                        $catchFrame = $this->dispatchVmError(
                            "Cannot instantiate interface {$class->name}",
                            $frame
                        );
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    if ($class->isTrait) {
                        $catchFrame = $this->dispatchVmError(
                            "Cannot instantiate trait {$class->name}",
                            $frame
                        );
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    try {
                        VM\ClassValidator::assertInstantiable($class);
                    } catch (\Error $e) {
                        $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    $catchFrame = $this->enforceNewConstructorVisibility($class, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $this->emitClassInstantiationDeprecation($class, $frame);
                    $object = new ObjectEntry($class);
                    $this->initInstancePropertyDefaults($object);
                    if (null !== $op->arg3 && VM\ExceptionSupport::classEntryImplementsThrowable($class, $this->context)) {
                        $newLine = (int) $op->arg3;
                        if ($newLine > 0) {
                            $object->getProperty(VM\ExceptionSupport::PROP_LINE)->int($newLine);
                        }
                    }
                    $result->object($object);
                    $this->markScopeSlotInitialized($frame, (int) $op->arg1);
                    $this->savePendingOutboundCallForInlineNew($frame);
                    $frame->call = $object->constructor;
                    $frame->callArgs = [$result];
                    $frame->callArgEntries = [];
                    $frame->builtinCalleeQualifiedMethod = $class->name.'::__construct';
                    if (null === $frame->call) {
                        $object->constructed = true;
                        // No constructor body — clear the provisional Class::__construct label (#10009).
                        $frame->builtinCalleeQualifiedMethod = null;
                        $newResultSlot = (int) $op->arg1;
                        if (!$this->isVmScopeSlotUsedByFollowingOps($frame, $newResultSlot)) {
                            $this->releaseVmDeadScopeSlot($frame, $newResultSlot);
                        }
                    }
                    break;
                case OpCode::TYPE_PROPERTY_FETCH:
                case OpCode::TYPE_PROPERTY_FETCH_WRITE:
                    $result = $frame->scope[$op->arg1];
                    $propertyFetchForWrite = OpCode::TYPE_PROPERTY_FETCH_WRITE === $op->type;
                    $fiber = $this->context->currentFiber;
                    if (null !== $fiber?->propertyHookResumeRead) {
                        $result->copyFrom($fiber->propertyHookResumeRead->resolveIndirect());
                        $fiber->propertyHookResumeRead = null;
                        break;
                    }
                    $catchFrame = $this->guardUnboundThisRead($frame, (int) $op->arg2);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $var = $frame->scope[$op->arg2]->resolveIndirect();
                    [$name, $catchFrame] = $this->coerceRuntimeOperandToString($frame->scope[$op->arg3], $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $catchFrame = $this->enforcePropertyName($name, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    if (Variable::TYPE_ENUM_CASE === $var->type) {
                        $enumEntry = $var->toEnumCase()->enumClass;
                        $forWrite = $propertyFetchForWrite || $this->propertyFetchDestUsedAsAssignLvalue($frame, $op);
                        if ($forWrite) {
                            // Readonly name/value, else Cannot create dynamic property (#26588).
                            $writeMsg = EnumCaseSupport::propertyWriteViolationMessage($enumEntry, $name);
                            $catchFrame = $this->dispatchVmError($writeMsg, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }

                            return self::EXCEPTION;
                        }
                        try {
                            $prop = $var->toEnumCase()->fetchProperty($name, $this->context, $frame);
                        } catch (\LogicException $e) {
                            return $this->raise($e->getMessage(), $frame);
                        } catch (\Error $e) {
                            $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }

                            return self::EXCEPTION;
                        }
                        $result->copyFrom($prop);
                        break;
                    }
                    if (TypeCheck::isNonObjectPropertyFetchReceiver($var)) {
                        $resolved = $var->resolveIndirect();
                        $typeName = TypeCheck::typeNameForConstraint($resolved->type);
                        $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
                        $forWrite = $propertyFetchForWrite || $this->propertyFetchDestUsedAsAssignLvalue($frame, $op);
                        if ($forWrite) {
                            if (
                                Variable::TYPE_NULL === $resolved->type
                                && $this->propertyFetchDestUsedAsIncDec($frame, $op)
                            ) {
                                $catchFrame = $this->dispatchVmError(
                                    sprintf('Attempt to increment/decrement property "%s" on null', $name),
                                    $frame
                                );
                            } else {
                                $catchFrame = $this->dispatchVmError(
                                    sprintf('Attempt to assign property "%s" on %s', $name, $typeName),
                                    $frame
                                );
                            }
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        }
                        if ($op->nullsafeFetchPropertyRead) {
                            // IS-mode (??/isset/empty) or null: silent like FETCH_OBJ_IS (#18026).
                            // R-mode nullsafe on scalar/array: warn like plain -> (#26365).
                            if (
                                $op->nullsafeUninitNullableToNull
                                || Variable::TYPE_NULL === $resolved->type
                            ) {
                                $result->null();
                                break;
                            }
                        } elseif (Variable::TYPE_NULL === $resolved->type) {
                            $this->context->errors->propertyReadOnNonObject(
                                $name,
                                'null',
                                $this->context,
                                $frame,
                                $scriptFile
                            );
                            $result->null();
                            break;
                        }
                        $this->context->errors->propertyReadOnNonObject(
                            $name,
                            $typeName,
                            $this->context,
                            $frame,
                            $scriptFile
                        );
                        $result->null();
                        break;
                    }
                    $propertyObject = $var->toObject();
                    if (!VM\LazyObjectSupport::skipLazyInitForPropertyRead($propertyObject, $name)) {
                        $catchFrame = $this->ensureLazyObjectInitialized($propertyObject, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    }
                    $propertyObject = VM\LazyObjectSupport::getLazyInstance($propertyObject);
                    // __PHP_Incomplete_Class — block userland property ops (zend_object_handlers.c, #19632).
                    if (VM\IncompleteClassSupport::isIncomplete($propertyObject)) {
                        $forWrite = $propertyFetchForWrite || $this->propertyFetchDestUsedAsAssignLvalue($frame, $op);
                        if ($forWrite) {
                            $catchFrame = $this->dispatchVmError(
                                VM\IncompleteClassSupport::modifyErrorMessage($propertyObject),
                                $frame
                            );
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }

                            return self::EXCEPTION;
                        }
                        if ($op->nullsafeFetchPropertyRead) {
                            $result->null();
                            break;
                        }
                        VM\IncompleteClassSupport::emitAccessWarning($propertyObject, $this->context, $frame);
                        $result->null();
                        break;
                    }
                    if (EnumCaseSupport::isEnumCase($propertyObject)) {
                        $forWrite = $propertyFetchForWrite || $this->propertyFetchDestUsedAsAssignLvalue($frame, $op);
                        if ($forWrite) {
                            // Readonly name/value, else Cannot create dynamic property (#26588).
                            $writeMsg = EnumCaseSupport::propertyWriteViolationMessage(
                                $propertyObject->class,
                                $name
                            );
                            $catchFrame = $this->dispatchVmError($writeMsg, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }

                            return self::EXCEPTION;
                        }
                        try {
                            $result->copyFrom(EnumCaseSupport::getProperty(
                                $propertyObject,
                                $name,
                                $this->context,
                                $frame
                            ));
                        } catch (\Error $e) {
                            $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }

                            return self::EXCEPTION;
                        }
                        break;
                    }
                    $forWrite = $propertyFetchForWrite || $this->propertyFetchDestUsedAsAssignLvalue($frame, $op);
                    $magicGetForRead = !$forWrite
                        && !$op->propertyHookCoalesceRead
                        && $this->propertyReadUsesMagicGet($propertyObject, $name, $frame);
                    // ?? / ??= use BP_VAR_IS: skip Error / Undefined from read visibility — isset-like (#29503).
                    if (!$magicGetForRead && !$forWrite && !$op->propertyHookCoalesceRead) {
                        $catchFrame = $this->enforcePropertyVisibilityRead($propertyObject, $name, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    }
                    if (!$magicGetForRead && !$forWrite && !$op->propertyHookCoalesceRead) {
                        $invisibleParentPrivateMeta = $this->classPropertyMeta($propertyObject, $name, $frame);
                        if (
                            null !== $invisibleParentPrivateMeta
                            && (
                                $invisibleParentPrivateMeta->phpInvisible
                                || $this->isParentPrivatePropertyInvisibleFromCaller(
                                    $invisibleParentPrivateMeta,
                                    $frame,
                                    $propertyObject
                                )
                            )
                        ) {
                            // Non-null receiver: nullsafe still warns like plain -> (#23705).
                            $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
                            $this->context->errors->undefinedPropertyRead(
                                $propertyObject->class->name,
                                $name,
                                $this->context,
                                $frame,
                                $scriptFile
                            );
                            $result->null();
                            break;
                        }
                    }
                    if ($op->propertyHookCoalesceRead && !$forWrite) {
                        // ?? / ??= still throws on virtual write-only (zend BP_VAR_IS; #29240).
                        $catchFrame = $this->enforceWriteOnlyVirtualPropertyRead($propertyObject, $name, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        $catchFrame = $this->fetchObjectPropertyForCoalesce($propertyObject, $name, $result, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    if ($propertyObject->hasProperty($name) && !$magicGetForRead) {
                        if (!$forWrite) {
                            VM\LazyPropertySupport::ensureDeclarativeLazyPropertyInitialized(
                                $this,
                                $propertyObject,
                                $name
                            );
                        }
                        if (!$forWrite) {
                            $this->emitInstancePropertyAccessDeprecation($propertyObject, $name, $frame);
                        }
                        if ($forWrite) {
                            // `$r = &$obj->inaccessible` / `return $obj->inaccessible` from `&fn`
                            // — get_property_ptr_ptr fails; BP_VAR_W read_property invokes __get
                            // (zend_object_handlers.c, #25688 / #29456).
                            if (
                                $this->propertyFetchDestUsedAsLiveRefBinding($frame, $op)
                                && $this->propertyReadUsesMagicGet($propertyObject, $name, $frame)
                            ) {
                                $this->deliverInaccessiblePropertyFetchByRef(
                                    $result,
                                    $propertyObject,
                                    $name,
                                    $frame
                                );
                                break;
                            }
                            $writeProxy = new Variable();
                            $writeProxy->objectPropertyOwner = $propertyObject;
                            $writeProxy->objectPropertyName = $name;
                            // `$r = &$obj->readonlyProp` / by-ref return — zend_readonly.c (#25620 / #29456).
                            // Must Error before binding; write-through checks alone leave REF_OK.
                            if ($this->propertyFetchDestUsedAsLiveRefBinding($frame, $op)) {
                                $catchFrame = $this->enforceReadonlyPropertyFetchByRef($writeProxy, $frame);
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }
                            }
                            // `$r = &$obj->hooked` / by-ref return — PROPERTY_FETCH_WRITE; Zend rejects
                            // without `&get` at get_ptr time, not as write-only (#22475 / #29456).
                            if (!$this->propertyFetchDestUsedAsLiveRefBinding($frame, $op)) {
                                $catchFrame = $this->enforceVirtualPropertyHookWrite($writeProxy, $frame);
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }
                            }
                            $readBeforeAssign = $this->propertyFetchDestUsedAsReadBeforeAssign($frame, $op);
                            if ($readBeforeAssign) {
                                $hookValue = $this->fetchPropertyWithHooks($propertyObject, $name, $frame);
                                if (null !== $hookValue) {
                                    $result->copyFrom($hookValue);
                                    $result->objectPropertyOwner = $propertyObject;
                                    $result->objectPropertyName = $name;
                                    break;
                                }
                            }
                            if ($this->propertyFetchDestUsedAsDimWriteContainer($frame, $op)) {
                                $proxy = new Variable();
                                $proxy->objectPropertyOwner = $propertyObject;
                                $proxy->objectPropertyName = $name;
                                $catchFrame = $this->enforceAsymmetricPropertyWrite($proxy, $frame);
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }
                                $this->tagReadonlyPropertyDimWriteContainer($result, $propertyObject, $name);
                                // `&get`-only: dim writes mutate live backing through the by-ref get (#21098).
                                if ($this->deliverByRefGetHookedPropertyDimWriteContainer(
                                    $result,
                                    $propertyObject,
                                    $name,
                                    $frame
                                )) {
                                    break;
                                }
                                // Without `&get`, refuse before RMW / backing write (#28590, php-src 8.4.24+).
                                $catchFrame = $this->enforceHookedPropertyDimWriteRequiresByRefGet(
                                    $propertyObject,
                                    $name,
                                    $frame
                                );
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }
                                // Virtual `&get`+`set`: RMW via get then set write-back (#21098).
                                $hookValue = $this->fetchPropertyWithHooks($propertyObject, $name, $frame);
                                if (null !== $hookValue) {
                                    $catchFrame = $this->deliverHookedPropertyDimWriteContainer(
                                        $result,
                                        $hookValue,
                                        $propertyObject,
                                        $name,
                                        $frame
                                    );
                                    if (null !== $catchFrame) {
                                        $frame = $catchFrame;
                                        goto restart;
                                    }
                                    break;
                                }
                            }
                            $catchFrame = $this->enforceDomDocumentReadOnlyPropertyWrite($propertyObject, $name, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            $catchFrame = $this->enforceInternalDynamicPropertyCreate($propertyObject, $name, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            // Declared-but-UNDEF (e.g. after unset): BP_VAR_RW ++/-- warns like a read (#29241).
                            $warnUndefAfterRw = $this->propertyFetchDestUsedAsIncDec($frame, $op)
                                && $this->objectPropertySlotIsUndefinedForRwWarn($propertyObject, $name, $frame);
                            $result->indirect($this->fetchObjectPropertyWriteLvalue($propertyObject, $name, $frame));
                            if ($warnUndefAfterRw) {
                                $this->warnUndefinedPropertyAfterIncDecRwFetch($propertyObject, $name, $frame);
                            }
                            if ($this->propertyFetchDestUsedAsLiveRefBinding($frame, $op)) {
                                $result->propertyRefAcquisition = true;
                            } else {
                                $result->propertyAssignLvalue = true;
                            }
                            break;
                        }
                        $catchFrame = $this->enforceWriteOnlyVirtualPropertyRead($propertyObject, $name, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        $hookValue = $this->fetchPropertyWithHooks($propertyObject, $name, $frame);
                        if (null !== $hookValue) {
                            if ($this->propertyFetchDestUsedAsDimWriteContainer($frame, $op)) {
                                if ($this->deliverByRefGetHookedPropertyDimWriteContainer(
                                    $result,
                                    $propertyObject,
                                    $name,
                                    $frame
                                )) {
                                    break;
                                }
                                $catchFrame = $this->enforceHookedPropertyDimWriteRequiresByRefGet(
                                    $propertyObject,
                                    $name,
                                    $frame
                                );
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }
                                $catchFrame = $this->deliverHookedPropertyDimWriteContainer(
                                    $result,
                                    $hookValue,
                                    $propertyObject,
                                    $name,
                                    $frame
                                );
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }
                            } elseif ($this->propertyFetchDestUsedAsByRefForeachIterable($frame, $op)) {
                                // foreach ($obj->hooked as &$v) — FE_RESET_RW / #29215.
                                if ($this->deliverByRefGetHookedPropertyDimWriteContainer(
                                    $result,
                                    $propertyObject,
                                    $name,
                                    $frame
                                )) {
                                    break;
                                }
                                $catchFrame = $this->enforceHookedPropertyDimWriteRequiresByRefGet(
                                    $propertyObject,
                                    $name,
                                    $frame
                                );
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }
                                $result->copyFrom($hookValue);
                            } else {
                                $result->copyFrom($hookValue);
                            }
                        } else {
                            if ($this->propertyFetchDestUsedAsDimWriteContainer($frame, $op)) {
                                $proxy = new Variable();
                                $proxy->objectPropertyOwner = $propertyObject;
                                $proxy->objectPropertyName = $name;
                                $catchFrame = $this->enforceAsymmetricPropertyWrite($proxy, $frame);
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }
                                $this->tagReadonlyPropertyDimWriteContainer($result, $propertyObject, $name);
                            }
                            $catchFrame = $this->enforceVirtualPropertyHookRawAccess(
                                $propertyObject,
                                $name,
                                true,
                                $frame
                            );
                            if (null !== $this->context->propertyHookExternalCatchFrame) {
                                return self::FAILURE;
                            }
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            $propMeta = $this->classPropertyMeta($propertyObject, $name, $frame);
                            $domStaleMsg = ext\dom\VmDom::fetchableNodeErrorMessage($propertyObject);
                            if (null !== $domStaleMsg) {
                                $catchFrame = $this->dispatchVmError($domStaleMsg, $frame);
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }
                                $result->null();
                                break;
                            }
                            $propSlot = null !== $propMeta && $propertyObject->hasPropertyForMeta($propMeta)
                                ? $propertyObject->getPropertyForMeta($propMeta)
                                : $propertyObject->getProperty($name);
                            if (
                                $op->nullsafeFetchPropertyRead
                                && $op->nullsafeUninitNullableToNull
                                && VM\TypedPropertyCheck::isUninitialized($propSlot)
                                && VM\TypedPropertyCheck::propertyAllowsNull($propSlot)
                            ) {
                                $result->null();
                                break;
                            }
                            // Untyped declared property after unset: E_WARNING + NULL (#22021, zend_object_handlers.c).
                            // Nullsafe on a live object still warns (#23705) — only null receivers short-circuit.
                            if (
                                $propSlot->resolveIndirect()->isUndefined()
                                && !VM\TypedPropertyCheck::isUninitialized($propSlot)
                            ) {
                                $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
                                $this->context->errors->undefinedPropertyRead(
                                    $propertyObject->class->name,
                                    $name,
                                    $this->context,
                                    $frame,
                                    $scriptFile
                                );
                                $result->null();
                                break;
                            }
                            VM\TypedPropertyCheck::assertReadable($propSlot);
                            // `$obj->arr[]=` / unset($obj->arr[$k]) need a live alias into property storage.
                            // Plain R-mode fetches must copy: an indirect alias makes ternary/`&&` phi self-ASSIGN
                            // look like a property write (readonly / DOM read-only / skipped `__get`) (#23986, #24250).
                            // By-ref `return $this->prop` also needs the live cell (#29456) — compiler prefers
                            // PROPERTY_FETCH_WRITE, but keep R-mode resilient when usages are empty.
                            if (
                                $this->propertyFetchDestUsedAsDimWriteContainer($frame, $op)
                                || $this->propertyFetchDestUsedAsReturnByRef($frame, $op)
                            ) {
                                $result->indirect($propSlot);
                            } elseif ($this->propertyFetchDestUsedAsByRefForeachIterable($frame, $op)) {
                                // Hooked array without &get must Error before FE_RESET_RW (#29215).
                                if ($this->deliverByRefGetHookedPropertyDimWriteContainer(
                                    $result,
                                    $propertyObject,
                                    $name,
                                    $frame
                                )) {
                                    break;
                                }
                                $catchFrame = $this->enforceHookedPropertyDimWriteRequiresByRefGet(
                                    $propertyObject,
                                    $name,
                                    $frame
                                );
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }
                                $result->copyFrom($propSlot);
                            } else {
                                $result->copyFrom($propSlot);
                            }
                        }
                        break;
                    }
                    if ($forWrite) {
                        // Missing / uninitialized declared prop still trips by-ref readonly (#25620).
                        if ($this->propertyFetchDestUsedAsLiveRefBinding($frame, $op)) {
                            $missingRefProxy = new Variable();
                            $missingRefProxy->objectPropertyOwner = $propertyObject;
                            $missingRefProxy->objectPropertyName = $name;
                            $catchFrame = $this->enforceReadonlyPropertyFetchByRef($missingRefProxy, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                        }
                        $catchFrame = $this->enforceReadonlyDynamicPropertyCreate($propertyObject, $name, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        $catchFrame = $this->enforceInternalDynamicPropertyCreate($propertyObject, $name, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        // Missing dynamic prop: create then Undefined property for ++/-- (BP_VAR_RW, #29241).
                        $warnUndefAfterRw = $this->propertyFetchDestUsedAsIncDec($frame, $op);
                        $result->indirect($this->fetchObjectPropertyWriteLvalue($propertyObject, $name, $frame));
                        if ($warnUndefAfterRw) {
                            $this->warnUndefinedPropertyAfterIncDecRwFetch($propertyObject, $name, $frame);
                        }
                        if ($this->propertyFetchDestUsedAsLiveRefBinding($frame, $op)) {
                            $result->propertyRefAcquisition = true;
                        } else {
                            $result->propertyAssignLvalue = true;
                        }
                        break;
                    }
                    if ($magicGetForRead) {
                        $this->deliverMagicGetRead($result, $propertyObject, $name);
                        break;
                    }
                    if (SplArrayStorage::hasArrayAsProps($propertyObject)) {
                        $key = new Variable(Variable::TYPE_STRING);
                        $key->string($name);
                        // php-src spl_array_read_property — Undefined array key (not property) (#28820).
                        $result->copyFrom(SplArrayStorage::offsetGet($propertyObject, $key, $frame));
                        break;
                    }
                    // Undefined property on a non-null object: warn for both -> and ?-> (#23705).
                    // Nullsafe only skips the warning when the receiver itself is null (TYPE_NULLSAFE).
                    $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
                    $this->context->errors->undefinedPropertyRead(
                        $propertyObject->class->name,
                        $name,
                        $this->context,
                        $frame,
                        $scriptFile
                    );
                    $result->null();
                    break;
                case OpCode::TYPE_INIT_ARRAY:
                    $result = $frame->scope[$op->arg1];
                    $result->newArray();
                    if (is_null($op->arg2)) {
                        break;
                    }
                    // Fall through intentional
                case OpCode::TYPE_ADD_ARRAY_ELEMENT:
                    try {
                        $result = $frame->scope[$op->arg1];
                        $catchFrame = $this->rejectMagicGetIndirectModify($result, true, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        $ht = $result->toArray();
                        if (is_null($op->arg3)) {
                            $ht->append($this->materializeArrayElementForStorage(
                                $this->resolveOutgoingCallArgValue($frame, $op->arg2)
                            ));
                            break;
                        }
                        $key = $this->resolveOutgoingCallArgValue($frame, $op->arg3)->resolveIndirect();
                        $value = $this->materializeArrayElementForStorage(
                            $this->resolveOutgoingCallArgValue($frame, $op->arg2)
                        );
                        // Array-literal keys share assignment's typed TypeError (#28628 / zend_illegal_container_offset).
                        // Resource keys warn+cast (#29550); float precision via normalizeIndexKeyForWrite.
                        $key = VM\HashTable::normalizeIndexKeyForWrite($key, $this->context, $frame);
                        if ($key->is(Variable::TYPE_INTEGER) || $key->is(Variable::TYPE_FLOAT)) {
                            $ht->updateIndex(
                                $key->is(Variable::TYPE_FLOAT)
                                    ? \PHPCompiler\ext\standard\VmMath::floatToZendLong($key->toFloat())
                                    : $key->toInt(),
                                $value
                            );
                        } elseif ($key->is(Variable::TYPE_STRING)) {
                            $ht->update($key->toString(), $value);
                        } else {
                            throw new \TypeError(VM\EnumCaseSupport::illegalArrayOffsetMessage($key));
                        }
                    } catch (\TypeError $e) {
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    }
                    break;
                case OpCode::TYPE_ARRAY_SPREAD:
                    try {
                        $result = $frame->scope[$op->arg1];
                        $source = $frame->scope[$op->arg2];
                        VM\ArraySpread::spreadInto(
                            $this,
                            $frame,
                            $result->toArray(),
                            $source,
                            (int) ($op->arg3 ?? 0)
                        );
                    } catch (\TypeError $e) {
                        // TypeError extends Error — must precede catch (\Error) (#27952).
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    } catch (\Error $e) {
                        $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    }
                    break;
                case OpCode::TYPE_CLONE:
                    $result = $frame->scope[$op->arg1];
                    $src = $frame->scope[$op->arg2]->resolveIndirect();
                    $uncloneableEnumClass = VM\EnumCaseSupport::uncloneableEnumClassForClone(
                        $src,
                        $this->context
                    );
                    if (null !== $uncloneableEnumClass) {
                        $message = VM\CloneSupport::uncloneableObjectErrorMessage($uncloneableEnumClass);
                        $catchFrame = $this->dispatchVmError($message, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    if (Variable::TYPE_OBJECT !== $src->type) {
                        $catchFrame = $this->dispatchVmError(
                            VM\CloneSupport::NON_OBJECT_ERROR_MESSAGE,
                            $frame
                        );
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    $srcObject = $src->toObject();
                    $deniedCloneClass = VM\CloneSupport::uncloneableDeniedClass($srcObject, $this->context);
                    if (null !== $deniedCloneClass) {
                        $catchFrame = $this->dispatchVmError(
                            VM\CloneSupport::uncloneableObjectErrorMessage($deniedCloneClass),
                            $frame
                        );
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    $catchFrame = $this->enforceCloneVisibility($srcObject, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    // Zend/zend_lazy_objects.c zend_lazy_object_clone — init pending ghost/proxy
                    // before clone so both original and clone are initialized (#29171).
                    $catchFrame = $this->ensureLazyObjectInitialized($srcObject, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $cloned = $srcObject->cloneShallow();
                    $this->invokeCloneObjectHandler($srcObject, $cloned);
                    $catchFrame = $this->invokeCloneMagicMethod($cloned, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $result->object($cloned);
                    break;
                case OpCode::TYPE_BOOLEAN_NOT:
                    $value = !($frame->scope[$op->arg2]->toBool());
                    $dst = $frame->scope[$op->arg1];
                    $dst->bool($value);
                    break;
                case OpCode::TYPE_EMPTY:
                    if ($this->isUnboundThisSlot($frame, (int) $op->arg2)) {
                        $frame->scope[$op->arg1]->bool(true);
                        break;
                    }
                    $v = $frame->scope[$op->arg2]->resolveIndirect();
                    if (VM\TypedPropertyCheck::isUninitialized($v)) {
                        $frame->scope[$op->arg1]->bool(true);
                        break;
                    }
                    $frame->scope[$op->arg1]->bool(!ext\standard\boolval::isTruthy($v));
                    break;
                case OpCode::TYPE_EMPTY_OBJECT_PROPERTY:
                    $dst = $frame->scope[$op->arg1];
                    $container = $frame->scope[$op->arg2]->resolveIndirect();
                    [$propName, $catchFrame] = $this->coerceRuntimeOperandToString($frame->scope[$op->arg3], $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $catchFrame = $this->enforcePropertyName($propName, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    if (Variable::TYPE_ENUM_CASE === $container->type) {
                        $dst->bool(VM\EnumCaseSupport::emptyPropertyOnCase(
                            $container->toEnumCase(),
                            $propName,
                            $this->context,
                            $frame
                        ));
                        break;
                    }
                    if (Variable::TYPE_OBJECT !== $container->type) {
                        $dst->bool(true);
                        break;
                    }
                    $object = $container->toObject();
                    if (VM\EnumCaseSupport::isEnumCase($object)) {
                        $enum = $object->class;
                        if (!VM\EnumCaseSupport::propertyExistsOnCase($enum, $propName)) {
                            $dst->bool(true);
                            break;
                        }
                        $prop = VM\EnumCaseSupport::getProperty($object, $propName, $this->context, $frame);
                        $dst->bool(!ext\standard\boolval::isTruthy($prop));
                        break;
                    }
                    $catchFrame = $this->ensureLazyObjectInitialized($object, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $object = VM\LazyObjectSupport::getLazyInstance($object);
                    $catchFrame = $this->emptyObjectProperty(
                        $object,
                        $propName,
                        $frame,
                        $dst
                    );
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    break;
                case OpCode::TYPE_EMPTY_STATIC_PROPERTY:
                    $dst = $frame->scope[$op->arg1];
                    $lcClass = $this->resolveStaticPropertyClassLc($frame->scope[$op->arg2], $frame);
                    if (!isset($this->context->classes[$lcClass])) {
                        $classOperand = $frame->scope[$op->arg2]->resolveIndirect();
                        $rawClass = Variable::TYPE_OBJECT === $classOperand->type
                            ? $classOperand->toObject()->class->name
                            : $classOperand->toString();
                        if ('self' !== strtolower($rawClass) && 'static' !== strtolower($rawClass)) {
                            $this->context->autoloadClass($rawClass);
                        }
                    }
                    if (!isset($this->context->classes[$lcClass])) {
                        $dst->bool(true);
                        break;
                    }
                    $propNameRaw = $frame->scope[$op->arg3]->toString();
                    $catchFrame = $this->emptyStaticProperty($lcClass, $propNameRaw, $frame, $dst);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    break;
                case OpCode::TYPE_EMPTY_DIMENSION:
                    $dst = $frame->scope[$op->arg1];
                    $catchFrame = $this->evaluateEmptyDimension(
                        $frame->scope[$op->arg2],
                        $frame->scope[$op->arg3],
                        $frame,
                        $dst
                    );
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    break;
                case OpCode::TYPE_ISSET:
                    $dst = $frame->scope[$op->arg1];
                    if (null === $op->arg3 && $this->isUnboundThisSlot($frame, (int) $op->arg2)) {
                        $dst->bool(false);
                        break;
                    }
                    if (null !== $op->arg3) {
                        if ($op->issetOnStaticProperty) {
                            $lcClass = $this->resolveStaticPropertyClassLc($frame->scope[$op->arg2], $frame);
                            $propNameRaw = $frame->scope[$op->arg3]->toString();
                            $dst->bool($this->staticPropertyIsSetForCoalesceAssign($lcClass, $propNameRaw));
                            break;
                        }
                        $container = $frame->scope[$op->arg2]->resolveIndirect();
                        if (Variable::TYPE_ENUM_CASE === $container->type) {
                            [$propName, $catchFrame] = $this->coerceRuntimeOperandToString(
                                $frame->scope[$op->arg3],
                                $frame
                            );
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            $catchFrame = $this->enforcePropertyName($propName, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            $dst->bool(EnumCaseSupport::propertyExistsOnCase(
                                $container->toEnumCase()->enumClass,
                                $propName
                            ));
                            break;
                        }
                        if (Variable::TYPE_ARRAY === $container->type) {
                            if ($this->context->isGlobalsTable($container)) {
                                $dst->bool($this->context->globalsTableOffsetIsSet($frame->scope[$op->arg3]));
                                break;
                            }
                            if ($op->issetOnProperty) {
                                $dst->bool(false);
                                break;
                            }
                            try {
                                $dst->bool($container->toArray()->offsetIsSet($frame->scope[$op->arg3], $frame));
                            } catch (\TypeError $e) {
                                $catchFrame = $this->dispatchVmTypeError($e, $frame);
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }
                            }
                            break;
                        }
                        if (Variable::TYPE_OBJECT === $container->type) {
                            $object = $container->toObject();
                            if (EnumCaseSupport::isEnumCase($object)) {
                                [$propName, $catchFrame] = $this->coerceRuntimeOperandToString(
                                    $frame->scope[$op->arg3],
                                    $frame
                                );
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }
                                $catchFrame = $this->enforcePropertyName($propName, $frame);
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }
                                $dst->bool(EnumCaseSupport::propertyExistsOnCase($object->class, $propName));
                                break;
                            }
                            if (
                                !$op->issetOnProperty
                                && VmDomCollectionDimension::isCollection($object)
                            ) {
                                // isset($list[$i]) via DOM has_dimension (php-src php_dom.c; #20311).
                                // TokenList illegal offsets TypeError (token_list.c; #23006).
                                try {
                                    $dst->bool(VmDomCollectionDimension::hasDimension(
                                        $object,
                                        $frame->scope[$op->arg3]
                                    ));
                                } catch (\TypeError $e) {
                                    $catchFrame = $this->dispatchVmTypeError($e, $frame);
                                    if (null !== $catchFrame) {
                                        $frame = $catchFrame;
                                        goto restart;
                                    }
                                }
                                break;
                            }
                            if (
                                !$op->issetOnProperty
                                && $this->objectImplementsArrayAccess($object)
                            ) {
                                // ArrayObject/ArrayIterator native has_dimension(isset): null ≠ set (#24251).
                                // User offsetExists overrides keep ArrayAccess isset == offsetExists (php-src).
                                $nativeSplIsset = $this->nativeSplArrayDimensionIsSet(
                                    $object,
                                    $frame->scope[$op->arg3]
                                );
                                if (null !== $nativeSplIsset) {
                                    $dst->bool($nativeSplIsset);
                                    break;
                                }
                                // isset($obj[$k]) via ArrayAccess::offsetExists — not isset($obj->prop) (#19707).
                                $existsOut = new Variable();
                                $catchFrame = $this->invokeArrayAccessOffsetExists(
                                    $object,
                                    $frame->scope[$op->arg3],
                                    $frame,
                                    $existsOut
                                );
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }
                                $dst->bool($existsOut->toBool());
                                break;
                            }
                            if (!$op->issetOnProperty) {
                                // isset($obj[$k]) without has_dimension / ArrayAccess — Zend Error
                                // (ResourceBundle has read_dimension only; #25145).
                                $catchFrame = $this->dispatchVmError(
                                    'Cannot use object of type ' . $object->class->name . ' as array',
                                    $frame
                                );
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }
                                break;
                            }
                            [$propName, $catchFrame] = $this->coerceRuntimeOperandToString($frame->scope[$op->arg3], $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            $catchFrame = $this->enforcePropertyName($propName, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            $catchFrame = $this->ensureLazyObjectInitialized($object, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            $object = VM\LazyObjectSupport::getLazyInstance($object);
                            if (!$op->issetForCoalesceAssign) {
                                $catchFrame = $this->enforceWriteOnlyVirtualPropertyRead($object, $propName, $frame);
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }
                            }
                            $dst->bool(
                                $op->issetForCoalesceAssign
                                    ? $this->objectPropertyIsSetForCoalesceAssign($object, $propName, $frame)
                                    : $this->objectPropertyIsSet($object, $propName, $frame)
                            );
                            break;
                        }
                        if (Variable::TYPE_STRING === $container->type) {
                            if ($op->issetOnProperty) {
                                $dst->bool(false);
                                break;
                            }
                            $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
                            $dst->bool(Variable::stringOffsetIsSetFromDim(
                                $container,
                                $frame->scope[$op->arg3],
                                $this->context->errors,
                                $this->context,
                                $frame,
                                $scriptFile
                            ));
                            break;
                        }
                        $dst->bool(false);
                        break;
                    }
                    $value = $frame->scope[$op->arg2]->resolveIndirect();
                    $dst->bool(
                        !$value->isUndefined()
                        && Variable::TYPE_NULL !== $value->type
                    );
                    break;
                case OpCode::TYPE_SCRIPT_MAGIC:
                    $dst = $frame->scope[$op->arg1];
                    if (OpCode::SCRIPT_MAGIC_HALT_OFFSET === $op->arg3) {
                        $offset = $this->context->runtime->compiler->getHaltCompilerOffset();
                        if (null === $offset) {
                            return $this->raise('Undefined constant "__COMPILER_HALT_OFFSET__"', $frame);
                        }
                        $dst->int($offset);
                        break;
                    }
                    if (OpCode::SCRIPT_MAGIC_LINE === $op->arg3) {
                        $line = null !== $op->arg2 ? (int) $op->arg2 : 0;
                        if ($line < 1) {
                            $line = 1;
                        }
                        $dst->int($line);
                        break;
                    }
                    $script = '' !== $frame->scriptPath
                        ? $frame->scriptPath
                        : $this->context->scriptStack->current();
                    if ('' === $script) {
                        return $this->raise('__DIR__/__FILE__ used without script context', $frame);
                    }
                    if (OpCode::SCRIPT_MAGIC_DIR === $op->arg3) {
                        $dst->string(dirname($script));
                    } else {
                        $dst->string($script);
                    }
                    break;
                case OpCode::TYPE_INCLUDE:
                    $file = null;
                    if (null !== $op->arg3 && isset($frame->block->literalIncludePaths[$op->arg3])) {
                        $file = $frame->block->literalIncludePaths[$op->arg3];
                    } elseif (null !== $op->arg3 && isset($frame->block->deployIncludePaths[$op->arg3])) {
                        $spec = $frame->block->deployIncludePaths[$op->arg3];
                        $file = $spec['compile'] ?? \PHPCompiler\Web\DeployRoot::resolvePathWithSuffix(
                            $spec['rel'],
                            $spec['fallback'],
                            $spec['suffix']
                        );
                    }
                    if (null === $file) {
                        try {
                            $file = $frame->scope[$op->arg1]->toString();
                        } catch (\Error $e) {
                            $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        } catch (\TypeError $e) {
                            $catchFrame = $this->dispatchVmTypeError($e, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        }
                    }

                    $kind = $op->includeKind ?? OpCode::INCLUDE_KIND_INCLUDE_ONCE;
                    $once = $kind === OpCode::INCLUDE_KIND_INCLUDE_ONCE || $kind === OpCode::INCLUDE_KIND_REQUIRE_ONCE;
                    $isRequire = $kind === OpCode::INCLUDE_KIND_REQUIRE || $kind === OpCode::INCLUDE_KIND_REQUIRE_ONCE;

                    if (VM\PathSupport::isEmptyPath($file)) {
                        $catchFrame = $this->dispatchVmValueError(
                            new \ValueError(VM\PathSupport::EMPTY_PATH_VALUE_ERROR_MESSAGE),
                            $frame
                        );
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }

                    $resolved = $this->resolveIncludeFilename($file, $frame);
                    if (null === $resolved) {
                        if ($isRequire) {
                            $this->context->errors->triggerError(
                                'Failed opening required \''.$file.'\' for inclusion',
                                VM\ErrorReporter::E_WARNING,
                                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                                $this->context,
                                $frame
                            );
                            $catchFrame = $this->dispatchEngineThrow(
                                $frame,
                                $this->makeEngineError('Failed opening required \''.$file.'\' for inclusion', 'Error')
                            );
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        }
                        $this->context->errors->triggerError(
                            'Failed opening \''.$file.'\' for inclusion',
                            VM\ErrorReporter::E_WARNING,
                            '' !== $frame->scriptPath ? $frame->scriptPath : null,
                            $this->context,
                            $frame
                        );
                        if (null !== $op->arg2 && isset($frame->scope[$op->arg2])) {
                            $frame->scope[$op->arg2]->bool(false);
                        }
                        break;
                    }

                    if ($once && $this->context->isCompileUnitLoaded($resolved)) {
                        if (null !== $op->arg2 && isset($frame->scope[$op->arg2])) {
                            // Zend: include_once/require_once return bool(true) when the file was already included.
                            $frame->scope[$op->arg2]->bool(true);
                        }
                        break;
                    }
                    if ($once) {
                        $this->context->markCompileUnitLoaded($resolved);
                    }
                    $this->context->recordIncludedFile($resolved);
                    $this->context->scriptStack->push($resolved);
                    $parsed = $this->context->runtime->parseAndCompileFile($resolved);
                    $new = $parsed->getFrame($this->context, $frame);
                    $new->ephemeral = true;
                    // Resume the caller via the run stack (like a call); keep $frame as a scope donor only.
                    $new->parent = null;
                    if (null !== $op->arg2) {
                        $new->returnVar = $frame->scope[$op->arg2];
                        $new->returnVar->int(1);
                    }
                    $this->context->push($frame);
                    $frame = $new;
                    goto restart;
                case OpCode::TYPE_YIELD:
                    $gen = $this->findGeneratorState($frame);
                    if (null === $gen) {
                        throw new \LogicException('yield outside generator function');
                    }
                    if (null !== $op->arg2) {
                        if (isset($frame->scope[$op->arg2])) {
                            if ($gen->yieldsByReference()) {
                                $gen->publishCurrentValueByRef($frame->scope[$op->arg2]);
                            } else {
                                $gen->publishCurrentValue($frame->scope[$op->arg2]->resolveIndirect());
                            }
                        } elseif (isset($frame->block->constants[$op->arg2])) {
                            $gen->publishCurrentValue($frame->block->constants[$op->arg2]);
                        } else {
                            $gen->clearCurrentValue();
                        }
                    } else {
                        $gen->currentValue->null();
                        $gen->currentSnapshot->null();
                        $gen->hasCurrent = true;
                    }
                    if (null !== $op->arg3) {
                        if (isset($frame->scope[$op->arg3])) {
                            $gen->currentKey->duplicateFrom($frame->scope[$op->arg3]->resolveIndirect());
                            $gen->noteExplicitYieldKey($gen->currentKey);
                        } elseif (isset($frame->block->constants[$op->arg3])) {
                            $gen->currentKey->duplicateFrom($frame->block->constants[$op->arg3]);
                            $gen->noteExplicitYieldKey($gen->currentKey);
                        } else {
                            $gen->currentKey->int($gen->takeNextAutoKey());
                        }
                    } else {
                        $gen->currentKey->int($gen->takeNextAutoKey());
                    }
                    if (null !== $op->arg1) {
                        $gen->yieldResultSlot = $op->arg1;
                    }
                    if (null === $op->arg2) {
                        $gen->hasCurrent = true;
                    }
                    $gen->frame = $frame;
                    $frame->generatorYield = true;
                    break;
                case OpCode::TYPE_YIELD_FROM:
                    $gen = $this->findGeneratorState($frame);
                    if (null === $gen) {
                        throw new \LogicException('yield from outside generator function');
                    }
                    if (null === $op->arg2 || !isset($frame->scope[$op->arg2])) {
                        throw new \LogicException('yield from missing container operand');
                    }
                    if (!$gen->yieldFromActive) {
                        $container = $frame->scope[$op->arg2]->resolveIndirect();
                        $gen->yieldFromActive = true;
                        $gen->yieldFromIteratorAdvance = false;
                        if (Variable::TYPE_ARRAY === $container->type) {
                            $gen->yieldFromContainer->copyFrom($container);
                            $container->toArray()->iterReset();
                        } elseif ($this->variableIsGenerator($container)) {
                            $gen->yieldFromContainer->copyFrom($container);
                            $container->toObject()->generatorState->rewind();
                        } elseif (Variable::TYPE_OBJECT === $container->type) {
                            if (!$this->yieldFromContainerIsTraversable($container)) {
                                $this->throwYieldFromInvalidContainer($container);
                            }
                            $iterable = VM\ForeachIterator::resolveTraversableObject($this, $frame, $container);
                            $gen->yieldFromContainer->copyFrom($iterable);
                            if ($this->variableIsGenerator($iterable)) {
                                $iterable->toObject()->generatorState->rewind();
                            } else {
                                $this->invokeForeachInstanceMethod($frame, $iterable, 'rewind');
                            }
                        } else {
                            $this->throwYieldFromInvalidContainer($container);
                        }
                    }
                    $container = $gen->yieldFromContainer->resolveIndirect();
                    if (Variable::TYPE_ARRAY === $container->type) {
                        if ($container->toArray()->iterValid()) {
                            $gen->currentKey->copyFrom($container->toArray()->iterCurrentKey());
                            $gen->publishCurrentValue($container->toArray()->iterCurrentValue(false));
                            $gen->frame = $frame;
                            $frame->pos--;
                            $frame->generatorYield = true;
                            break;
                        }
                        $this->completeYieldFromDelegation($gen, $frame, $op, null);
                        break;
                    }
                    if ($this->variableIsGenerator($container)) {
                        $inner = $container->toObject()->generatorState;
                        // Zend yield-from: rewind leaves inner on opening yield; do not advance past it (#23813, #23713).
                        if ($inner->hasCurrent && !$inner->done && !$inner->foreachNeedsAdvance) {
                            $gen->currentKey->copyFrom($inner->currentKey);
                            $gen->publishCurrentValue($inner->currentSnapshot);
                            $inner->foreachNeedsAdvance = true;
                            $gen->frame = $frame;
                            $frame->pos--;
                            $frame->generatorYield = true;
                            break;
                        }
                        if ($this->advanceGeneratorIteration($inner)) {
                            $gen->currentKey->copyFrom($inner->currentKey);
                            $gen->publishCurrentValue($inner->currentSnapshot);
                            $inner->foreachNeedsAdvance = true;
                            $gen->frame = $frame;
                            $frame->pos--;
                            $frame->generatorYield = true;
                            break;
                        }
                        $delegatedReturn = $inner->hasReturned ? $inner->returnValue : null;
                        $this->completeYieldFromDelegation($gen, $frame, $op, $delegatedReturn);
                        break;
                    }
                    if (Variable::TYPE_OBJECT === $container->type) {
                        if ($gen->yieldFromIteratorAdvance) {
                            $this->invokeForeachInstanceMethod($frame, $container, 'next');
                        }
                        $valid = $this->invokeForeachInstanceMethod($frame, $container, 'valid');
                        if ($valid->toBool()) {
                            $gen->currentKey->copyFrom(
                                $this->invokeForeachInstanceMethod($frame, $container, 'key')
                            );
                            $gen->publishCurrentValue(
                                $this->invokeForeachInstanceMethod($frame, $container, 'current')
                            );
                            $gen->yieldFromIteratorAdvance = true;
                            $gen->frame = $frame;
                            $frame->pos--;
                            $frame->generatorYield = true;
                            break;
                        }
                        $this->completeYieldFromDelegation($gen, $frame, $op, null);
                        break;
                    }
                    $this->throwYieldFromInvalidContainer($container);
                case OpCode::TYPE_ITER_RESET:
                    // Zend FE_RESET / CV fetch: Undefined variable E_WARNING before type check (#26148).
                    $container = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg1)->resolveIndirect();
                    unset($this->context->foreachInvalidSlots[$op->arg1]);
                    if ($this->variableIsGenerator($container)) {
                        unset($this->context->foreachObjectAdvance[$op->arg1]);
                        unset($this->context->objectPropertyIterators[$op->arg1]);
                        unset($this->context->weakMapIterators[$op->arg1]);
                        $frame->iterators[$op->arg1] = $container;
                        $this->context->foreachIterators[$op->arg1] = $container;
                        try {
                            $container->toObject()->generatorState->rewindForForeach();
                        } catch (\Exception $e) {
                            $catchFrame = $this->dispatchVmEngineException($e->getMessage(), $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                        }
                        break;
                    }
                    if (Variable::TYPE_ARRAY === $container->type) {
                        unset($this->context->foreachObjectAdvance[$op->arg1]);
                        unset($this->context->objectPropertyIterators[$op->arg1]);
                        unset($this->context->weakMapIterators[$op->arg1]);
                        $this->bindArrayForeachIteratorContainer($frame, (int) $op->arg1, $container);
                        break;
                    }
                    if (Variable::TYPE_OBJECT === $container->type) {
                        try {
                            unset($this->context->objectPropertyIterators[$op->arg1]);
                            unset($this->context->weakMapIterators[$op->arg1]);
                            $iterable = VM\ForeachIterator::resolveTraversableObject($this, $frame, $container);
                            $frame->iterators[$op->arg1] = $iterable;
                            $this->context->foreachIterators[$op->arg1] = $iterable;
                            if ($this->variableIsGenerator($iterable)) {
                                unset($this->context->foreachObjectAdvance[$op->arg1]);
                                try {
                                    $iterable->toObject()->generatorState->rewindForForeach();
                                } catch (\Exception $e) {
                                    $catchFrame = $this->dispatchVmEngineException($e->getMessage(), $frame);
                                    if (null !== $catchFrame) {
                                        $frame = $catchFrame;
                                        goto restart;
                                    }
                                }
                                break;
                            }
                            $this->context->foreachObjectAdvance[$op->arg1] = false;
                            $this->invokeForeachInstanceMethod($frame, $iterable, 'rewind');
                            break;
                        } catch (\TypeError $e) {
                            // Property-foreach fallback only for "not iterable" (#3234).
                            // Return-type / other TypeErrors from getIterator() must reach userland (#19729).
                            if (!str_contains($e->getMessage(), 'is not iterable')) {
                                $catchFrame = $this->dispatchVmTypeError($e, $frame);
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }
                                break;
                            }
                            unset($this->context->foreachObjectAdvance[$op->arg1]);
                            if (WeakRefSupport::isWeakMap($container->toObject())) {
                                unset($this->context->objectPropertyIterators[$op->arg1]);
                                unset($this->context->weakMapIterators[$op->arg1]);
                                $iter = new WeakMapIterator($container->toObject());
                                $iter->reset();
                                $this->context->weakMapIterators[$op->arg1] = $iter;
                                break;
                            }
                            $iter = new ObjectPropertyIterator($container->toObject(), $this, $frame);
                            $iter->reset();
                            $this->context->objectPropertyIterators[$op->arg1] = $iter;
                            break;
                        } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
                            // Iterator protocol throw (FilterIterator::accept, …) — do not re-wrap (#24286).
                            $frame = $this->resumeAfterBuiltinCallbackCatchRedirect($redirect);
                            goto restart;
                        } catch (\Exception $e) {
                            // zend_interfaces.c — bad getIterator() return is Exception, not TypeError (#19729).
                            $catchFrame = $this->dispatchVmEngineException($e->getMessage(), $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        }
                    }
                    $this->warnForeachNonTraversable($container, $frame, $op);
                    unset($this->context->foreachObjectAdvance[$op->arg1]);
                    unset($this->context->objectPropertyIterators[$op->arg1]);
                    unset($this->context->weakMapIterators[$op->arg1]);
                    unset($this->context->foreachIterators[$op->arg1]);
                    unset($frame->iterators[$op->arg1]);
                    $this->context->foreachInvalidSlots[$op->arg1] = true;
                    break;
                case OpCode::TYPE_ITER_VALID:
                    if ($this->isForeachInvalidSlot((int) $op->arg2)) {
                        $frame->scope[$op->arg1]->bool(false);
                        break;
                    }
                    $container = $this->resolveForeachContainer($frame, (int) $op->arg2);
                    if ($this->isForeachObjectIteratorSlot((int) $op->arg2)) {
                        if ($this->context->foreachObjectAdvance[$op->arg2]) {
                            $this->invokeForeachInstanceMethod($frame, $container, 'next');
                        }
                        $valid = $this->invokeForeachInstanceMethod($frame, $container, 'valid');
                        $frame->scope[$op->arg1]->bool($valid->toBool());
                        break;
                    }
                    if ($this->variableIsGenerator($container)) {
                        $catchFrame = $this->foreachAdvanceGenerator(
                            $frame,
                            $container->toObject()->generatorState,
                            (int) $op->arg1
                        );
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    if (Variable::TYPE_OBJECT === $container->type) {
                        if ($this->isWeakMapForeachSlot((int) $op->arg2)) {
                            $frame->scope[$op->arg1]->bool(
                                $this->weakMapForeachIterator($op->arg2)->valid()
                            );
                            break;
                        }
                        $frame->scope[$op->arg1]->bool(
                            $this->objectForeachIterator($op->arg2)->valid()
                        );
                        break;
                    }
                    if (Variable::TYPE_ARRAY !== $container->type) {
                        // Literal scalars re-embed per block (RESET slot ≠ VALID slot), so
                        // foreachInvalidSlots from FE_RESET is missed — treat as empty (#23452).
                        $frame->scope[$op->arg1]->bool(false);
                        break;
                    }
                    $frame->scope[$op->arg1]->bool($container->toArray()->iterValid());
                    break;
                case OpCode::TYPE_ITER_KEY:
                    if ($this->isForeachInvalidSlot((int) $op->arg2)) {
                        break;
                    }
                    $container = $this->resolveForeachContainer($frame, (int) $op->arg2);
                    if ($this->isForeachObjectIteratorSlot((int) $op->arg2)) {
                        $key = $this->invokeForeachInstanceMethod($frame, $container, 'key');
                        $frame->scope[$op->arg1]->copyFrom($key);
                        break;
                    }
                    if ($this->variableIsGenerator($container)) {
                        $frame->scope[$op->arg1]->copyFrom(
                            $container->toObject()->generatorState->currentKey
                        );
                        break;
                    }
                    if (Variable::TYPE_OBJECT === $container->type) {
                        if ($this->isWeakMapForeachSlot((int) $op->arg2)) {
                            $frame->scope[$op->arg1]->copyFrom(
                                $this->weakMapForeachIterator($op->arg2)->currentKey()
                            );
                            break;
                        }
                        $frame->scope[$op->arg1]->copyFrom(
                            $this->objectForeachIterator($op->arg2)->currentKey()
                        );
                        break;
                    }
                    if (Variable::TYPE_ARRAY !== $container->type) {
                        // Non-traversable: FE_RESET warned; no key fetch (#23452 / zend_vm_def.h).
                        break;
                    }
                    $frame->scope[$op->arg1]->copyFrom($container->toArray()->iterCurrentKey());
                    break;
                case OpCode::TYPE_ITER_VALUE:
                    if ($this->isForeachInvalidSlot((int) $op->arg2)) {
                        break;
                    }
                    $container = $this->resolveForeachContainer($frame, (int) $op->arg2);
                    if ($this->isForeachObjectIteratorSlot((int) $op->arg2)) {
                        if ((bool) $op->arg3) {
                            // Zend FE_RESET_RW allow-list: array-backed SPL iterators (#19444).
                            $iterObj = $container->toObject();
                            if (SplArrayStorage::allowsForeachByRef($iterObj)) {
                                $frame->scope[$op->arg1]->indirect(
                                    SplArrayStorage::foreachCurrentByRef($iterObj)
                                );
                                $this->markScopeSlotInitialized($frame, (int) $op->arg1);
                                $this->context->foreachObjectAdvance[$op->arg2] = true;
                                break;
                            }
                            if (RecursiveArrayIteratorBuiltin::allowsForeachByRef($iterObj)) {
                                $frame->scope[$op->arg1]->indirect(
                                    RecursiveArrayIteratorBuiltin::foreachCurrentByRef($iterObj)
                                );
                                $this->markScopeSlotInitialized($frame, (int) $op->arg1);
                                $this->context->foreachObjectAdvance[$op->arg2] = true;
                                break;
                            }
                            $catchFrame = $this->dispatchVmError(
                                'An iterator cannot be used with foreach by reference',
                                $frame
                            );
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        }
                        $value = $this->invokeForeachInstanceMethod($frame, $container, 'current');
                        $frame->scope[$op->arg1]->copyFrom($value);
                        $this->context->foreachObjectAdvance[$op->arg2] = true;
                        break;
                    }
                    if ($this->variableIsGenerator($container)) {
                        if ((bool) $op->arg3) {
                            $genState = $container->toObject()->generatorState;
                            if (!$genState->yieldsByReference()) {
                                $catchFrame = $this->dispatchVmEngineException(
                                    \PHPCompiler\JIT\GeneratorHelper::FOREACH_GENERATOR_BYREF_ERROR,
                                    $frame
                                );
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }
                                break;
                            }
                            $frame->scope[$op->arg1]->indirect(
                                $genState->currentValue->byRefTarget()
                            );
                            $this->markScopeSlotInitialized($frame, (int) $op->arg1);
                            break;
                        }
                        $frame->scope[$op->arg1]->copyFrom(
                            $container->toObject()->generatorState->currentValue
                        );
                        break;
                    }
                    if (Variable::TYPE_OBJECT === $container->type) {
                        $byRef = (bool) $op->arg3;
                        if ($this->isWeakMapForeachSlot((int) $op->arg2)) {
                            $iter = $this->weakMapForeachIterator($op->arg2);
                            if ($byRef) {
                                $frame->scope[$op->arg1]->indirect($iter->currentValue(true));
                                $this->markScopeSlotInitialized($frame, (int) $op->arg1);
                            } else {
                                $frame->scope[$op->arg1]->assignForeachByValue($iter->currentValue(false));
                            }
                            break;
                        }
                        if ($byRef) {
                            try {
                                $frame->scope[$op->arg1]->indirect(
                                    $this->objectForeachIterator($op->arg2)->currentValue(true)
                                );
                            } catch (VM\PropertyHookRefWriteSignal $signal) {
                                $frame = $signal->catchFrame;
                                goto restart;
                            }
                            $this->markScopeSlotInitialized($frame, (int) $op->arg1);
                        } else {
                            try {
                                $frame->scope[$op->arg1]->assignForeachByValue(
                                    $this->objectForeachIterator($op->arg2)->currentValue(false)
                                );
                            } catch (VM\PropertyHookRefWriteSignal $signal) {
                                $frame = $signal->catchFrame;
                                goto restart;
                            }
                        }
                        break;
                    }
                    if (Variable::TYPE_ARRAY !== $container->type) {
                        // Non-traversable: FE_RESET warned; no value fetch (#23452 / zend_vm_def.h).
                        break;
                    }
                    $byRef = (bool) $op->arg3;
                    if ($byRef) {
                        $this->rebindArrayForeachToLiveContainer($frame, (int) $op->arg2);
                        $container = $this->resolveForeachContainer($frame, (int) $op->arg2);
                        $frame->scope[$op->arg1]->indirect(
                            $container->toArray()->iterCurrentValue(true)
                        );
                        $this->markScopeSlotInitialized($frame, (int) $op->arg1);
                    } else {
                        $frame->scope[$op->arg1]->assignForeachByValue(
                            $container->toArray()->iterCurrentValue(false)
                        );
                    }
                    break;
                case OpCode::TYPE_TRY:
                    $this->context->activeTryHandlerFrames[] = $frame;
                    // Loop re-entry reuses the handler frame object; clear stale "finally done"
                    // so break/continue unwind can run finally again (#25240).
                    unset($this->context->completedFinallyHandlers[spl_object_id($frame)]);
                    if (null !== $op->block2) {
                        $this->context->tryMergeBlockIds[spl_object_id($op->block2)] = true;
                    }
                    // php-cfg may fuse try body with merge when try is only `goto` to a later label (#4491).
                    if (
                        null !== $op->block2
                        && $op->block1 === $op->block2
                        && $this->hasPendingFinally($frame)
                    ) {
                        $this->context->pendingGotoAfterFinally = $op->block1;
                        $finallyFrame = $this->enterFinallyHandlerForUnwind($frame, false);
                        if (null !== $finallyFrame) {
                            $frame = $finallyFrame;
                            goto restart;
                        }
                    }
                    $frame = $op->block1->getFrame($this->context, $frame);
                    goto restart;
                case OpCode::TYPE_CATCH:
                    if (null !== $this->context->pendingException) {
                        if ($this->catchTypesMatch($op, $this->context->pendingException)) {
                            $caught = $this->context->pendingException;
                            $this->context->pendingException = null;
                            if (null !== $op->arg3) {
                                if (!isset($frame->scope[$op->arg3])) {
                                    $frame->scope[$op->arg3] = new Variable();
                                }
                                $frame->scope[$op->arg3]->copyFrom($caught);
                            }
                            $frame = $op->block1->getFrame($this->context, $frame);
                            $this->bindCatchVariableToFrame($frame, $op->arg3, $caught);
                            goto restart;
                        }
                        break;
                    }
                    if (null !== $op->block2) {
                        $frame = $op->block2->getFrame($this->context, $frame);
                        goto restart;
                    }
                    break;
                case OpCode::TYPE_FINALLY:
                    if (null !== $this->context->pendingException) {
                        break;
                    }
                    if (null !== $op->block1) {
                        $frame = $op->block1->getFrame($this->context, $frame);
                        goto restart;
                    }
                    break;
                case OpCode::TYPE_THROW:
                    $thrown = $frame->scope[$op->arg1]->resolveIndirect();
                    if (null !== $op->arg2) {
                        VM\ExceptionSupport::stampThrowLine($thrown, (int) $op->arg2);
                    }
                    // External catch during __clone throws CloneMagicCatchRedirect from
                    // findCatchFrameForThrow (#23527 / #12068). Local try/catch inside __clone
                    // falls through to the normal dispatchEngineThrow path below.
                    if ($this->frameIsPropertyGetHook($frame)) {
                        $catchFrame = $this->dispatchEngineThrow($frame, $thrown);
                        if (null !== $catchFrame) {
                            // Bubble to caller stack — do not finish property read (#9503, zend_property_hooks.c).
                            $this->context->propertyHookExternalCatchFrame = $catchFrame;

                            return self::FAILURE;
                        }
                        break;
                    }
                    if ($this->frameIsPropertyUnsetHook($frame)) {
                        $catchFrame = $this->dispatchEngineThrow($frame, $thrown);
                        if (null !== $catchFrame) {
                            // Bubble to caller stack — do not finish unset (#9666, zend_property_hooks.c).
                            $this->context->propertyHookExternalCatchFrame = $catchFrame;

                            return self::FAILURE;
                        }
                        break;
                    }
                    if ($this->frameIsPropertySetHook($frame)) {
                        $catchFrame = $this->dispatchEngineThrow($frame, $thrown);
                        if (null !== $catchFrame) {
                            // Bubble to caller stack — do not finish assignment (#9670, zend_property_hooks.c).
                            $this->context->propertyHookExternalCatchFrame = $catchFrame;
                            $this->context->propertyHookSetAborted = true;

                            return self::FAILURE;
                        }
                        break;
                    }
                    $catchFrame = $this->dispatchEngineThrow($frame, $thrown);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    break;
                case OpCode::TYPE_RETHROW:
                    $thrown = $this->resolveActiveCatchException($frame);
                    if (null === $thrown) {
                        throw new \LogicException('Cannot use "throw;" outside of a catch block');
                    }
                    $catchFrame = $this->dispatchEngineThrow($frame, $thrown);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    break;
                case OpCode::TYPE_TICK_SCOPE_ENTER:
                    $this->context->tickIntervalStack[] = $this->context->tickInterval;
                    $this->context->tickInterval = max(0, (int) $op->arg1);
                    $this->context->tickCounter = $this->context->tickInterval > 0
                        ? $this->context->tickInterval
                        : 0;
                    break;
                case OpCode::TYPE_TICK_SCOPE_SET:
                    $this->context->tickInterval = max(0, (int) $op->arg1);
                    $this->context->tickCounter = $this->context->tickInterval > 0
                        ? $this->context->tickInterval
                        : 0;
                    break;
                case OpCode::TYPE_TICK_SCOPE_LEAVE:
                    if ([] !== $this->context->tickIntervalStack) {
                        $this->context->tickInterval = array_pop($this->context->tickIntervalStack);
                    } else {
                        $this->context->tickInterval = 0;
                    }
                    $this->context->tickCounter = $this->context->tickInterval > 0
                        ? $this->context->tickInterval
                        : 0;
                    break;
                case OpCode::TYPE_TICKS:
                    $this->maybeRunTick();
                    break;
                default:
                    throw new \LogicException("VM OpCode Not Implemented: " . opcode_type_name($op->type));
                }
            } catch (TypedPropertyReadSignal $signal) {
                $catchFrame = $this->dispatchEngineThrow($frame, $signal->errorObject);
                if (null !== $catchFrame) {
                    $frame = $catchFrame;
                    goto restart;
                }

                return self::FAILURE;
            } catch (VM\PropertyHookRefWriteSignal $signal) {
                $frame = $signal->catchFrame;
                goto restart;
            } catch (VM\PropertyHookFiberSuspendSignal $signal) {
                $fiber = $this->context->currentFiber;
                if (null !== $fiber) {
                    $fiber->propertyHookSuspendFrame = $fiber->frame;
                    $fiber->frame = $signal->resumeFrame;
                }
                // pos is pre-incremented at loop head; re-run the property fetch on resume (#9862).
                if ($signal->resumeFrame->pos > 0) {
                    --$signal->resumeFrame->pos;
                }

                return self::FIBER_SUSPEND;
            } catch (VM\ArrayAccessOffsetSignal $signal) {
                $frame = $signal->catchFrame;
                goto restart;
            } catch (VM\DestructorThrowCatchSignal $signal) {
                if ($this->context->isolatedDestructorInvoke) {
                    throw $signal;
                }
                $frame = $signal->catchFrame;
                goto restart;
            } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
                if (
                    $this->context->deferBuiltinCallbackCatchToOuterRunFrames
                    || null !== $this->context->deferCatchBelowTryHandlerDepth
                ) {
                    throw $redirect;
                }
                $frame = $this->resumeAfterBuiltinCallbackCatchRedirect($redirect);
                goto restart;
            } catch (VM\CloneMagicCatchRedirect $redirect) {
                // Isolated __clone stack: abort nested runFrames; clone opcode resumes outer catch (#23527).
                $this->context->cloneMagicExternalCatchFrame = $redirect->catchFrame;

                return self::FAILURE;
            }
            if ($this->shouldAbortPropertyHookInvocation($frame)) {
                return self::FAILURE;
            }
            if ($frame->generatorYield) {
                $frame->generatorYield = false;

                return self::GENERATOR_YIELD;
            }
            if ($frame->fiberSuspend) {
                $frame->fiberSuspend = false;
                $frame->call = null;
                $this->clearOutgoingCallState($frame);
                $this->restorePendingOutboundCallAfterInlineNew($frame);

                return self::FIBER_SUSPEND;
            }
        }
        if ($frame->ephemeral) {
            $this->context->scriptStack->pop();
            if (null !== $frame->parent) {
                $frame = $this->resumeEphemeralCallerFrame($frame);
                goto restart;
            }
            $this->releaseFrameObjectRefs($frame);
            goto nextframe;
        }
        if ([] !== $this->context->deferredTraitUses) {
            $this->finalizeDeferredTraitUses();
        }
        if ([] !== $this->context->deferredClassConstants) {
            $this->finalizeAllDeferredClassConstants();
        }
        if ([] !== $this->context->deferredParentInheritance) {
            try {
                $this->finalizeDeferredParentInheritance($frame);
            } catch (\CompileError $deferredCompileError) {
                $this->raiseClassDeclareCompileFatal($deferredCompileError, $frame);
            } catch (\Error $deferredParentError) {
                $catchFrame = $this->dispatchVmError($deferredParentError->getMessage(), $frame);
                if (null !== $catchFrame) {
                    $frame = $catchFrame;
                    goto restart;
                }

                return self::FAIL;
            }
        }

        return self::SUCCESS;

        return_void_complete:
        if ($frame->ephemeral) {
            $this->context->scriptStack->pop();
        }
        try {
            $this->enforceReturnType($frame, null);
        } catch (\TypeError $e) {
            $catchFrame = $this->dispatchVmTypeError($e, $frame);
            if (null !== $catchFrame) {
                $frame = $catchFrame;
                goto restart;
            }
            return self::FAIL;
        } catch (\Error $e) {
            $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
            if (null !== $catchFrame) {
                $frame = $catchFrame;
                goto restart;
            }
            return self::FAIL;
        }
        // Do not null returnVar: it may alias the caller result slot (#1885).
        $this->markObjectConstructedIfLeavingConstruct($frame);
        $gen = $this->findGeneratorState($frame);
        if (null !== $gen) {
            $gen->markReturned(null);
            $this->releaseFrameObjectRefs($frame);
            goto nextframe;
        }
        if ($frame->ephemeral && null !== $frame->parent) {
            $frame = $this->resumeEphemeralCallerFrame($frame);
            goto restart;
        }
        // Match return_value_complete: clear caller callSiteLine so later opcodes
        // (readonly property writes, etc.) do not cite the prior call (#25556, #21953).
        $callee = $frame;
        $caller = $this->context->pop();
        $this->releaseFrameObjectRefs($callee);
        if (null !== $caller) {
            $this->clearOutgoingCallState($caller);
            $this->restorePendingOutboundCallAfterInlineNew($caller);
            $frame = $caller;
            goto restart;
        }

        return self::SUCCESS;

        return_value_complete:
        if ($frame->ephemeral) {
            $this->context->scriptStack->pop();
        }
        try {
            $this->enforceReturnType($frame, $returnValue);
        } catch (\TypeError $e) {
            $catchFrame = $this->dispatchVmTypeError($e, $frame);
            if (null !== $catchFrame) {
                $frame = $catchFrame;
                goto restart;
            }
            return self::FAIL;
        }
        $gen = $this->findGeneratorState($frame);
        if (null !== $gen) {
            $gen->markReturned($returnValue);
            $this->markObjectConstructedIfLeavingConstruct($frame);
            goto nextframe;
        }
        if (!is_null($frame->returnVar)) {
            if ($this->functionReturnsByRef($frame)) {
                $frame->returnVar->indirect($returnValue);
            } else {
                $frame->returnVar->copyFrom($returnValue);
            }
        }
        $this->markObjectConstructedIfLeavingConstruct($frame);
        $callee = $frame;
        $caller = $this->context->pop();
        $this->releaseFrameObjectRefs($callee);
        if (null !== $caller) {
            $this->clearOutgoingCallState($caller);
            $frame = $caller;
            goto restart;
        }
        // Nested return <call>(): callee may finish with an empty run stack (#1885).
        if (null !== $frame->parent && null !== $frame->returnVar) {
            if ($this->isFunctionStaticInitContinueReturn($frame)) {
                $entry = $frame->parent;
                if (null !== $entry->returnVar) {
                    $entry->returnVar->copyFrom($returnValue);
                }
                $this->releaseFrameObjectRefs($frame);
                $caller = $this->context->pop();
                if (null !== $caller) {
                    $this->clearOutgoingCallState($caller);
                    $frame = $caller;
                    goto restart;
                }

                return self::SUCCESS;
            }
            // Property hooks run via swapRunStack(null); parent is only for static-init
            // continue detection — must not resume the caller frame here (#7097, #7108).
            if (null !== $frame->propertyHookRawProperty) {
                return self::SUCCESS;
            }
            $child = $frame;
            $frame = $frame->parent;
            $this->releaseFrameObjectRefs($child);
            goto restart;
        }
        if ($frame->ephemeral && null !== $frame->parent) {
            $frame = $this->resumeEphemeralCallerFrame($frame);
            goto restart;
        }

        return self::SUCCESS;
    }

    /**
     * Resume the caller after an ephemeral child (constructor, etc.) finishes.
     */
    private function resumeEphemeralCallerFrame(Frame $child): Frame
    {
        $this->markObjectConstructedIfLeavingConstruct($child);
        $caller = $child->parent;
        if (null === $caller) {
            return $child;
        }
        $caller->call = null;
        $this->clearOutgoingCallState($caller);
        $this->restorePendingOutboundCallAfterInlineNew($caller);
        $this->releaseFrameObjectRefs($child);

        return $caller;
    }

    /**
     * Goto / label back-edges reuse the innermost frame for the target block (#1228).
     * php-cfg lowers `if (cond) goto L` as JumpIf to the label block; naive getFrame()
     * nests a new frame per iteration and never terminates on merge blocks.
     */
    /**
     * Runtime-init function static: continue block return must not resume the entry
     * frame at TYPE_FUNCTION_STATIC_INIT_STORE (#7097, property hook dispatch).
     */
    private function isFunctionStaticInitContinueReturn(Frame $continueFrame): bool
    {
        $entry = $continueFrame->parent;
        if (null === $entry || $entry->pos < 1) {
            return false;
        }
        $prev = $entry->block->opCodes[$entry->pos - 1] ?? null;
        if (null === $prev || OpCode::TYPE_JUMPIF_FUNCTION_STATIC_INITIALIZED !== $prev->type) {
            return false;
        }

        return $prev->block1 === $continueFrame->block;
    }

    private function frameForBranch(Frame $frame, Block $target): Frame
    {
        if ($target === $frame->block) {
            while (null !== $frame->parent && $frame->parent->block === $target) {
                $frame = $frame->parent;
            }
            $frame->pos = 0;

            return $frame;
        }

        return $target->getFrame($this->context, $frame);
    }

    /** Zend compile-time fatal if $this is written; runtime guard when compile missed (#4865). */
    private function dispatchThisReassignFatalIfNeeded(Frame $frame, int $writeSlot): ?Frame
    {
        $func = $frame->block->func;
        if (null === $func || null === $func->class) {
            return null;
        }
        $thisIdx = $frame->block->slotIndexForVariableName('this');
        if (null === $thisIdx || $writeSlot !== $thisIdx) {
            return null;
        }

        return $this->dispatchVmError('Cannot re-assign $this', $frame);
    }

    /**
     * isset($this) / empty($this) in static or non-object scope — false / true without Error (#5411).
     */
    private function isUnboundThisSlot(Frame $frame, int $slot): bool
    {
        $thisIdx = $frame->block->slotIndexForVariableName('this');
        if (null === $thisIdx || $thisIdx !== $slot) {
            return false;
        }
        $func = $frame->block->func;
        if (null === $func) {
            return false;
        }
        // Static methods and static closures never have $this (zend_closures.c / #23704).
        if ((($func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) !== 0) {
            return true;
        }
        // Closures only have $this when auto-bound / bindTo supplied an object.
        // Scope class may still be set (created inside a method) while $this is NULL —
        // static-method-created free closures (#28814) and top-level arrows (#10558).
        // TYPE_UNDEFINED in scope must not count as bound (getFrame leaves that sentinel).
        if (((int) ($func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE) !== 0) {
            return !$this->closureFrameHasBoundThis($frame, $thisIdx);
        }
        if (null === $func->class) {
            return false;
        }

        return !isset($frame->scope[$thisIdx]);
    }

    /** True when a closure invoke has a bound object for $this (auto-bind / bindTo). */
    private function closureFrameHasBoundThis(Frame $frame, int $thisIdx): bool
    {
        if (isset($frame->scope[$thisIdx])) {
            $var = $frame->scope[$thisIdx]->resolveIndirect();
            if (Variable::TYPE_OBJECT === $var->type) {
                return true;
            }
        }
        $closureState = $frame->closureCall ?? $frame->pendingClosureInvoke;
        if (null !== $closureState && null !== $closureState->boundThis) {
            return true;
        }

        return false;
    }

    /** Runtime Error when $this is evaluated outside object context (not isset/empty). */
    private function guardUnboundThisRead(Frame $frame, int $slot): ?Frame
    {
        if (!$this->isUnboundThisSlot($frame, $slot)) {
            return null;
        }

        return $this->dispatchVmError('Using $this when not in object context', $frame);
    }

    /**
     * Pre/post increment/decrement with Zend bool→int coercion (#4727, #3552).
     * Rejects ++/-- on readonly properties after construction (#3149).
     * Inaccessible / overloaded props RMW via __get then __set (#25687, zend_object_handlers.c).
     */
    private function executeIncDec(Frame $frame, OpCode $op, bool $increment, bool $prefix): ?Frame
    {
        $catchFrame = $this->dispatchThisReassignFatalIfNeeded($frame, $op->arg3);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $read = $frame->scope[$op->arg2];
        $write = $frame->scope[$op->arg3];
        $result = $frame->scope[$op->arg1];
        $catchFrame = $this->enforceReadonlyPropertyWrite($write, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $catchFrame = $this->enforceFinalPropertyWrite($write, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $catchFrame = $this->enforceAsymmetricPropertyWrite($write, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $catchFrame = $this->enforceVirtualPropertyHookWrite($write, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $magicCatch = $this->executeMagicOverloadedPropertyIncDec(
            $frame,
            $read,
            $write,
            $result,
            $increment,
            $prefix
        );
        if (false !== $magicCatch) {
            return $magicCatch;
        }
        $this->warnUndefinedVariableForIncDecRead($frame, $op, $read, $write);
        $resolvedRead = $read->resolveIndirect();
        $hookedRead = Variable::TYPE_ARRAY === $resolvedRead->type
            ? null
            : $this->fetchHookedPropertyValueForIncDec($write, $frame);
        if (null === $hookedRead && Variable::TYPE_ARRAY !== $resolvedRead->type) {
            $catchFrame = $this->enforceWriteOnlyVirtualPropertyReadForLvalue($write, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
        }
        if (null !== $hookedRead) {
            return $this->executeHookedPropertyIncDec(
                $frame,
                $hookedRead,
                $write,
                $result,
                $increment,
                $prefix
            );
        }
        if (Variable::TYPE_STRING_OFFSET === $write->resolveIndirect()->type) {
            return $this->dispatchVmError(Variable::STRING_OFFSET_INCDEC_ERROR, $frame);
        }
        $working = new Variable();
        $working->copyFrom($read->resolveIndirect());
        try {
            if ($prefix) {
                $before = new Variable();
                $before->copyFrom($working);
                if ($increment) {
                    $working->applyIncrement($this, $frame);
                } else {
                    $working->applyDecrement($this, $frame);
                }
                // Typed int property: keep MAX/MIN and TypeError (zend_execute.c, #29144).
                VmIncDec::throwIfTypedPropertyRejectsOverflow($write, $before, $working, $increment);
                $write->copyFrom($working);
                $result->copyFrom($working);
            } else {
                $old = new Variable();
                $old->copyFrom($working);
                if ($increment) {
                    $working->applyIncrement($this, $frame);
                } else {
                    $working->applyDecrement($this, $frame);
                }
                VmIncDec::throwIfTypedPropertyRejectsOverflow($write, $old, $working, $increment);
                $write->copyFrom($working);
                $result->copyFrom($old);
            }
        } catch (\TypeError $e) {
            return $this->dispatchVmTypeError($e, $frame);
        } catch (\Error $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }

        $this->markScopeSlotInitialized($frame, (int) $op->arg3);

        return null;
    }

    /**
     * ++/-- on undeclared or inaccessible props: __get then __set (zend_std_*_property; #25687).
     *
     * @return null|Frame|false null on success, Frame on catch, false when not a magic RMW lvalue
     */
    private function executeMagicOverloadedPropertyIncDec(
        Frame $frame,
        Variable $read,
        Variable $write,
        Variable $result,
        bool $increment,
        bool $prefix
    ): null|Frame|false {
        $owner = $this->resolvePropertyWriteOwner($write);
        $propName = $this->resolvePropertyWriteName($write);
        if (null === $owner || null === $propName) {
            return false;
        }
        $resolvedWrite = $write->resolveIndirect();
        $isMagicSetProxy = null !== $resolvedWrite->magicSetTarget && null !== $resolvedWrite->magicSetName;
        $readUsesMagic = $this->propertyReadUsesMagicGet($owner, $propName, $frame);
        $writeUsesMagic = $this->propertyWriteUsesMagicSet($owner, $propName, $frame);
        $meta = $this->classPropertyMeta($owner, $propName, $frame);
        $declaredInaccessible = null !== $meta && (
            $this->declaredPropertyInaccessibleFromCaller($owner, $meta, $propName, $frame, $meta->getVisibility)
            || $this->declaredPropertyInaccessibleFromCaller($owner, $meta, $propName, $frame, 0)
        );
        if (!$isMagicSetProxy && !$declaredInaccessible && !$readUsesMagic && !$writeUsesMagic) {
            return false;
        }
        // Accessible declared slot — keep normal in-place mutate even if __get/__set exist.
        if (null !== $meta && !$declaredInaccessible && !$isMagicSetProxy) {
            return false;
        }

        $working = new Variable();
        if ($readUsesMagic) {
            $working->copyFrom($this->invokeMagicGet($owner, $propName));
        } elseif ($declaredInaccessible || $isMagicSetProxy) {
            // Inaccessible / overloaded without __get: Error (do not read the private slot).
            $catchFrame = $this->enforcePropertyVisibilityRead($owner, $propName, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            // Undeclared without __get: fall back to the fetched value (undefined → null/warn elsewhere).
            $working->copyFrom($read->resolveIndirect());
        } else {
            $working->copyFrom($read->resolveIndirect());
        }

        try {
            if ($prefix) {
                $before = new Variable();
                $before->copyFrom($working);
                if ($increment) {
                    $working->applyIncrement($this, $frame);
                } else {
                    $working->applyDecrement($this, $frame);
                }
                if (!$writeUsesMagic) {
                    VmIncDec::throwIfTypedPropertyRejectsOverflow($write, $before, $working, $increment);
                }
                if ($writeUsesMagic) {
                    $this->invokeMagicSet($owner, $propName, $working);
                } else {
                    $catchFrame = $this->enforcePropertyVisibilityWrite($write, $frame);
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                    // Declared inaccessible without __set — visibility Error via owner metadata.
                    $catchFrame = $this->enforcePropertyWriteVisibility($owner, $propName, $frame);
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                    $write->copyFrom($working);
                }
                $result->copyFrom($working);
            } else {
                $old = new Variable();
                $old->copyFrom($working);
                if ($increment) {
                    $working->applyIncrement($this, $frame);
                } else {
                    $working->applyDecrement($this, $frame);
                }
                if (!$writeUsesMagic) {
                    VmIncDec::throwIfTypedPropertyRejectsOverflow($write, $old, $working, $increment);
                }
                if ($writeUsesMagic) {
                    $this->invokeMagicSet($owner, $propName, $working);
                } else {
                    $catchFrame = $this->enforcePropertyVisibilityWrite($write, $frame);
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                    $catchFrame = $this->enforcePropertyWriteVisibility($owner, $propName, $frame);
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                    $write->copyFrom($working);
                }
                $result->copyFrom($old);
            }
        } catch (\TypeError $e) {
            return $this->dispatchVmTypeError($e, $frame);
        } catch (\Error $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }

        return null;
    }

    /**
     * Zend E_WARNING when ++/-- reads an unbound CV (zend_variables.c, issue #6800).
     */
    private function warnUndefinedVariableForIncDecRead(
        Frame $frame,
        OpCode $op,
        Variable $read,
        Variable $write
    ): void {
        if (!$this->isSimpleVariableIncDecLvalue($write)) {
            return;
        }
        if (!$this->isUnboundVariableIncDecRead($frame, $op, $read)) {
            return;
        }
        $name = $this->resolveScopeSlotVariableName($frame, (int) $op->arg2)
            ?? $this->resolveScopeSlotVariableName($frame, (int) $op->arg3);
        if (null === $name) {
            return;
        }
        $this->context->errors->undefinedVariable(
            $name,
            $this->context,
            $frame,
            '' !== $frame->scriptPath ? $frame->scriptPath : null
        );
    }

    private function isUnboundVariableIncDecRead(Frame $frame, OpCode $op, Variable $read): bool
    {
        $resolved = $read->resolveIndirect();
        if ($resolved->isUndefined()) {
            return true;
        }
        $globalName = $this->context->globalNameForStorage($resolved);
        if (null !== $globalName) {
            return !$this->context->isGlobalEverAssigned($globalName);
        }
        $staticKey = $this->context->functionStaticKeyForStorage($resolved);
        if (null !== $staticKey) {
            return !$this->isFunctionStaticInitializedForFrame($frame, $staticKey);
        }

        return !isset($frame->initializedSlots[(int) $op->arg2]);
    }

    /**
     * Zend E_WARNING when a user-function local is read before assignment (#5454).
     */
    private function warnUndefinedVariableForScopeRead(Frame $frame, int $slot): void
    {
        if (!$this->isUnboundLocalScopeRead($frame, $slot)) {
            return;
        }
        $name = $this->resolveScopeSlotVariableName($frame, $slot);
        if (null === $name) {
            return;
        }
        $this->context->errors->undefinedVariable(
            $name,
            $this->context,
            $frame,
            '' !== $frame->scriptPath ? $frame->scriptPath : null
        );
    }

    /** Zend ZEND_CHECK_UNDEFINED_VAR on scope slot reads (casts/unary/binary/?? RHS, #10358). */
    private function guardUndefinedVariableScopeReadSlot(Frame $frame, int $slot): void
    {
        if (isset($frame->block->constants[$slot])) {
            return;
        }
        $this->warnUndefinedVariableForScopeRead($frame, $slot);
    }

    /**
     * Scope operand for value reads — warn then treat unbound TYPE_UNDEFINED as null (Zend, #10358).
     */
    private function readScopeOperandForRuntimeRead(Frame $frame, int $slot): Variable
    {
        $this->guardUndefinedVariableScopeReadSlot($frame, $slot);
        $operand = $frame->scope[$slot];
        if ($this->isUnboundLocalScopeRead($frame, $slot)) {
            $resolved = $operand->resolveIndirect();
            if ($resolved->isUndefined()) {
                $null = new Variable();
                $null->null();

                return $null;
            }
        }

        return $operand;
    }

    /**
     * Literal constant slots may alias branch-assigned CVs — prefer initialized runtime (#10430, #9973).
     */
    private function readRuntimeOperandPreferringInitializedCv(Frame $frame, int $slot): Variable
    {
        if (isset($frame->block->constants[$slot])) {
            if (isset($frame->scope[$slot])) {
                $local = $frame->scope[$slot]->resolveIndirect();
                if (!$local->isUndefined() && !$this->isUnboundLocalScopeRead($frame, $slot)) {
                    return $this->readScopeOperandForRuntimeRead($frame, $slot);
                }
            }
            $calleeFunc = $frame->block->func;
            for ($f = $frame->parent; null !== $f; $f = $f->parent) {
                // Callee concat must not read caller CVs when slot indices collide (#17383, re-#16253).
                if ($f->block->func !== $calleeFunc) {
                    break;
                }
                if (!isset($f->scope[$slot])) {
                    continue;
                }
                $resolved = $f->scope[$slot]->resolveIndirect();
                if ($resolved->isUndefined() || $this->isUnboundLocalScopeRead($f, $slot)) {
                    continue;
                }

                return $this->readScopeOperandForRuntimeRead($f, $slot);
            }

            return $frame->block->constants[$slot];
        }

        return $this->readScopeOperandForRuntimeRead($frame, $slot);
    }

    /** TYPE_CONCAT operands may be literal constant slots colliding with assign dest (#9973, #9063). */
    private function readRuntimeOperandForConcat(Frame $frame, int $slot): Variable
    {
        return $this->readRuntimeOperandPreferringInitializedCv($frame, $slot);
    }

    /** Bitwise ops in CFG branch blocks may inherit polluted literal slots (#15902). */
    private function readRuntimeOperandForBitwise(Frame $frame, int $slot): Variable
    {
        if (isset($frame->block->constants[$slot])) {
            $copy = new Variable();
            $copy->duplicateFrom($frame->block->constants[$slot]);

            return $copy;
        }

        return $this->readScopeOperandForRuntimeRead($frame, $slot);
    }

    private function isUnboundLocalScopeRead(Frame $frame, int $slot): bool
    {
        if (!isset($frame->scope[$slot])) {
            return false;
        }
        if (null !== $frame->catchVarSlot && $slot === $frame->catchVarSlot) {
            return false;
        }
        $name = $this->resolveScopeSlotVariableName($frame, $slot);
        if (null === $name || 'this' === $name) {
            return false;
        }
        $resolved = $frame->scope[$slot]->resolveIndirect();
        if ($resolved->isUndefined()) {
            return true;
        }
        $globalName = $this->context->globalNameForStorage($resolved);
        if (null !== $globalName) {
            return !$this->context->isGlobalEverAssigned($globalName);
        }
        $staticKey = $this->context->functionStaticKeyForStorage($resolved);
        if (null !== $staticKey) {
            return !$this->isFunctionStaticInitializedForFrame($frame, $staticKey);
        }
        if (null === $frame->block || $frame->block->inheritUndefinedLocals) {
            return false;
        }
        if (null !== $name && $frame->block->declaresGlobalName($name)) {
            return false;
        }
        // Zend ZEND_CHECK_UNDEFINED_VAR: assigned CVs (extract imports, etc.) are readable (#10590).
        if (Variable::TYPE_NULL !== $resolved->type) {
            return false;
        }

        return !isset($frame->initializedSlots[$slot]);
    }

    private function markScopeSlotInitialized(Frame $frame, int $slot): void
    {
        $frame->initializedSlots[$slot] = true;
        if (!isset($frame->scope[$slot])) {
            return;
        }
        $globalName = $this->context->globalNameForStorage($frame->scope[$slot]->resolveIndirect());
        if (null !== $globalName) {
            $this->context->markGlobalEverAssigned($globalName);
        }
    }

    /** Mark CV init when a binary op writes directly into a named local slot (#9063). */
    private function markScopeSlotInitializedIfNamedLocal(Frame $frame, int $slot): void
    {
        if (null === $this->resolveScopeSlotVariableName($frame, $slot)) {
            return;
        }
        $this->markScopeSlotInitialized($frame, $slot);
    }

    private function resolveScopeSlotVariableName(Frame $frame, int $slot): ?string
    {
        $operand = $frame->block->operandForScopeSlot($slot);

        return null !== $operand ? Block::resolveVariableName($operand) : null;
    }

    private function isSimpleVariableIncDecLvalue(Variable $write): bool
    {
        if (null !== $this->resolvePropertyWriteOwner($write)) {
            return false;
        }
        $target = $write->resolveIndirect();
        if (Variable::TYPE_STRING_OFFSET === $target->type) {
            return false;
        }
        $classLc = $write->staticPropertyClassLc ?? $target->staticPropertyClassLc;
        if (is_string($classLc) && '' !== $classLc) {
            return false;
        }

        return true;
    }

    /**
     * Read via get hook for ++/-- on hooked static or instance properties (#6319, zend_property_hooks.c).
     */
    private function fetchHookedPropertyValueForIncDec(Variable $write, Frame $frame): ?Variable
    {
        if ($this->isPropertyHookRawWrite($frame, $this->resolvePropertyWriteName($write) ?? '')) {
            return null;
        }
        $target = $write->resolveIndirect();
        $classLc = $write->staticPropertyClassLc ?? $target->staticPropertyClassLc;
        $staticPropName = $write->objectPropertyName ?? $target->objectPropertyName;
        if (is_string($classLc) && is_string($staticPropName) && '' !== $staticPropName) {
            $hooks = $this->resolveStaticPropertyHooks($classLc, strtolower($staticPropName));
            $getLc = $hooks['get'] ?? null;
            if (null === $getLc) {
                return null;
            }

            return $this->fetchStaticPropertyWithHooks($classLc, $staticPropName, $getLc, $frame);
        }
        $owner = $this->resolvePropertyWriteOwner($write);
        $propName = $this->resolvePropertyWriteName($write);
        if (null === $owner || null === $propName) {
            return null;
        }

        return $this->fetchPropertyWithHooks($owner, $propName, $frame);
    }

    /**
     * In-place compound assign on hooked properties ($prop .= 'x', $prop += 1) (#6438, zend_property_hooks.c).
     */
    private function executeHookedPropertyInPlaceCompound(Frame $frame, OpCode $op, Variable $hookedRead): ?Frame
    {
        $write = $frame->scope[$op->arg1];
        $working = new Variable();
        $working->copyFrom($hookedRead->resolveIndirect());
        try {
            switch ($op->type) {
                case OpCode::TYPE_CONCAT:
                    $lhs = $this->coerceVariableToString($working, $frame);
                    $rhs = $this->coerceVariableToString($frame->scope[$op->arg3], $frame);
                    $working->string($lhs . $rhs);
                    break;
                case OpCode::TYPE_PLUS:
                case OpCode::TYPE_MINUS:
                case OpCode::TYPE_MUL:
                case OpCode::TYPE_DIV:
                case OpCode::TYPE_MODULO:
                case OpCode::TYPE_POW:
                    $working->numericOp($op->type, $working, $frame->scope[$op->arg3], $this, $frame);
                    break;
                case OpCode::TYPE_BITWISE_AND:
                case OpCode::TYPE_BITWISE_OR:
                case OpCode::TYPE_BITWISE_XOR:
                case OpCode::TYPE_SHIFT_LEFT:
                case OpCode::TYPE_SHIFT_RIGHT:
                    $working->bitwiseOp($op->type, $working, $frame->scope[$op->arg3], $this, $frame);
                    break;
                default:
                    return null;
            }
        } catch (\TypeError $e) {
            return $this->dispatchVmTypeError($e, $frame);
        } catch (\DivisionByZeroError $e) {
            return $this->dispatchVmDivisionByZeroError($e, $frame);
        } catch (\ArithmeticError $e) {
            return $this->dispatchVmArithmeticError($e, $frame);
        } catch (\Error $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }
        $catchFrame = $this->enforceAsymmetricPropertyWrite($write, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        if (!$this->dispatchPropertySetHookAssign($write, $working, $frame)) {
            $catchFrame = $this->enforceVirtualPropertyHookWrite($write, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            $write->copyFrom($working);
        }

        return null;
    }

    private function executeHookedPropertyIncDec(
        Frame $frame,
        Variable $hookedRead,
        Variable $write,
        Variable $result,
        bool $increment,
        bool $prefix
    ): ?Frame {
        $working = new Variable();
        $working->copyFrom($hookedRead->resolveIndirect());
        try {
            if ($prefix) {
                if ($increment) {
                    $working->applyIncrement($this, $frame);
                } else {
                    $working->applyDecrement($this, $frame);
                }
                $catchFrame = $this->enforceAsymmetricPropertyWrite($write, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                if (!$this->dispatchPropertySetHookAssign($write, $working, $frame)) {
                    $catchFrame = $this->enforceVirtualPropertyHookWrite($write, $frame);
                    if (null !== $catchFrame) {
                        return $catchFrame;
                    }
                    $write->copyFrom($working);
                }
                $result->copyFrom($working);

                return null;
            }
            $old = new Variable();
            $old->copyFrom($working);
            if ($increment) {
                $working->applyIncrement($this, $frame);
            } else {
                $working->applyDecrement($this, $frame);
            }
            $catchFrame = $this->enforceAsymmetricPropertyWrite($write, $frame);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            if (!$this->dispatchPropertySetHookAssign($write, $working, $frame)) {
                $catchFrame = $this->enforceVirtualPropertyHookWrite($write, $frame);
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                $write->copyFrom($working);
            }
            $result->copyFrom($old);
        } catch (\TypeError $e) {
            return $this->dispatchVmTypeError($e, $frame);
        }

        return null;
    }

    protected function raise(string $message, Frame $frame): int
    {
        $where = '' !== $frame->scriptPath ? $frame->scriptPath : 'script';
        throw new \LogicException($message.' in '.$where);
    }

    private function resolveIncludeFilename(string $file, Frame $frame): ?string
    {
        if ('' === $file || str_contains($file, "\0")) {
            return null;
        }
        // Absolute unix paths or windows drive letters.
        if ($file[0] === '/' || (strlen($file) > 1 && $file[1] === ':')) {
            $normalized = VM\ScriptStack::normalize($file);

            return '' !== $normalized && is_file($normalized) ? $normalized : null;
        }

        // 1) As-is (cwd / relative execution context)
        $candidate = VM\ScriptStack::normalize($file);
        if ('' !== $candidate && is_file($candidate)) {
            return $candidate;
        }

        // 2) Relative to the current script directory (Zend-like common path)
        $current = '' !== $frame->scriptPath ? $frame->scriptPath : $this->context->scriptStack->current();
        if (!is_string($current) || '' === $current || '-' === $current) {
            $current = '';
        }
        if ('' !== $current) {
            $fromDir = dirname($current);
            $cand = VM\ScriptStack::normalize($fromDir.'/'.$file);
            if ('' !== $cand && is_file($cand)) {
                return $cand;
            }
        }

        // 3) include_path search (VmIncludePath stack; issues #3223, #6051)
        $includePath = \PHPCompiler\ext\standard\VmIncludePath::get();
        if ('' !== $includePath) {
            foreach (explode(\PATH_SEPARATOR, $includePath) as $dir) {
                if ('' === $dir) {
                    continue;
                }
                $cand = VM\ScriptStack::normalize(rtrim($dir, '/').'/'.$file);
                if ('' !== $cand && is_file($cand)) {
                    return $cand;
                }
            }
        }

        return null;
    }

    /** Zend get_debug_type() labels for TypeError messages (#4241). */
    private function valueDebugTypeLabel(Variable $value): string
    {
        if (Variable::TYPE_OBJECT === $value->type || Variable::TYPE_ENUM_CASE === $value->type) {
            return 'object';
        }

        return match ($value->type) {
            Variable::TYPE_STRING => 'string',
            Variable::TYPE_INTEGER => 'int',
            Variable::TYPE_FLOAT => 'float',
            Variable::TYPE_BOOLEAN => 'bool',
            Variable::TYPE_NULL => 'null',
            Variable::TYPE_ARRAY => 'array',
            default => 'mixed',
        };
    }

    /**
     * Resolve `ClassName::class` reference string (Zend zend_constants.c; #15645).
     *
     * Returns the name used to refer to the class — alias when accessed via alias, canonical otherwise.
     * self/parent/static resolve to the declaring/late-static/parent class canonical name.
     */
    protected function resolveClassPseudoConstDisplayName(string $className, Frame $frame): string
    {
        $lcClass = strtolower($className);
        if ('self' === $lcClass) {
            $declaring = $this->declaringClassLc($frame, 'self');
            $fallback = $this->context->classes[$declaring]->name;
            $funcClassLc = null;
            $funcIsTrait = false;
            $methodLc = null;
            if (null !== $frame->block->func && null !== $frame->block->func->class) {
                $funcClassLc = $frame->block->func->class->value;
                $funcLc = strtolower(ltrim($funcClassLc, '\\'));
                $funcIsTrait = ($this->context->classes[$funcLc] ?? null)?->isTrait ?? false;
                $methodLc = strtolower($frame->block->func->name);
            }
            $calledLc = null !== $frame->calledClass && '' !== $frame->calledClass
                ? $frame->calledClass
                : null;

            return VM\TraitSelfClassScope::resolveSelfClassName(
                $funcClassLc,
                $funcIsTrait,
                $calledLc,
                $fallback,
                fn (string $lc): string => $this->context->classes[$lc]->name,
                $methodLc,
                fn (string $classLc, string $method): ?string => $this->context->classes[$classLc]->traitMethodSources[$method] ?? null,
                fn (string $classLc): ?string => $this->context->classes[$classLc]->parentLc ?? null,
                fn (string $classLc): bool => ($this->context->classes[$classLc] ?? null)?->isTrait ?? false,
            );
        }
        if ('static' === $lcClass) {
            $lateLc = $this->lateStaticClassLc($frame);

            return $this->context->classes[$lateLc]->name;
        }
        if ('parent' === $lcClass) {
            $declaring = $this->declaringClassLc($frame, 'parent');
            $parentLc = $this->context->classes[$declaring]->parentLc;
            if (null === $parentLc) {
                throw new \LogicException('parent:: used when class has no parent');
            }

            return $this->context->classes[$parentLc]->name;
        }

        return $className;
    }

    /**
     * Resolve `$operand::class` (Zend zend_compile.c FETCH_CLASS on enum case / object).
     *
     * Legacy Resource wrappers are not objects for {@code ::class} — Zend TypeError
     * {@code Cannot use "::class" on resource} (#29623 / zend_execute.c).
     */
    private function resolveClassPseudoConstFromOperand(Variable $operand): ?string
    {
        $operand = $operand->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $operand->type) {
            return $operand->toEnumCase()->enumClass->name;
        }
        if (Variable::TYPE_OBJECT === $operand->type) {
            $object = $operand->toObject();
            if (VM\ResourceSupport::isHiddenPseudoClassEntry($object->class)) {
                return null;
            }

            return $object->class->name;
        }

        return null;
    }

    /** True when the next opcode assigns through this VAR_FETCH destination slot (#3801, #5370). */
    private function varFetchDestUsedAsAssignLvalue(Frame $frame, OpCode $op): bool
    {
        $nextIndex = $frame->pos;
        if ($nextIndex >= $frame->block->nOpCodes) {
            return false;
        }
        $next = $frame->block->opCodes[$nextIndex] ?? null;
        if (null === $next) {
            return false;
        }

        return OpCode::destSlotUsedAsAssignLvalue($next, (int) $op->arg1);
    }

    /** True when fetch dest is mutated by a following ++/-- (#7431, zend_execute.c). */
    private function propertyFetchDestUsedAsIncDec(Frame $frame, OpCode $op): bool
    {
        $destSlot = (int) $op->arg1;
        $next = $frame->block->opCodes[$frame->pos] ?? null;
        if (null === $next) {
            return false;
        }

        return \in_array($next->type, [
            OpCode::TYPE_PRE_INC,
            OpCode::TYPE_POST_INC,
            OpCode::TYPE_PRE_DEC,
            OpCode::TYPE_POST_DEC,
        ], true) && $next->arg3 === $destSlot;
    }

    /**
     * True when an existing instance slot is UNDEF for get_property_ptr_ptr BP_VAR_RW (#29241).
     *
     * Typed uninitialized props stay Error-on-read; untyped/explicitly-unset warn like a plain read.
     */
    private function objectPropertySlotIsUndefinedForRwWarn(
        ObjectEntry $object,
        string $name,
        Frame $frame
    ): bool {
        if ($object->isPropertyExplicitlyUnset($name)) {
            return true;
        }
        $propMeta = $this->classPropertyMeta($object, $name, $frame);
        $propSlot = null !== $propMeta && $object->hasPropertyForMeta($propMeta)
            ? $object->getPropertyForMeta($propMeta)
            : ($object->hasProperty($name) ? $object->getProperty($name) : null);
        if (null === $propSlot) {
            return false;
        }

        return $propSlot->resolveIndirect()->isUndefined()
            && !VM\TypedPropertyCheck::isUninitialized($propSlot);
    }

    /**
     * After creating/binding a property for ++/-- (BP_VAR_RW), emit Undefined property
     * (zend_std_get_property_ptr_ptr — after allocation, #29241).
     */
    private function warnUndefinedPropertyAfterIncDecRwFetch(
        ObjectEntry $object,
        string $name,
        Frame $frame
    ): void {
        $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
        $this->context->errors->undefinedPropertyRead(
            $object->class->name,
            $name,
            $this->context,
            $frame,
            $scriptFile
        );
    }

    /** True when a following opcode assigns through this PROPERTY_FETCH destination slot (#5370). */
    private function propertyFetchDestUsedAsAssignLvalue(Frame $frame, OpCode $op): bool
    {
        // Only the immediate next opcode (pos already advanced past this fetch). Scanning the
        // whole block false-positives on dead-temp reuse after ARG_SEND / nested fetches (#23986).
        $nextIndex = $frame->pos;
        if ($nextIndex >= $frame->block->nOpCodes) {
            return false;
        }
        $next = $frame->block->opCodes[$nextIndex] ?? null;
        if (null === $next) {
            return false;
        }

        return OpCode::destSlotUsedAsAssignLvalue($next, (int) $op->arg1);
    }

    /** True when fetch dest is the RHS of a following ASSIGN_REF (#22475). */
    private function propertyFetchDestUsedAsAssignRefSource(Frame $frame, OpCode $op): bool
    {
        $destSlot = (int) $op->arg1;
        for ($j = $frame->pos, $n = $frame->block->nOpCodes; $j < $n; $j++) {
            $candidate = $frame->block->opCodes[$j] ?? null;
            if (null === $candidate) {
                continue;
            }
            if (OpCode::destSlotUsedAsAssignRefSource($candidate, $destSlot)) {
                return true;
            }
        }

        return false;
    }

    /**
     * True when fetch dest is returned from a by-ref function (`return $this->prop`, #29456).
     *
     * PROPERTY_FETCH is immediately followed by RETURN on the same slot in the typical
     * `function &get(){ return $this->x; }` lowering.
     */
    private function propertyFetchDestUsedAsReturnByRef(Frame $frame, OpCode $op): bool
    {
        if (!$this->functionReturnsByRef($frame)) {
            return false;
        }
        $destSlot = (int) $op->arg1;
        $next = $frame->block->opCodes[$frame->pos] ?? null;
        if (null === $next) {
            return false;
        }

        return OpCode::destSlotUsedAsReturnValue($next, $destSlot);
    }

    /**
     * Live property alias without treating the fetch as a direct assign lvalue
     * (ASSIGN_REF RHS or by-ref return — #22475 / #29456).
     */
    private function propertyFetchDestUsedAsLiveRefBinding(Frame $frame, OpCode $op): bool
    {
        return $this->propertyFetchDestUsedAsAssignRefSource($frame, $op)
            || $this->propertyFetchDestUsedAsReturnByRef($frame, $op);
    }

    /** True when fetch dest is read by a compound op before a later assign (#6438, zend_property_hooks.c). */
    private function propertyFetchDestUsedAsReadBeforeAssign(Frame $frame, OpCode $op): bool
    {
        $destSlot = (int) $op->arg1;
        $next = $frame->block->opCodes[$frame->pos] ?? null;
        if (null === $next) {
            return false;
        }

        return OpCode::destSlotUsedAsCompoundAssignRead($next, $destSlot);
    }

    /**
     * True when fetch dest is the container for `foreach (… as &$v)` (FE_RESET_RW, #29215).
     *
     * PROPERTY_FETCH is immediately followed by ITER_RESET on the same slot; the by-ref flag
     * lives on ITER_VALUE in a successor block (arg3), so scan reachable CFG edges.
     */
    private function propertyFetchDestUsedAsByRefForeachIterable(Frame $frame, OpCode $op): bool
    {
        $destSlot = (int) $op->arg1;
        $next = $frame->block->opCodes[$frame->pos] ?? null;
        if (
            null === $next
            || OpCode::TYPE_ITER_RESET !== $next->type
            || (int) $next->arg1 !== $destSlot
        ) {
            return false;
        }

        return $this->foreachContainerSlotHasByRefValueFetch($frame->block, $destSlot);
    }

    /**
     * Walk successor blocks from {@see $start} for ITER_VALUE with by-ref on {@see $containerSlot}.
     */
    private function foreachContainerSlotHasByRefValueFetch(\PHPCompiler\Block $start, int $containerSlot): bool
    {
        $seen = [];
        $queue = [$start];
        while ([] !== $queue) {
            $block = array_shift($queue);
            $id = spl_object_id($block);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($block->opCodes as $candidate) {
                if (OpCode::destSlotUsedAsByRefForeachValueContainer($candidate, $containerSlot)) {
                    return true;
                }
                foreach ([$candidate->block1, $candidate->block2, $candidate->block3] as $edge) {
                    if ($edge instanceof \PHPCompiler\Block) {
                        $queue[] = $edge;
                    }
                }
            }
        }

        return false;
    }

    /**
     * True when fetch dest is the container for a following dim mutation
     * ($prop[]= / $prop[k]=, or unset($prop[k]) — #6775, #24250).
     *
     * Multi-target unset batches PropertyFetch ops before TYPE_UNSET, so look beyond the
     * immediate next opcode through sibling fetches / other unsets (#24250).
     */
    private function propertyFetchDestUsedAsDimWriteContainer(Frame $frame, OpCode $op): bool
    {
        $destSlot = (int) $op->arg1;
        $ops = $frame->block->opCodes;
        $n = \count($ops);
        for ($i = $frame->pos; $i < $n; ++$i) {
            $next = $ops[$i];
            if (OpCode::destSlotUsedAsDimWriteContainer($next, $destSlot)) {
                return true;
            }
            if (
                OpCode::TYPE_PROPERTY_FETCH === $next->type
                || OpCode::TYPE_PROPERTY_FETCH_WRITE === $next->type
            ) {
                if ((int) $next->arg1 === $destSlot) {
                    // Same temp redefined before a dim mutation — not an aliasing consumer.
                    return false;
                }
                continue;
            }
            if (
                OpCode::TYPE_ARRAY_DIM_FETCH === $next->type
                || OpCode::TYPE_ARRAY_DIM_FETCH_WRITE === $next->type
            ) {
                continue;
            }
            if (OpCode::TYPE_UNSET === $next->type) {
                // unset of a different container; keep scanning for ours.
                continue;
            }

            return false;
        }

        return false;
    }

    private function containerNeedsHookedDimWriteBack(Variable $containerSlot): bool
    {
        $container = $containerSlot->resolveIndirect();

        return $container->propertyHookDimWriteBackPending;
    }

    private function tagHookedPropertyDimWriteLvalue(Variable $dimLvalue, Variable $containerSlot): void
    {
        if (
            !$this->containerNeedsHookedDimWriteBack($containerSlot)
            && (null === $containerSlot->objectPropertyOwner || null === $containerSlot->objectPropertyName)
        ) {
            return;
        }
        $dimLvalue->hookedPropertyDimWriteBackContainer = $containerSlot;
    }

    /** Skip eager set-hook dispatch on $prop[] = / $prop[$k] = element writes (#6775, #9875). */
    private function assignDefersHookedPropertyDimWriteBack(Variable $lvalue): bool
    {
        if (null !== $lvalue->hookedPropertyDimWriteBackContainer) {
            return true;
        }
        $target = $lvalue->resolveIndirect();
        if ($target !== $lvalue && null !== $target->hookedPropertyDimWriteBackContainer) {
            return true;
        }

        return false;
    }

    /**
     * Tag property-fetch container for readonly dim-write enforcement (#7245, zend_readonly.c).
     */
    private function tagReadonlyPropertyDimWriteContainer(
        Variable $containerSlot,
        ObjectEntry $owner,
        string $propName
    ): void {
        if (!$owner->constructed) {
            return;
        }
        if (isset($owner->reinitableProperties[$propName])) {
            return;
        }
        if (null === $this->readonlyPropertyDeclaringClass($owner, $propName)) {
            return;
        }
        $containerSlot->objectPropertyOwner = $owner;
        $containerSlot->objectPropertyName = $propName;
    }

    private function flushHookedPropertyDimWriteBackAfterAssign(Variable $writtenLvalue, Frame $frame): ?Frame
    {
        $containerSlot = $writtenLvalue->hookedPropertyDimWriteBackContainer;
        if (null === $containerSlot) {
            $target = $writtenLvalue->resolveIndirect();
            if ($target !== $writtenLvalue) {
                $containerSlot = $target->hookedPropertyDimWriteBackContainer;
            }
        }
        if (null === $containerSlot) {
            return null;
        }
        $container = $containerSlot->resolveIndirect();
        if (!$container->propertyHookDimWriteBackPending) {
            return null;
        }
        $container->propertyHookDimWriteBackPending = false;
        $writtenLvalue->hookedPropertyDimWriteBackContainer = null;
        if ($this->dispatchPropertySetHookAssign($containerSlot, $containerSlot, $frame)) {
            return null;
        }
        if ($this->context->propertyHookSetAborted) {
            $this->context->propertyHookSetAborted = false;

            return null;
        }
        $catchFrame = $this->enforceVirtualPropertyHookWrite($containerSlot, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $containerSlot->copyFrom($container);

        return null;
    }

    /**
     * Dim/append/unset-dim on a hooked property without `&get` is Error in php-src 8.4.24+
     * (zend_object_handlers get_property_ptr_ptr / #28590). Older get→set RMW (#6775/#19171)
     * no longer matches Zend; keep RMW only for virtual `&get`+`set`.
     */
    private function deliverHookedPropertyDimWriteContainer(
        Variable $dest,
        Variable $hookValue,
        ObjectEntry $owner,
        string $propName,
        Frame $frame,
    ): ?Frame {
        $proxy = new Variable();
        $proxy->objectPropertyOwner = $owner;
        $proxy->objectPropertyName = $propName;
        // Caller already enforced `&get` via enforceHookedPropertyDimWriteRequiresByRefGet;
        // keep the check here for static/shared call sites.
        if (!$this->propertyHookGetIsByRef($proxy)) {
            return $this->dispatchVmError(
                $this->indirectModificationOfHookedPropertyMessage($proxy),
                $frame
            );
        }
        $catchFrame = $this->enforceVirtualPropertyHookWrite($proxy, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $working = new Variable();
        $working->duplicateFrom($hookValue);
        $dest->copyFrom($working);
        $dest->objectPropertyOwner = $owner;
        $dest->objectPropertyName = $propName;
        $dest->propertyHookDimWriteBackPending = true;

        return null;
    }

    /**
     * Refuse `$o->hooked[]=` / `$o->hooked[$k]=` / `unset($o->hooked[$k])` unless `&get` (#28590).
     */
    private function enforceHookedPropertyDimWriteRequiresByRefGet(
        ObjectEntry $owner,
        string $propName,
        Frame $frame,
    ): ?Frame {
        $meta = $this->classPropertyMeta($owner, $propName);
        if (null === $meta) {
            return null;
        }
        if (null === $meta->getHookMethodLc && null === $meta->setHookMethodLc) {
            return null;
        }
        if ($meta->getHookByRef) {
            return null;
        }
        $proxy = new Variable();
        $proxy->objectPropertyOwner = $owner;
        $proxy->objectPropertyName = $propName;

        return $this->dispatchVmError(
            $this->indirectModificationOfHookedPropertyMessage($proxy),
            $frame
        );
    }

    /**
     * `&get`-only hooked property: `$o->x[] =` / `$o->x[$k] =` mutates the by-ref get target
     * without a set hook or write-back (#21098, zend_property_hooks.c).
     *
     * @return bool true when the dest was wired as a live by-ref dim container
     */
    private function deliverByRefGetHookedPropertyDimWriteContainer(
        Variable $dest,
        ObjectEntry $owner,
        string $propName,
        Frame $frame,
    ): bool {
        $meta = $this->classPropertyMeta($owner, $propName);
        if (null === $meta || !$meta->getHookByRef || null === $meta->getHookMethodLc) {
            return false;
        }
        if (null !== $meta->setHookMethodLc) {
            // Backed `&get`+`set` is illegal in php-src; virtual may allow both — use RMW path.
            return false;
        }
        $lcClass = strtolower($owner->class->name);
        $propMeta = $this->context->propertyHookRegistry[$lcClass][$propName]
            ?? $this->context->propertyHookRegistry[$lcClass][strtolower($propName)]
            ?? null;
        $backingName = is_array($propMeta)
            ? ($propMeta['getBacking'] ?? null)
            : null;
        if (null !== $backingName && $owner->hasProperty($backingName)) {
            $dest->indirect($owner->getProperty($backingName));

            return true;
        }
        // No recorded backing: invoke `&get` and keep the returned reference live.
        $hookValue = $this->fetchPropertyWithHooksByRef($owner, $propName, $frame);
        if (null === $hookValue) {
            return false;
        }
        $dest->indirect($hookValue);

        return true;
    }

    /**
     * Invoke a get hook preserving return-by-ref aliases (#21098).
     */
    private function fetchPropertyWithHooksByRef(ObjectEntry $object, string $name, Frame $frame): ?Variable
    {
        $meta = $this->classPropertyMeta($object, $name);
        $getLc = $meta?->getHookMethodLc
            ?? strtolower(SourcePreprocessor\PropertyHooks::getHookMethodName($name));
        if (!isset($object->class->methods[$getLc])) {
            return null;
        }
        $func = $object->class->methods[$getLc];
        if (!$func instanceof Func\PHP) {
            return null;
        }
        $thisVar = new Variable();
        $thisVar->object($object);

        return $this->invokePhpFunctionWithPropertyHookRawByRef($func, $name, $frame, $thisVar);
    }

    private function invokePhpFunctionWithPropertyHookRawByRef(
        Func\PHP $func,
        string $rawProperty,
        Frame $parentFrame,
        Variable ...$args
    ): Variable {
        $savedStack = null !== $this->context->currentFiber
            ? null
            : $this->context->swapRunStack(null);
        $savedExternalCatch = $this->context->propertyHookExternalCatchFrame;
        $this->context->propertyHookExternalCatchFrame = null;
        try {
            $this->emitPropertyHookDeprecationNotice($func, $rawProperty, $parentFrame);
            $child = $func->getFrame($this->context, $parentFrame);
            $child->propertyHookRawProperty = $rawProperty;
            $child->calledArgs = $args;
            if (
                [] !== $args
                && null !== $func->block->func
                && null !== $func->block->func->class
            ) {
                $thisIdx = $func->block->slotIndexForVariableName('this');
                if (null !== $thisIdx) {
                    $child->scope[$thisIdx] = $args[0];
                }
            }
            $out = new Variable();
            $child->returnVar = $out;
            $this->context->push($child);
            $result = $this->runFrames();
            if (null !== $this->context->propertyHookExternalCatchFrame) {
                throw new VM\PropertyHookRefWriteSignal($this->context->propertyHookExternalCatchFrame);
            }
            if (self::FIBER_SUSPEND === $result) {
                throw new VM\PropertyHookFiberSuspendSignal($parentFrame);
            }
            if (self::SUCCESS !== $result) {
                throw new \LogicException('Property hook invocation failed in this compiler build');
            }
            // Preserve TYPE_INDIRECT so dim writes mutate the `&get` target (#21098).
            if (Variable::TYPE_INDIRECT === $out->type) {
                return $out;
            }

            return $out->resolveIndirect();
        } finally {
            $this->context->propertyHookExternalCatchFrame = $savedExternalCatch;
            if (null !== $savedStack) {
                $this->context->swapRunStack($savedStack);
            }
        }
    }

    private function deliverHookedStaticPropertyDimWriteContainer(
        Variable $dest,
        Variable $hookValue,
        string $classLc,
        string $propNameRaw,
        Frame $frame,
    ): ?Frame {
        $proxy = new Variable();
        $proxy->staticPropertyClassLc = $classLc;
        $proxy->objectPropertyName = $propNameRaw;
        // Same `&get` requirement as instance dim writes (#28590).
        if (!$this->propertyHookGetIsByRef($proxy)) {
            return $this->dispatchVmError(
                $this->indirectModificationOfHookedPropertyMessage($proxy),
                $frame
            );
        }
        $catchFrame = $this->enforceVirtualPropertyHookWrite($proxy, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $working = new Variable();
        $working->duplicateFrom($hookValue);
        $dest->copyFrom($working);
        $dest->staticPropertyClassLc = $classLc;
        $dest->objectPropertyName = $propNameRaw;
        $dest->propertyHookDimWriteBackPending = true;

        return null;
    }

    /**
     * Run an internal builtin handler; bridge native Error/Throwable into user catch (#3648).
     */
    private function executeInternalHandler(Frame $handlerFrame, Frame $callerFrame): ?Frame
    {
        // Void builtin calls omit returnVar; handlers must still run validation/throws (#4866).
        if (null === $handlerFrame->returnVar) {
            $handlerFrame->returnVar = new Variable();
        }
        if ($handlerFrame->handler instanceof Func\Internal) {
            foreach (BuiltinByRefParams::forFunction($handlerFrame->handler->getName()) as $idx) {
                if (!isset($handlerFrame->calledArgs[$idx])) {
                    continue;
                }
                $catchFrame = $this->enforceReadonlyPropertyWrite(
                    $handlerFrame->calledArgs[$idx],
                    $callerFrame
                );
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
                $catchFrame = $this->enforceFinalPropertyWrite(
                    $handlerFrame->calledArgs[$idx],
                    $callerFrame
                );
                if (null !== $catchFrame) {
                    return $catchFrame;
                }
            }
        }
        try {
            $this->builtinHandlerFrameForTrace = $handlerFrame;
            $handlerFrame->handler->execute($handlerFrame);

            return null;
        } catch (\DivisionByZeroError $e) {
            return $this->dispatchVmDivisionByZeroError($e, $callerFrame);
        } catch (\ArithmeticError $e) {
            return $this->dispatchVmArithmeticError($e, $callerFrame);
        } catch (\ArgumentCountError $e) {
            return $this->dispatchVmArgumentCountError($e, $callerFrame);
        } catch (\TypeError $e) {
            return $this->dispatchVmTypeError($e, $callerFrame);
        } catch (\PHPCompiler\ext\simdjson\SimdJsonValueError $e) {
            return $this->dispatchVmSimdJsonValueError($e, $callerFrame);
        } catch (\ValueError $e) {
            return $this->dispatchVmValueError($e, $callerFrame);
        } catch (\AssertionError $e) {
            return $this->dispatchVmAssertionError($e, $callerFrame);
        } catch (VM\NativeFiberError $e) {
            return $this->dispatchVmFiberError($e, $callerFrame);
        } catch (VM\NativeFiberStackOverflow $e) {
            return $this->dispatchVmFiberStackOverflowFromNative($e, $callerFrame);
        } catch (\ParseError $e) {
            return $this->dispatchVmParseError($e, $callerFrame);
        } catch (\CompileError $e) {
            return $this->dispatchVmCompileError($e, $callerFrame);
        } catch (\ReflectionException $e) {
            return $this->dispatchVmReflectionException($e, $callerFrame);
        } catch (\JsonException $e) {
            return $this->dispatchVmJsonException($e, $callerFrame);
        } catch (\DOMException $e) {
            return $this->dispatchVmDomException($e, $callerFrame);
        } catch (\SodiumException $e) {
            return $this->dispatchVmSodiumException($e, $callerFrame);
        } catch (\IntlException $e) {
            return $this->dispatchVmIntlException($e, $callerFrame);
        } catch (\RedisException $e) {
            return $this->dispatchVmRedisException($e, $callerFrame);
        } catch (\RarException $e) {
            return $this->dispatchVmRarException($e, $callerFrame);
        } catch (\PHPCompiler\ext\simdjson\SimdJsonException $e) {
            return $this->dispatchVmSimdJsonException($e, $callerFrame);
        } catch (\FFI\ParserException $e) {
            return $this->dispatchVmFfiException($e, $callerFrame, true);
        } catch (\FFI\Exception $e) {
            return $this->dispatchVmFfiException($e, $callerFrame, false);
        } catch (VM\NativeDateInvalidTimeZoneException $e) {
            return $this->dispatchVmDateInvalidTimeZoneException($e, $callerFrame);
        } catch (VM\NativeDateMalformedStringException $e) {
            return $this->dispatchVmDateMalformedStringException($e, $callerFrame);
        } catch (VM\NativeDateInvalidOperationException $e) {
            return $this->dispatchVmDateInvalidOperationException($e, $callerFrame);
        } catch (VM\NativeDateMalformedIntervalException $e) {
            return $this->dispatchVmDateMalformedIntervalException($e, $callerFrame);
        } catch (VM\NativeDateMalformedPeriodStringException $e) {
            return $this->dispatchVmDateMalformedPeriodStringException($e, $callerFrame);
        } catch (VM\NativeDateRangeError $e) {
            return $this->dispatchVmDateRangeError($e, $callerFrame);
        } catch (VM\NativeDateObjectError $e) {
            return $this->dispatchVmDateObjectError($e, $callerFrame);
        } catch (\Error $e) {
            return $this->dispatchVmError($e->getMessage(), $callerFrame);
        } catch (VM\GeneratorUncaughtThrow $e) {
            return $this->dispatchUncaughtGeneratorThrow($e->thrown, $callerFrame, $handlerFrame);
        } catch (VM\FiberUncaughtThrow $e) {
            return $this->dispatchUncaughtGeneratorThrow($e->thrown, $callerFrame, $handlerFrame);
        } catch (TypedPropertyReadSignal $signal) {
            $catchFrame = $this->findCatchFrameForThrow($callerFrame, $signal->errorObject);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            $this->raiseUncaughtException($signal->errorObject);

            return null;
        } catch (ScriptExit $e) {
            throw $e;
        } catch (\BadMethodCallException $e) {
            // During (string)/echo __toString coercion, rethrow so TYPE_CAST_STRING (etc.)
            // can dispatch into the *user* try/catch. Bridging here returns a catch frame that
            // invokeMagicToString turns into MagicMethodInvocationAborted — which CAST swallows,
            // leaving "" / undefined ($s) instead of BadMethodCallException (#24907 CachingIterator).
            if ($this->context->coercingObjectToString) {
                throw $e;
            }

            return $this->dispatchVmBadMethodCallException($e, $callerFrame);
        } catch (\OutOfBoundsException $e) {
            return $this->dispatchVmOutOfBoundsException($e, $callerFrame);
        } catch (\UnexpectedValueException $e) {
            return $this->dispatchVmUnexpectedValueException($e, $callerFrame);
        } catch (\PDOException $e) {
            return $this->dispatchVmPDOException($e, $callerFrame);
        } catch (\SQLite3Exception $e) {
            return $this->dispatchVmSQLite3Exception($e, $callerFrame);
        } catch (\mysqli_sql_exception $e) {
            return $this->dispatchVmMysqliSqlException($e, $callerFrame);
        } catch (\PharException $e) {
            return $this->dispatchVmPharException($e, $callerFrame);
        } catch (\SoapFault $e) {
            return $this->dispatchVmSoapFault($e, $callerFrame);
        } catch (\RedisClusterException $e) {
            return $this->dispatchVmRedisClusterException($e, $callerFrame);
        } catch (\RuntimeException $e) {
            return $this->dispatchVmRuntimeException($e, $callerFrame);
        } catch (\InvalidArgumentException $e) {
            return $this->dispatchVmInvalidArgumentException($e, $callerFrame);
        } catch (\LogicException $e) {
            return $this->dispatchVmLogicException($e, $callerFrame);
        } catch (VM\NativeRequestParseBodyException $e) {
            return $this->dispatchVmRequestParseBodyException($e, $callerFrame);
        } catch (\Uri\WhatWg\InvalidUrlException $e) {
            return $this->dispatchVmInvalidUrlException($e, $callerFrame);
        } catch (\Uri\InvalidUriException $e) {
            return $this->dispatchVmInvalidUriException($e, $callerFrame);
        } catch (\Filter\FilterFailedException $e) {
            return $this->dispatchVmFilterFailedException($e, $callerFrame);
        } catch (VM\MagicMethodInvocationAborted) {
            $this->clearTryCatchUnwindState();
            $callerFrame->call = null;
            $this->clearOutgoingCallState($callerFrame);
            $callerFrame->suppressNextEcho = true;
            ++$callerFrame->pos;

            return null;
        } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
            return $this->resumeAfterBuiltinCallbackCatchRedirect($redirect);
        } catch (\Exception $e) {
            return $this->dispatchVmEngineException($e->getMessage(), $callerFrame);
        } finally {
            $this->builtinHandlerFrameForTrace = null;
        }
    }

    private function dispatchUncaughtGeneratorThrow(
        Variable $thrown,
        Frame $callerFrame,
        ?Frame $resumeHandlerFrame = null,
    ): ?Frame {
        if (null !== $resumeHandlerFrame) {
            VM\ExceptionTrace::captureOnGeneratorResumeUncaught(
                $this->context,
                $callerFrame,
                $resumeHandlerFrame,
                $thrown
            );
        }
        $catchFrame = $this->findCatchFrameForThrow($callerFrame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    /** Attach builtin throw trace then dispatch to user catch / fatal (#11677, #14369). */
    private function dispatchBuiltinThrowable(Frame $callerFrame, Variable $thrown): ?Frame
    {
        if (null !== $this->builtinHandlerFrameForTrace) {
            VM\ExceptionTrace::captureOnBuiltinThrow(
                $this->context,
                $callerFrame,
                $this->builtinHandlerFrameForTrace,
                $thrown
            );
        } else {
            VM\ExceptionTrace::captureOnThrow($this->context, $callerFrame, $thrown);
        }
        $catchFrame = $this->findCatchFrameForThrow($callerFrame, $thrown);
        if (null !== $catchFrame) {
            if ($this->stashPropertyHookSetExternalCatch($callerFrame, $catchFrame)) {
                return null;
            }

            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    /** Bridge native Exception from builtins (e.g. Generator::rewind after run, #5195). */
    private function dispatchVmEngineException(string $message, Frame $frame): ?Frame
    {
        $thrown = $this->makeEngineError($message, 'Exception');

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /**
     * Bridge host RequestParseBodyException into the VM RequestParseBodyException class (#5965).
     *
     * php-src: ext/standard/http.c — PHP_FUNCTION(request_parse_body).
     */
    private function dispatchVmRequestParseBodyException(\Throwable $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeRequestParseBodyException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge host Uri\InvalidUriException into VM catch handlers (#21468). */
    private function dispatchVmInvalidUriException(\Uri\InvalidUriException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeInvalidUriException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge host Filter\FilterFailedException into VM catch handlers (#28131). */
    private function dispatchVmFilterFailedException(\Filter\FilterFailedException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeFilterFailedException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge host Uri\WhatWg\InvalidUrlException into VM catch handlers (#21468). */
    private function dispatchVmInvalidUrlException(\Uri\WhatWg\InvalidUrlException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeInvalidUrlException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native LogicException from stdlib builtins into user catch handlers (#4866). */
    private function dispatchVmLogicException(\LogicException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeLogicException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge InvalidArgumentException from SPL builtins (#16917, ext/spl/spl_iterators.c). */
    private function dispatchVmInvalidArgumentException(\InvalidArgumentException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeInvalidArgumentException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge BadMethodCallException from SPL builtins (#13379, ext/spl/spl_iterators.c). */
    private function dispatchVmBadMethodCallException(\BadMethodCallException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeBadMethodCallException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge OutOfBoundsException from SPL builtins (#13561, ext/spl/spl_array.c). */
    private function dispatchVmOutOfBoundsException(\OutOfBoundsException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeOutOfBoundsException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native PDOException from ext/pdo builtins into user catch handlers (#19830, re-#3367, #22455). */
    private function dispatchVmPDOException(\PDOException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $errorInfo = null;
        if (isset($error->errorInfo) && \is_array($error->errorInfo)) {
            $errorInfo = $error->errorInfo;
        }
        $thrown = VM\BuiltinExceptionSupport::materializePDOException(
            $this->context,
            $error->getMessage(),
            $file,
            $line,
            (int) $error->getCode(),
            $errorInfo
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native SoapFault from ext/soap builtins (#20124, #20219). */
    private function dispatchVmSoapFault(\SoapFault $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $faultcode = isset($error->faultcode) ? (string) $error->faultcode : '';
        $faultstring = isset($error->faultstring) ? (string) $error->faultstring : $error->getMessage();
        $faultactor = isset($error->faultactor) ? (string) $error->faultactor : '';
        $detail = $error->detail ?? null;
        $name = isset($error->_name) ? (string) $error->_name : '';
        $thrown = VM\BuiltinExceptionSupport::materializeSoapFault(
            $this->context,
            $error->getMessage(),
            $file,
            $line,
            $faultcode,
            $faultstring,
            $faultactor,
            $detail,
            $name
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native FFI\Exception / FFI\ParserException from ext/ffi builtins (#4420). */
    private function dispatchVmFfiException(\FFI\Exception $error, Frame $frame, bool $parser): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeFfiException(
            $this->context,
            $error->getMessage(),
            $file,
            $line,
            $parser || $error instanceof \FFI\ParserException
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native SQLite3Exception from ext/sqlite3 builtins (#19862). */
    private function dispatchVmSQLite3Exception(\SQLite3Exception $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeSQLite3Exception(
            $this->context,
            $error->getMessage(),
            $file,
            $line,
            (int) $error->getCode()
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native mysqli_sql_exception from ext/mysqli builtins (#21803, #21815, #22456). */
    private function dispatchVmMysqliSqlException(\mysqli_sql_exception $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $sqlstate = '00000';
        if (\method_exists($error, 'getSqlState')) {
            $sqlstate = (string) $error->getSqlState();
        }
        $thrown = VM\BuiltinExceptionSupport::materializeMysqliSqlException(
            $this->context,
            $error->getMessage(),
            $file,
            $line,
            (int) $error->getCode(),
            $sqlstate
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native PharException from ext/phar builtins (#21232). */
    private function dispatchVmPharException(\PharException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializePharException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native RuntimeException from SPL file builtins (#6393, ext/spl/spl_directory.c). */
    private function dispatchVmRuntimeException(\RuntimeException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeRuntimeException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native UnexpectedValueException from DirectoryIterator (#3635 family). */
    private function dispatchVmUnexpectedValueException(\UnexpectedValueException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeUnexpectedValueException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /**
     * Bridge native TypeError from VM internals into user catch handlers (#3445).
     */
    private function dispatchVmTypeError(\TypeError $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeTypeError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** ASSIGN to ArrayAccess lvalue — dispatch deferred offsetSet TypeError (#8949). */
    private function assignCopyFrom(Variable $dst, Variable $src, Frame $frame): ?Frame
    {
        try {
            $resolved = $dst->resolveIndirect();
            if (null !== $resolved->objectPropertyOwner && null !== $resolved->objectPropertyName) {
                try {
                    ext\dom\DomDocumentPropertySupport::rejectReadOnlyPropertyWrite(
                        $resolved->objectPropertyOwner,
                        $resolved->objectPropertyName
                    );
                    ext\dom\DomNodePropertySupport::rejectReadOnlyPropertyWrite(
                        $resolved->objectPropertyOwner,
                        $resolved->objectPropertyName
                    );
                    VM\DatePeriodSupport::rejectReadOnlyPropertyWrite(
                        $resolved->objectPropertyOwner,
                        $resolved->objectPropertyName
                    );
                } catch (\Error $e) {
                    return $this->dispatchVmError($e->getMessage(), $frame);
                }
                if (ext\dom\DomNodePropertySupport::tryAssign(
                    $resolved->objectPropertyOwner,
                    $resolved->objectPropertyName,
                    $src,
                    $this->context
                )) {
                    return null;
                }
                if (ext\dom\DomDocumentPropertySupport::tryAssign(
                    $resolved->objectPropertyOwner,
                    $resolved->objectPropertyName,
                    $src,
                    $this->context
                )) {
                    return null;
                }
                if (ext\dom\DomHtmlDocumentPropertySupport::tryAssign(
                    $resolved->objectPropertyOwner,
                    $resolved->objectPropertyName,
                    $src,
                    $this->context
                )) {
                    return null;
                }
                if (ext\dom\DomHtmlElementPropertySupport::tryAssign(
                    $resolved->objectPropertyOwner,
                    $resolved->objectPropertyName,
                    $src,
                    $this->context
                )) {
                    return null;
                }
                if (ext\dom\DomTokenListPropertySupport::tryAssign(
                    $resolved->objectPropertyOwner,
                    $resolved->objectPropertyName,
                    $src,
                    $this->context
                )) {
                    return null;
                }
            }
            $dst->copyFrom($src);

            return null;
        } catch (\TypeError $e) {
            $resolved = $dst->resolveIndirect();
            if ($resolved->isArrayAccessOffset()) {
                $dst->null();
            }

            return $this->dispatchVmTypeError($e, $frame);
        } catch (\ValueError $e) {
            return $this->dispatchVmValueError($e, $frame);
        } catch (\OutOfBoundsException $e) {
            // SplFixedArray OOB under PROFILE≥8.4 — before RuntimeException (parent) (#28819).
            $resolved = $dst->resolveIndirect();
            if ($resolved->isArrayAccessOffset()) {
                $dst->null();
            }

            return $this->dispatchVmOutOfBoundsException($e, $frame);
        } catch (\RuntimeException $e) {
            // ArrayAccess dim write (e.g. SplFixedArray OOB) — same bridge as method calls (#21994).
            $resolved = $dst->resolveIndirect();
            if ($resolved->isArrayAccessOffset()) {
                $dst->null();
            }

            return $this->dispatchVmRuntimeException($e, $frame);
        } catch (\LogicException $e) {
            $resolved = $dst->resolveIndirect();
            if ($resolved->isArrayAccessOffset()) {
                $dst->null();
            }

            return $this->dispatchVmLogicException($e, $frame);
        } catch (\Error $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }
    }

    /**
     * Bridge native ArgumentCountError from stdlib builtins into user catch handlers (#4034).
     */
    private function dispatchVmArgumentCountError(\ArgumentCountError $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeArgumentCountError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /**
     * Bridge native DivisionByZeroError from numeric ops into user catch handlers (#3562, #3371).
     */
    private function dispatchVmDivisionByZeroError(\DivisionByZeroError $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeDivisionByZeroError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /**
     * Bridge native ArithmeticError from stdlib builtins into user catch handlers (#4724).
     */
    private function dispatchVmArithmeticError(\ArithmeticError $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeArithmeticError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /**
     * Bridge native ValueError from stdlib builtins into user catch handlers (#3763).
     */
    private function dispatchVmValueError(\ValueError $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeValueError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native AssertionError from assert() into user catch handlers (#3316). */
    private function dispatchVmAssertionError(\AssertionError $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeAssertionError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /**
     * Coerce a runtime operand to string for property/var names (Zend zend_operators.c, #6206).
     *
     * @return array{0: string|null, 1: Frame|null}
     */
    private function coerceRuntimeOperandToString(Variable $operand, Frame $frame): array
    {
        try {
            return [$operand->resolveIndirect()->toString($this, $frame), null];
        } catch (\Error $e) {
            return [null, $this->dispatchVmError($e->getMessage(), $frame)];
        }
    }

    /**
     * Reject property names starting with null byte (Zend zend_verify_property_name, #5136).
     *
     * @return ?Frame catch frame when handled; null when name valid
     */
    private function enforcePropertyName(string $name, Frame $frame): ?Frame
    {
        $message = VM\PropertyNameSupport::leadingNullByteMessage($name);
        if (null === $message) {
            return null;
        }

        return $this->dispatchVmError($message, $frame);
    }

    /**
     * Bridge VM Error throws (enum clone guard, echo __toString, etc.) into user catch handlers (#3554, #3564).
     */
    /** Zend object_and_properties_init unknown class message (zend_execute.c). */
    private function classNotFoundMessage(string $className): string
    {
        return sprintf('Class "%s" not found', $className);
    }

    private function dispatchVmError(string $message, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeError($this->context, $message, $file, $line);

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /**
     * Run lazy ghost/proxy init; convert host Error/TypeError into catchable VM throwables (#29151).
     *
     * Captures the init throwable on the lazy object (getLazyInitializationException) before
     * dispatch — ensureInitialized()'s finally clears lazyInitializingObject before this catch.
     */
    private function ensureLazyObjectInitialized(ObjectEntry $object, Frame $frame): ?Frame
    {
        try {
            VM\LazyObjectSupport::ensureInitialized($this, $object);
        } catch (\TypeError $e) {
            $thrown = $this->makeEngineError($e->getMessage(), 'TypeError');
            VM\LazyObjectSupport::captureLazyInitException($object, $thrown);

            return $this->dispatchVmTypeError($e, $frame);
        } catch (\Error $e) {
            $thrown = $this->makeEngineError($e->getMessage());
            VM\LazyObjectSupport::captureLazyInitException($object, $thrown);

            return $this->dispatchVmError($e->getMessage(), $frame);
        }

        return null;
    }

    /** php-src ext/dom/php_dom.c + DatePeriod write handlers (#15550, #20605, #26154). */
    private function enforceDomDocumentReadOnlyPropertyWrite(
        ObjectEntry $object,
        string $name,
        Frame $frame
    ): ?Frame {
        try {
            ext\dom\DomDocumentPropertySupport::rejectReadOnlyPropertyWrite($object, $name);
            ext\dom\DomNodePropertySupport::rejectReadOnlyPropertyWrite($object, $name);
            VM\DatePeriodSupport::rejectReadOnlyPropertyWrite($object, $name);
        } catch (\Error $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }

        return null;
    }

    private function dispatchVmCompileError(\CompileError $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeCompileError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /**
     * Catchable CompileError from eval() — Zend zend_throw_exception file shape (#25114).
     *
     * php-src: zif_eval / zend_eval_string — exception file is parent(line) : eval()'d code.
     */
    private function dispatchVmEvalCompileError(\CompileError $error, Frame $frame): ?Frame
    {
        $evalLine = 1;
        if ($error instanceof \PHPCompiler\Compiler\CompileFatal && $error->sourceLine > 0) {
            // wrapEvalCode prepends "<?php\n" — map compiler line back to eval body (#22796).
            $evalLine = $error->sourceLine > 1 ? $error->sourceLine - 1 : max(1, $error->sourceLine);
        } elseif ($error->getCode() > 0) {
            $evalLine = $error->getCode();
        }
        [$file, $line] = VM\ExceptionSupport::evalFatalSite($frame, $evalLine);
        $thrown = VM\BuiltinExceptionSupport::materializeCompileError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /**
     * Zend E_COMPILE_ERROR during eval() — uncatchable CLI fatal (#22922).
     *
     * php-src: zend_error_noreturn(E_COMPILE_ERROR, …) from zend_inheritance.c;
     * file shape Command line code(N) : eval()'d code (zif_eval / #4410).
     *
     * @return never
     */
    private function raiseEvalCompileFatal(\CompileError $error, Frame $frame): never
    {
        $evalLine = 1;
        if ($error instanceof \PHPCompiler\Compiler\CompileFatal && $error->sourceLine > 0) {
            // wrapEvalCode prepends "<?php\n" — map compiler line back to eval body (#22796).
            $evalLine = $error->sourceLine > 1 ? $error->sourceLine - 1 : max(1, $error->sourceLine);
        }
        [$file, $line] = VM\ExceptionSupport::evalFatalSite($frame, $evalLine);
        if ($this->context->isolatedPhpFunctionInvoke || $this->context->bubbleUncaughtToNative) {
            throw $error;
        }
        $this->context->errors->recordLastError(
            VM\ErrorReporter::E_COMPILE_ERROR,
            $error->getMessage(),
            $file,
            $line
        );
        VM\ErrorReporter::writeCliErrorOutput(
            VM\ErrorReporter::E_COMPILE_ERROR,
            $error->getMessage(),
            $file,
            $line,
            $this->context->errors->getDisplayErrors()
        );
        throw new ScriptExit(255);
    }

    /**
     * Zend E_COMPILE_ERROR during class declare (include/require / top-level) — uncatchable (#25384).
     *
     * @return never
     */
    private function raiseClassDeclareCompileFatal(\CompileError $error, Frame $frame): never
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        if ($this->context->isolatedPhpFunctionInvoke || $this->context->bubbleUncaughtToNative) {
            throw $error;
        }
        $this->context->errors->recordLastError(
            VM\ErrorReporter::E_COMPILE_ERROR,
            $error->getMessage(),
            $file,
            $line
        );
        VM\ErrorReporter::writeCliErrorOutput(
            VM\ErrorReporter::E_COMPILE_ERROR,
            $error->getMessage(),
            $file,
            $line,
            $this->context->errors->getDisplayErrors()
        );
        throw new ScriptExit(255);
    }

    private function dispatchVmParseError(\ParseError $error, Frame $frame): ?Frame
    {
        $evalLine = $error->getCode() > 0 ? $error->getCode() : 1;
        [$file, $line] = VM\ExceptionSupport::evalFatalSite($frame, $evalLine);
        $thrown = VM\BuiltinExceptionSupport::materializeParseError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native ReflectionException from reflection builtins into user catch handlers (#7344). */
    private function dispatchVmReflectionException(\ReflectionException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeReflectionException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native JsonException from ext/json builtins into user catch handlers (#3281). */
    private function dispatchVmJsonException(\JsonException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeJsonException(
            $this->context,
            $error->getMessage(),
            $file,
            $line,
            $error->getCode()
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native DOMException from ext/dom builtins into user catch handlers (#15430). */
    private function dispatchVmDomException(\DOMException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeDomException(
            $this->context,
            $error->getMessage(),
            $file,
            $line,
            $error->getCode()
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native SodiumException from ext/sodium builtins into user catch handlers (#15531). */
    private function dispatchVmSodiumException(\SodiumException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeSodiumException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native IntlException from ext/intl builtins into user catch handlers (#22577). */
    private function dispatchVmIntlException(\IntlException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeIntlException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native RedisException from ext/redis builtins into user catch handlers (#6098). */
    private function dispatchVmRedisException(\RedisException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeRedisException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native RedisClusterException into user catch handlers (#28094). */
    private function dispatchVmRedisClusterException(\RedisClusterException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeRedisClusterException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native RarException from ext/rar builtins into user catch handlers (#6237). */
    private function dispatchVmRarException(\RarException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeRarException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    private function dispatchVmSimdJsonException(
        \PHPCompiler\ext\simdjson\SimdJsonException $error,
        Frame $frame
    ): ?Frame {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeSimdJsonException(
            $this->context,
            $error->getMessage(),
            $file,
            $line,
            $error->getCode()
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    private function dispatchVmSimdJsonValueError(
        \PHPCompiler\ext\simdjson\SimdJsonValueError $error,
        Frame $frame
    ): ?Frame {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeSimdJsonValueError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge native DateInvalidTimeZoneException from date builtins into user catch handlers (#7279). */
    private function dispatchVmDateInvalidTimeZoneException(
        VM\NativeDateInvalidTimeZoneException $error,
        Frame $frame
    ): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeDateInvalidTimeZoneException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge malformed DateTime strings from date builtins into user catch handlers (#7113). */
    private function dispatchVmDateMalformedStringException(
        VM\NativeDateMalformedStringException $error,
        Frame $frame
    ): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeDateMalformedStringException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge illegal date operations from date builtins into user catch handlers (#6048). */
    private function dispatchVmDateInvalidOperationException(
        VM\NativeDateInvalidOperationException $error,
        Frame $frame
    ): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeDateInvalidOperationException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge malformed DateInterval specs into DateMalformedIntervalStringException (#20779). */
    private function dispatchVmDateMalformedIntervalException(
        VM\NativeDateMalformedIntervalException $error,
        Frame $frame
    ): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeDateMalformedIntervalStringException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge malformed DatePeriod ISO8601 specs from date builtins into user catch handlers (#7296). */
    private function dispatchVmDateMalformedPeriodStringException(
        VM\NativeDateMalformedPeriodStringException $error,
        Frame $frame
    ): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeDateMalformedPeriodStringException(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge DateRangeError from date builtins into user catch handlers (#7276). */
    private function dispatchVmDateRangeError(VM\NativeDateRangeError $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeDateRangeError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /** Bridge DateObjectError from date builtins into user catch handlers (#7276). */
    private function dispatchVmDateObjectError(VM\NativeDateObjectError $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeDateObjectError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /**
     * Bridge native FiberError from fiber lifecycle operations into user catch handlers (#4372).
     */
    private function dispatchVmFiberError(VM\NativeFiberError $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeFiberError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    /**
     * Guard fiber call depth before entering a callee frame (#7267; php-src zend_call_stack_size_error).
     */
    private function guardFiberStackBeforeCall(Frame $frame): ?Frame
    {
        if (null === $this->context->currentFiber || !VM\FiberStackLimit::wouldOverflow($this->context)) {
            return null;
        }

        return $this->dispatchVmFiberStackOverflow($frame);
    }

    private function dispatchVmFiberStackOverflow(Frame $frame): ?Frame
    {
        $fiber = $this->context->currentFiber;
        if (null !== $fiber) {
            [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
            $thrown = VM\BuiltinExceptionSupport::materializeFiberStackOverflow(
                $this->context,
                VM\FiberStackLimit::stackSizeErrorMessage(),
                $file,
                $line
            );
            $this->context->pendingException = $thrown;
            for ($handler = $frame; null !== $handler; $handler = $handler->parent) {
                if ($handler->fiberState !== $fiber && $this->findFiberState($handler) !== $fiber) {
                    break;
                }
                $catchFrame = $this->dispatchCatchForHandlerFrame($handler, $frame);
                if (null !== $catchFrame) {
                    $catchFrame->fiberState = $fiber;
                    $fiber->frame = $catchFrame;

                    return $catchFrame;
                }
            }
            $this->clearTryCatchUnwindState();
            $fiber->status = FiberState::STATUS_TERMINATED;
            $fiber->frame = null;
            $fiber->hasReturnValue = false;
            $fiber->threw = true;

            throw new VM\NativeFiberStackOverflow(VM\FiberStackLimit::stackSizeErrorMessage());
        }

        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeFiberStackOverflow(
            $this->context,
            VM\FiberStackLimit::stackSizeErrorMessage(),
            $file,
            $line
        );
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    private function dispatchVmFiberStackOverflowFromNative(
        VM\NativeFiberStackOverflow $error,
        Frame $frame
    ): ?Frame {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeFiberStackOverflow(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );

        return $this->dispatchBuiltinThrowable($frame, $thrown);
    }

    private function findCatchFrameForThrow(Frame $frame, Variable $thrown): ?Frame
    {
        $pending = $this->context->pendingException;
        if (null !== $pending && $this->frameIsInFinallyBody($frame)) {
            VM\ExceptionSupport::chainPendingExceptionOnFinallyThrow($thrown, $pending);
        }
        $this->stashPendingException($thrown);
        $handlers = $this->context->activeTryHandlerFrames;
        for ($i = \count($handlers) - 1; $i >= 0; --$i) {
            $handler = $handlers[$i];
            $catchFrame = $this->dispatchCatchForHandlerFrame($handler, $frame);
            if (null !== $catchFrame) {
                \array_splice($this->context->activeTryHandlerFrames, $i);
                if ($this->context->coercingObjectToString) {
                    $this->context->magicMethodThrowHandled = true;
                }
                if ($this->shouldDeferCatchToOuterRunFrames($i)) {
                    throw new VM\BuiltinCallbackCatchRedirect($catchFrame);
                }
                $this->redirectCloneMagicExternalCatch($handler, $catchFrame);

                return $catchFrame;
            }
        }
        for ($handler = $frame->parent ?? $frame; null !== $handler; $handler = $handler->parent) {
            // Only match try/catch on frames that entered TYPE_TRY — not handler opcodes on
            // ancestors before the try body runs (#14504).
            if (!\in_array($handler, $this->context->activeTryHandlerFrames, true)) {
                continue;
            }
            $catchFrame = $this->dispatchCatchForHandlerFrame($handler, $frame);
            if (null !== $catchFrame) {
                if ($this->context->coercingObjectToString) {
                    $this->context->magicMethodThrowHandled = true;
                }
                $handlerIndex = \array_search($handler, $this->context->activeTryHandlerFrames, true);
                if ($this->shouldDeferCatchToOuterRunFrames(
                    false !== $handlerIndex ? (int) $handlerIndex : 0
                )) {
                    throw new VM\BuiltinCallbackCatchRedirect($catchFrame);
                }
                $this->redirectCloneMagicExternalCatch($handler, $catchFrame);

                return $catchFrame;
            }
        }

        return null;
    }

    /**
     * True when a matched try handler must resume on the outer runFrames (#14104, #25816).
     *
     * {@see Context::$deferBuiltinCallbackCatchToOuterRunFrames} defers every match (isolated
     * callbacks). {@see Context::$deferCatchBelowTryHandlerDepth} defers only handlers that were
     * already active before a nested eval() — inner eval try/catch stays on the nested loop.
     */
    private function shouldDeferCatchToOuterRunFrames(int $handlerIndex): bool
    {
        if ($this->context->deferBuiltinCallbackCatchToOuterRunFrames) {
            return true;
        }
        $depth = $this->context->deferCatchBelowTryHandlerDepth;

        return null !== $depth && $handlerIndex < $depth;
    }

    /**
     * Resume a deferred user catch on the outer runFrames stack (#29521, #29534).
     *
     * {@see Context::truncateRunStackForCatch()} during __toString coercion runs on the
     * isolated empty stack from {@see invokePhpFunctionForCoercion()}; re-truncate here so
     * suspended try-body call sites (compare inside a callee) cannot resume AFTER catch.
     */
    private function resumeAfterBuiltinCallbackCatchRedirect(VM\BuiltinCallbackCatchRedirect $redirect): Frame
    {
        $handler = $this->context->activeCatchHandlerFrame;
        if (null !== $handler) {
            $this->context->truncateRunStackForCatch($handler);
        }

        return $redirect->catchFrame;
    }

    /**
     * When __clone() throws into a try/catch outside the isolated clone stack, defer the
     * catch to the clone opcode caller — do not goto restart on the nested stack (#23527).
     *
     * TYPE_TRY stores the pre-getFrame handler, so identity with run-stack frames is unreliable;
     * instead treat a handler as external when it is the clone caller or any of its ancestors.
     *
     * @throws VM\CloneMagicCatchRedirect
     */
    private function redirectCloneMagicExternalCatch(Frame $handler, Frame $catchFrame): void
    {
        if (!$this->context->invokingCloneMagic) {
            return;
        }
        $caller = $this->context->cloneMagicCallerFrame;
        if (null === $caller) {
            return;
        }
        for ($f = $caller; null !== $f; $f = $f->parent) {
            if ($f === $handler) {
                $this->context->cloneMagicExternalCatchFrame = $catchFrame;

                throw new VM\CloneMagicCatchRedirect($catchFrame);
            }
        }
    }

    private function dispatchCatchForHandlerFrame(Frame $handler, Frame $throwFrame): ?Frame
    {
        $this->rewindHandlerToCatchChain($handler);
        $catchFrame = $this->enterMatchingCatchHandler($handler);
        if (null !== $catchFrame) {
            // Catch frame holds the throwable; mirror onto the try handler so callee CV release
            // (#22541) can preserve it (pendingException was already cleared).
            if (null !== $catchFrame->activeCatchException) {
                $handler->activeCatchException = $catchFrame->activeCatchException;
            }
            $this->releaseCalleeObjectRefsBeforeExceptionHandler($throwFrame, $handler);

            return $catchFrame;
        }
        $finallyFrame = $this->enterFinallyHandlerForUnwind($handler, true);
        if (null !== $finallyFrame) {
            // Nested callees die before finally; handler-function locals stay until after finally.
            $this->releaseCalleeObjectRefsBeforeExceptionHandler($throwFrame, $handler);
            $this->context->pendingFinallyUnwindThrowFrame = $throwFrame;

            return $finallyFrame;
        }

        return null;
    }

    private function popTryHandlerIfAtMergeBlock(Frame $frame): void
    {
        if (null === $frame->block) {
            return;
        }
        $id = spl_object_id($frame->block);
        if (!isset($this->context->tryMergeBlockIds[$id])) {
            return;
        }
        unset($this->context->tryMergeBlockIds[$id]);
        if ([] !== $this->context->activeTryHandlerFrames) {
            \array_pop($this->context->activeTryHandlerFrames);
        }
    }

    private function resolveActiveCatchException(Frame $frame): ?Variable
    {
        for ($f = $frame; null !== $f; $f = $f->parent) {
            if (null !== $f->activeCatchException) {
                return $f->activeCatchException;
            }
        }

        return null;
    }

    /** Align handler position to the first TYPE_CATCH after TYPE_TRY (issue #1362). */
    private function rewindHandlerToCatchChain(Frame $handler): void
    {
        $ops = $handler->block->opCodes;
        $n = $handler->block->nOpCodes;
        for ($i = 0; $i < $n; ++$i) {
            if (!isset($ops[$i])) {
                continue;
            }
            if (OpCode::TYPE_TRY !== $ops[$i]->type) {
                continue;
            }
            for ($j = $i + 1; $j < $n; ++$j) {
                if (!isset($ops[$j])) {
                    continue;
                }
                if (OpCode::TYPE_CATCH === $ops[$j]->type) {
                    $handler->pos = $j;

                    return;
                }
                if (OpCode::TYPE_FINALLY === $ops[$j]->type) {
                    return;
                }
            }

            return;
        }
    }

    private function enterMatchingCatchHandler(Frame $handler): ?Frame
    {
        if (null === $this->context->pendingException) {
            return null;
        }
        while ($handler->pos < $handler->block->nOpCodes) {
            $op = $handler->block->opCodes[$handler->pos];
            if (OpCode::TYPE_CATCH !== $op->type) {
                if (OpCode::TYPE_FINALLY === $op->type) {
                    break;
                }

                return null;
            }
            $handler->pos++;
            if (!$this->catchTypesMatch($op, $this->context->pendingException)) {
                continue;
            }
            $caught = $this->context->pendingException;
            $this->context->pendingException = null;
            if (null !== $op->arg3) {
                if (!isset($handler->scope[$op->arg3])) {
                    $handler->scope[$op->arg3] = new Variable();
                }
                $handler->scope[$op->arg3]->copyFrom($caught);
            }
            // php-cfg may fuse an empty catch body with the merge block (no TYPE_JUMP edge out of
            // the catch). Ensure finally still runs before resuming the merge (#14959).
            if (
                null !== $op->block2
                && $op->block1 === $op->block2
                && $this->hasPendingFinally($handler)
            ) {
                $this->skipTryCatchHandlerTail($handler);
                $this->context->activeCatchHandlerFrame = $handler;
                $this->context->pendingMergeAfterFinally = $op->block2;
                $this->context->truncateRunStackForCatch($handler);
                $this->clearThrowDispatchState();

                return $this->enterFinallyHandlerForUnwind($handler, false);
            }
            $catchFrame = $op->block1->getFrame($this->context, $handler);
            $this->bindCatchVariableToFrame($catchFrame, $op->arg3, $caught);
            $gen = $this->findGeneratorState($handler);
            if (null !== $gen) {
                $catchFrame->generatorState = $gen;
            }
            $mergeFrame = null;
            if (null !== $op->block2) {
                $mergeFrame = $op->block2->getFrame($this->context, $handler);
                $mergeFrame->parent = $handler->parent;
                if (null !== $gen) {
                    $mergeFrame->generatorState = $gen;
                }
            }
            $this->skipTryCatchHandlerTail($handler);
            if (null !== $mergeFrame) {
                $handler->pos = $handler->block->nOpCodes;
                $catchFrame->parent = $mergeFrame;
            }
            $this->context->activeCatchHandlerFrame = $handler;
            // Abandon suspended try-body call sites (throw from callee/finally; #5331).
            $this->context->truncateRunStackForCatch($handler);
            $this->clearThrowDispatchState();

            return $catchFrame;
        }

        return null;
    }

    private function enterFinallyHandlerForUnwind(Frame $handler, bool $resumeCatchAfter = true): ?Frame
    {
        $handlerId = spl_object_id($handler);
        if (isset($this->context->completedFinallyHandlers[$handlerId])) {
            return null;
        }
        $finallyOp = $this->findFinallyOpForHandler($handler);
        if (null === $finallyOp || null === $finallyOp->block1) {
            return null;
        }
        $this->context->completedFinallyHandlers[$handlerId] = true;
        $this->context->pendingCatchResumeHandler = $resumeCatchAfter ? $handler : null;
        // When the finally block is fused with the try-merge block, prevent
        // popTryHandlerIfAtMergeBlock from removing an outer handler (#24728).
        unset($this->context->tryMergeBlockIds[spl_object_id($finallyOp->block1)]);
        return $finallyOp->block1->getFrame($this->context, $handler);
    }

    /** Run finally after a matching catch body before the try/catch merge block (Zend order). */
    private function beginCatchExitFinallyUnwind(Frame $frame, Block $target): ?Frame
    {
        // Catch bodies may reparent to the merge frame (handler frame is not necessarily in the
        // current parent chain); rely on the tracked active catch handler instead (#14959).
        if (!isset($this->context->tryMergeBlockIds[spl_object_id($target)])) {
            return null;
        }
        $handler = $this->context->activeCatchHandlerFrame;
        if (null === $handler || !$this->hasPendingFinally($handler)) {
            return null;
        }
        $this->context->pendingMergeAfterFinally = $target;
        $this->context->activeCatchHandlerFrame = null;

        return $this->enterFinallyHandlerForUnwind($handler, false);
    }

    private function resumeMergeAfterFinally(Frame $frame): ?Frame
    {
        $merge = $this->context->pendingMergeAfterFinally;
        if (null === $merge) {
            return null;
        }
        $this->context->pendingMergeAfterFinally = null;
        $this->context->activeCatchHandlerFrame = null;
        $frame->activeCatchException = null;
        $frame->catchVarSlot = null;

        return $merge->getFrame($this->context, $frame);
    }

    /** Bind catch `$e` and mark initialized — avoid #10358 false undefined warnings on catch reads. */
    private function bindCatchVariableToFrame(Frame $frame, ?int $catchVarSlot, Variable $caught): void
    {
        $frame->activeCatchException = $caught;
        if (null === $catchVarSlot) {
            $frame->catchVarSlot = null;

            return;
        }
        $frame->catchVarSlot = $catchVarSlot;
        if (!isset($frame->scope[$catchVarSlot])) {
            $frame->scope[$catchVarSlot] = new Variable();
        }
        $frame->scope[$catchVarSlot]->copyFrom($caught);
        $this->markScopeSlotInitialized($frame, $catchVarSlot);
    }

    private function resumeGotoAfterFinally(Frame $frame): ?Frame
    {
        $target = $this->context->pendingGotoAfterFinally;
        if (null === $target) {
            return null;
        }
        $this->context->pendingGotoAfterFinally = null;

        return $this->frameForBranch($frame, $target);
    }

    /**
     * Leaving a try body via goto / break / continue must run finally before the jump target
     * (Zend order, #4491 / #25240).
     *
     * php-cfg often wires break/continue (and try fall-through when those edges exist) straight
     * to the try merge block, skipping the finally CFG edge. Jumping to that merge with a pending
     * finally is an unwind. JumpIf edges that stay inside the try body must not unwind.
     */
    private function beginGotoFinallyUnwind(Frame $frame, Block $target): ?Frame
    {
        $handlers = $this->context->activeTryHandlerFrames;
        for ($i = \count($handlers) - 1; $i >= 0; --$i) {
            $handler = $handlers[$i];
            if (!$this->hasPendingFinally($handler)) {
                continue;
            }
            $finallyOp = $this->findFinallyOpForHandler($handler);
            if (null === $finallyOp || null === $finallyOp->block1) {
                continue;
            }
            if ($target === $finallyOp->block1) {
                continue;
            }
            if ($finallyOp->block1 === $frame->block) {
                continue;
            }
            if (!$this->frameIsDescendantOf($frame, $handler)) {
                continue;
            }
            $isMerge = isset($this->context->tryMergeBlockIds[spl_object_id($target)]);
            // Intra-try JumpIf (e.g. `if` fall-through inside the try body) is not a leave.
            if (!$isMerge && $this->blockIsInsideActiveTryBody($handler, $target)) {
                continue;
            }
            $this->context->pendingGotoAfterFinally = $target;

            return $this->enterFinallyHandlerForUnwind($handler, false);
        }

        return null;
    }

    /**
     * True when $target is part of the try body for $handler: reachable from the try entry
     * without crossing merge/finally, and able to reach the merge (or finally) afterward.
     * Leave edges such as `break` to the loop exit are successors of the try JumpIf but do
     * not reach the merge — those must still unwind (#25240).
     */
    private function blockIsInsideActiveTryBody(Frame $handler, Block $target): bool
    {
        $tryOp = $this->findTryOpForHandler($handler);
        if (null === $tryOp || null === $tryOp->block1) {
            return false;
        }
        $entry = $tryOp->block1;
        $merge = $tryOp->block2;
        $finallyOp = $this->findFinallyOpForHandler($handler);
        $finallyBlock = null !== $finallyOp ? $finallyOp->block1 : null;
        if ($target === $entry) {
            return true;
        }
        if ($target === $merge || $target === $finallyBlock) {
            return false;
        }
        $leaveBlocked = [];
        if (null !== $merge) {
            $leaveBlocked[spl_object_id($merge)] = true;
        }
        if (null !== $finallyBlock) {
            $leaveBlocked[spl_object_id($finallyBlock)] = true;
        }
        if (!$this->blockCanReach($entry, $target, $leaveBlocked)) {
            return false;
        }
        // Still inside the try region only if control can rejoin via merge/finally.
        if (null !== $merge && $this->blockCanReach($target, $merge, [])) {
            return true;
        }
        if (null !== $finallyBlock && $this->blockCanReach($target, $finallyBlock, [])) {
            return true;
        }

        return false;
    }

    /**
     * @param array<int, true> $blocked
     */
    private function blockCanReach(Block $from, Block $to, array $blocked): bool
    {
        if ($from === $to) {
            return true;
        }
        $seen = [];
        $queue = [$from];
        while ([] !== $queue) {
            /** @var Block $block */
            $block = \array_pop($queue);
            $id = spl_object_id($block);
            if (isset($seen[$id])) {
                continue;
            }
            $seen[$id] = true;
            foreach ($this->blockBranchTargets($block) as $succ) {
                if ($succ === $to) {
                    return true;
                }
                if (isset($blocked[spl_object_id($succ)])) {
                    continue;
                }
                $queue[] = $succ;
            }
        }

        return false;
    }

    /** @return list<Block> */
    private function blockBranchTargets(Block $block): array
    {
        $targets = [];
        foreach ($block->opCodes as $op) {
            foreach ([$op->block1, $op->block2, $op->block3] as $t) {
                if ($t instanceof Block) {
                    $targets[] = $t;
                }
            }
        }

        return $targets;
    }

    private function findTryOpForHandler(Frame $handler): ?OpCode
    {
        foreach ($handler->block->opCodes as $op) {
            if (OpCode::TYPE_TRY === $op->type) {
                return $op;
            }
        }

        return null;
    }

    private function frameIsDescendantOf(Frame $frame, Frame $ancestor): bool
    {
        for ($f = $frame; null !== $f; $f = $f->parent) {
            if ($f === $ancestor) {
                return true;
            }
        }

        return false;
    }

    private function findFinallyOpForHandler(Frame $handler): ?OpCode
    {
        foreach ($handler->block->opCodes as $op) {
            if (OpCode::TYPE_FINALLY === $op->type) {
                return $op;
            }
        }

        return null;
    }

    private function resumeCatchAfterFinally(Frame $frame): ?Frame
    {
        $handler = $this->context->pendingCatchResumeHandler;
        if (null === $handler) {
            return null;
        }
        $this->context->pendingCatchResumeHandler = null;
        $this->rewindHandlerToCatchChain($handler);
        $catchFrame = $this->enterMatchingCatchHandler($handler);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $thrown = $this->context->pendingException;
        if (null === $thrown) {
            return null;
        }
        // Leaving this try/finally function scope: destroy locals before outer catch (#22541).
        $throwFrame = $this->context->pendingFinallyUnwindThrowFrame;
        $this->context->pendingFinallyUnwindThrowFrame = null;
        $this->releaseHandlerScopeObjectRefsOnExceptionLeave($handler, $throwFrame);
        // Generator try/finally must bubble to the resume caller via GeneratorUncaughtThrow —
        // not findCatchFrameForThrow into a caller try that is isolated during advance (#22869).
        $gen = $this->findGeneratorState($handler);
        if (null === $gen && null !== $throwFrame) {
            $gen = $this->findGeneratorState($throwFrame);
        }
        if (null !== $gen) {
            $gen->frame = null;
            $gen->markClosedWithoutReturn();
            throw new VM\GeneratorUncaughtThrow($thrown, $throwFrame ?? $handler);
        }
        $fiber = $this->context->currentFiber;
        if (null !== $fiber && (
            $this->findFiberState($handler) === $fiber
            || (null !== $throwFrame && $this->findFiberState($throwFrame) === $fiber)
        )) {
            throw new VM\FiberUncaughtThrow($thrown);
        }
        $outerCatch = $this->findCatchFrameForThrow($handler->parent ?? $handler, $thrown);
        if (null !== $outerCatch) {
            return $outerCatch;
        }
        $this->raiseUncaughtException($thrown);
    }

    private function clearThrowDispatchState(): void
    {
        $this->context->pendingException = null;
        $this->context->pendingCatchResumeHandler = null;
        $this->context->pendingFinallyUnwindThrowFrame = null;
        $this->context->completedFinallyHandlers = [];
    }

    private function clearTryCatchUnwindState(): void
    {
        $this->clearThrowDispatchState();
        $this->context->activeCatchHandlerFrame = null;
        $this->context->pendingMergeAfterFinally = null;
        $this->context->pendingGotoAfterFinally = null;
        $this->clearPendingReturnState();
    }

    private function clearPendingReturnState(): void
    {
        $this->context->pendingReturnActive = false;
        $this->context->pendingReturnDispatch = false;
        $this->context->pendingReturnIsVoid = true;
        $this->context->pendingReturnValue = null;
        $this->context->pendingReturnResumeFrame = null;
    }

    /** Snapshot throw operand so scope reuse cannot clobber pending try exceptions (#5867, #6457). */
    private function stashPendingException(Variable $thrown): void
    {
        if (null !== $this->context->lazyInitializingObject) {
            VM\LazyObjectSupport::captureLazyInitException(
                $this->context->lazyInitializingObject,
                $thrown
            );
        }
        if (null === $this->context->pendingException) {
            $this->context->pendingException = new Variable();
        }
        $this->context->pendingException->copyFrom($thrown);
    }

    private function hasPendingFinally(Frame $handler): bool
    {
        if (null === $this->findFinallyOpForHandler($handler)) {
            return false;
        }

        return !isset($this->context->completedFinallyHandlers[spl_object_id($handler)]);
    }

    private function frameIsInFinallyBody(Frame $frame): bool
    {
        return null !== $this->findFinallyOpForFrameBody($frame);
    }

    /**
     * True when executing a finally CFG block that is distinct from the try merge block.
     * Empty `finally {}` often fuses with the merge (#24728); those epilogues must not be
     * treated as an explicit `return` inside finally (#25239).
     */
    private function frameIsInDistinctFinallyBody(Frame $frame): bool
    {
        $finallyOp = $this->findFinallyOpForFrameBody($frame);
        if (null === $finallyOp) {
            return false;
        }

        return $finallyOp->block1 !== $finallyOp->block2;
    }

    private function findFinallyOpForFrameBody(Frame $frame): ?OpCode
    {
        for ($handler = $frame->parent; null !== $handler; $handler = $handler->parent) {
            $finallyOp = $this->findFinallyOpForHandler($handler);
            if (null === $finallyOp || null === $finallyOp->block1) {
                continue;
            }
            if ($finallyOp->block1 === $frame->block) {
                return $finallyOp;
            }
        }

        return null;
    }

    /**
     * Leaving a finally body (via jump or fused merge RETURN_VOID) — continue return/catch/merge chains.
     *
     * @return bool true when the caller should goto restart (frame updated or pending return scheduled)
     */
    private function completeActiveFinallyUnwind(Frame &$frame): bool
    {
        if (!$this->frameIsInFinallyBody($frame)) {
            return false;
        }
        $this->markFinallyCompletedWhenLeavingFinallyBody($frame);
        $finallyFrame = $this->continueReturnFinallyChain();
        if (null !== $finallyFrame) {
            $frame = $finallyFrame;

            return true;
        }
        if ($this->schedulePendingReturnDispatch()) {
            return true;
        }
        $resumeFrame = $this->resumeCatchAfterFinally($frame);
        if (null !== $resumeFrame) {
            $frame = $resumeFrame;

            return true;
        }
        $mergeFrame = $this->resumeMergeAfterFinally($frame);
        if (null !== $mergeFrame) {
            $frame = $mergeFrame;

            return true;
        }
        // Nested try/finally: run outer finally before the pending break/continue/goto (#25240).
        if (null !== $this->context->pendingGotoAfterFinally) {
            $outerFinally = $this->beginGotoFinallyUnwind(
                $frame,
                $this->context->pendingGotoAfterFinally
            );
            if (null !== $outerFinally) {
                $frame = $outerFinally;

                return true;
            }
        }
        $gotoFrame = $this->resumeGotoAfterFinally($frame);
        if (null !== $gotoFrame) {
            $frame = $gotoFrame;

            return true;
        }

        return false;
    }

    /** Normal try completion runs the finally CFG block directly; mark it done (#3082). */
    private function markFinallyCompletedWhenLeavingFinallyBody(Frame $frame): void
    {
        if (!$this->frameIsInFinallyBody($frame)) {
            return;
        }
        for ($handler = $frame->parent; null !== $handler; $handler = $handler->parent) {
            $finallyOp = $this->findFinallyOpForHandler($handler);
            if (null === $finallyOp || null === $finallyOp->block1) {
                continue;
            }
            if ($finallyOp->block1 !== $frame->block) {
                continue;
            }
            $this->context->completedFinallyHandlers[spl_object_id($handler)] = true;

            return;
        }
    }

    private function findNextFinallyHandlerForReturn(Frame $from): ?Frame
    {
        // Catch frames may skip the handler frame in their parent chain; still need to run the
        // handler finally on `return` from catch (#14959).
        if (
            null !== $this->context->activeCatchHandlerFrame
            && null !== $this->resolveActiveCatchException($from)
            && $this->hasPendingFinally($this->context->activeCatchHandlerFrame)
        ) {
            $catchHandler = $this->context->activeCatchHandlerFrame;
            // Do not run a caller's finally when a nested call (e.g. __construct) returns (#22541).
            if (($catchHandler->block->func ?? null) === ($from->block->func ?? null)) {
                return $catchHandler;
            }
        }
        // Only finally blocks in the returning function — nested callees must not trigger the
        // caller's try/finally (Zend; premature finally after `new` broke exception dtor order #22541).
        $fromFunc = $from->block->func ?? null;
        for ($handler = $from->parent; null !== $handler; $handler = $handler->parent) {
            if (($handler->block->func ?? null) !== $fromFunc) {
                break;
            }
            if ($this->hasPendingFinally($handler)) {
                return $handler;
            }
        }

        return null;
    }

    private function beginReturnFinallyUnwind(Frame $frame, ?Variable $value, bool $isVoid): ?Frame
    {
        $handler = $this->findNextFinallyHandlerForReturn($frame);
        if (null === $handler) {
            return null;
        }
        $this->context->pendingReturnActive = true;
        $this->context->pendingReturnIsVoid = $isVoid;
        $this->context->pendingReturnValue = $value;
        $this->context->pendingReturnResumeFrame = $frame;

        return $this->enterFinallyHandlerForUnwind($handler, true);
    }

    /**
     * Zend: return inside finally replaces any pending try/catch return and clears a pending
     * exception so the finally return value is what the caller observes (#25239).
     *
     * php-src: Zend/zend_vm_def.h (finally return / ZEND_FAST_CALL), Zend/zend_execute.c
     *
     * @return bool true when the caller should goto restart (outer finally or pending dispatch)
     */
    private function applyReturnInsideFinally(Frame &$frame, ?Variable $value, bool $isVoid): bool
    {
        // Suppress pending try exception — finally return wins over EG(exception).
        $this->context->pendingException = null;
        $this->context->pendingCatchResumeHandler = null;
        $this->context->pendingFinallyUnwindThrowFrame = null;
        $this->context->activeCatchHandlerFrame = null;
        $this->context->pendingMergeAfterFinally = null;
        $this->context->pendingGotoAfterFinally = null;

        $this->context->pendingReturnActive = true;
        $this->context->pendingReturnIsVoid = $isVoid;
        $this->context->pendingReturnValue = $value;
        $this->context->pendingReturnResumeFrame = $frame;
        $this->context->pendingReturnDispatch = false;

        $this->markFinallyCompletedWhenLeavingFinallyBody($frame);

        $finallyFrame = $this->continueReturnFinallyChain();
        if (null !== $finallyFrame) {
            $frame = $finallyFrame;

            return true;
        }
        if ($this->schedulePendingReturnDispatch()) {
            return true;
        }

        // No outer finally left — complete the return from this opcode now.
        $this->clearPendingReturnState();

        return false;
    }

    private function continueReturnFinallyChain(): ?Frame
    {
        if (!$this->context->pendingReturnActive || null === $this->context->pendingReturnResumeFrame) {
            return null;
        }
        $handler = $this->findNextFinallyHandlerForReturn($this->context->pendingReturnResumeFrame);
        if (null === $handler) {
            return null;
        }

        return $this->enterFinallyHandlerForUnwind($handler, true);
    }

    private function schedulePendingReturnDispatch(): bool
    {
        if (!$this->context->pendingReturnActive || null === $this->context->pendingReturnResumeFrame) {
            return false;
        }
        if (null !== $this->findNextFinallyHandlerForReturn($this->context->pendingReturnResumeFrame)) {
            return false;
        }
        $this->context->pendingReturnDispatch = true;

        return true;
    }

    /** @return never */
    private function raiseUncaughtException(Variable $thrown): void
    {
        $this->clearTryCatchUnwindState();
        if ($this->context->exceptionHandlers->dispatch($this->context, $thrown)) {
            throw new ScriptExit(0);
        }
        if (Variable::TYPE_OBJECT === $thrown->type) {
            $entry = $thrown->toObject();
            $native = VM\ExceptionSupport::nativeUncaughtThrowable(
                $entry,
                VM\ExceptionSupport::readThrowableMessage($entry)
            );
            if ($this->context->isolatedPhpFunctionInvoke || $this->context->bubbleUncaughtToNative) {
                throw $native;
            }
            VM\ExceptionSupport::emitNativeUncaughtFatal(
                $native,
                $entry,
                $this->context->errors->getDisplayErrors(),
            );
        }
        throw new \Exception($thrown->toString());
    }

    /** @return never */
    private function raiseUncaughtExceptionWithNext(Variable $primary, Variable $next): void
    {
        $this->clearTryCatchUnwindState();
        if ($this->context->exceptionHandlers->dispatch($this->context, $primary)) {
            throw new ScriptExit(0);
        }
        if (Variable::TYPE_OBJECT !== $primary->type || Variable::TYPE_OBJECT !== $next->type) {
            $this->raiseUncaughtException($primary);
        }
        $primaryEntry = $primary->toObject();
        $nextEntry = $next->toObject();
        if ($this->context->isolatedPhpFunctionInvoke || $this->context->bubbleUncaughtToNative) {
            throw VM\ExceptionSupport::nativeUncaughtThrowable(
                $primaryEntry,
                VM\ExceptionSupport::readThrowableMessage($primaryEntry)
            );
        }
        VM\ExceptionSupport::emitNativeUncaughtFatalWithNext(
            VM\ExceptionSupport::nativeUncaughtThrowable(
                $primaryEntry,
                VM\ExceptionSupport::readThrowableMessage($primaryEntry)
            ),
            VM\ExceptionSupport::nativeUncaughtThrowable(
                $nextEntry,
                VM\ExceptionSupport::readThrowableMessage($nextEntry)
            ),
            $this->context->errors->getDisplayErrors(),
        );
    }

    /**
     * After a catch match, skip remaining TYPE_CATCH / CFG entry TYPE_JUMP on the handler
     * block so merge fallthrough does not re-enter the try body (#2084).
     */
    private function skipTryCatchHandlerTail(Frame $handler): void
    {
        while ($handler->pos < $handler->block->nOpCodes) {
            $op = $handler->block->opCodes[$handler->pos];
            if (OpCode::TYPE_CATCH === $op->type || OpCode::TYPE_FINALLY === $op->type) {
                $handler->pos++;
                continue;
            }
            if (OpCode::TYPE_JUMP === $op->type) {
                $handler->pos++;
                continue;
            }
            break;
        }
    }

    private function catchTypesMatch(OpCode $op, Variable $thrown): bool
    {
        return VM\VmTryCatch::encodedTypesMatchOpcode($op, $thrown, $this->context);
    }

    private function valueInstanceOfClassName(Variable $value, string $className): bool
    {
        $resolved = $value->resolveIndirect();
        $enumMatch = VM\EnumCaseSupport::valueMatchesInstanceOfClassName(
            $value,
            $className,
            $this->context
        );
        if (null !== $enumMatch) {
            return $enumMatch;
        }
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            return false;
        }
        $className = strtolower(ltrim($className, '\\'));
        $entry = $resolved->toObject()->class;
        $target = $this->context->classes[$className] ?? null;
        if (null !== $target && $target->isInterface) {
            return VM\InterfaceCheck::entryImplements($entry, $className, $this->context);
        }

        return VM\InterfaceCheck::entryIsInstanceOf($entry, $className, $this->context);
    }

    private function frameIsPropertySetHook(Frame $frame): bool
    {
        $func = $frame->block->func ?? null;
        if (null === $func) {
            return false;
        }
        $name = strtolower($func->name);

        return str_contains($name, '__phpc_property_set_');
    }

    /**
     * Route catchable hook failures to the caller stack (#9670, #10005, zend_property_hooks.c).
     */
    private function stashPropertyHookSetExternalCatch(Frame $frame, Frame $catchFrame): bool
    {
        if (
            null === $frame->propertyHookRawProperty
            && !$this->frameIsPropertySetHook($frame)
            && !$this->frameIsPropertyGetHook($frame)
        ) {
            return false;
        }
        $this->context->propertyHookExternalCatchFrame = $catchFrame;

        return true;
    }

    private function shouldAbortPropertyHookInvocation(Frame $frame): bool
    {
        if (null === $this->context->propertyHookExternalCatchFrame) {
            return false;
        }
        if (null === $frame->propertyHookRawProperty && !$this->frameIsPropertySetHook($frame)) {
            return false;
        }
        $this->context->propertyHookSetAborted = true;

        return true;
    }

    private function frameIsPropertyGetHook(Frame $frame): bool
    {
        $func = $frame->block->func ?? null;
        if (null === $func) {
            return false;
        }
        $name = strtolower($func->name);

        return str_contains($name, '__phpc_property_get_');
    }

    private function frameIsPropertyUnsetHook(Frame $frame): bool
    {
        $func = $frame->block->func ?? null;
        if (null === $func) {
            return false;
        }
        $name = strtolower($func->name);

        return str_contains($name, '__phpc_property_unset_');
    }

    private function isPropertyHookRawWrite(Frame $frame, string $propName): bool
    {
        if ($propName === $frame->propertyHookRawProperty) {
            return true;
        }
        $func = $frame->block->func ?? null;
        if (null === $func || null === $func->class) {
            return false;
        }
        $className = $func->class->value ?? null;
        if (!is_string($className) || '' === $className) {
            return false;
        }
        $methodLc = strtolower((string) $func->name);
        if (str_contains($methodLc, '::')) {
            $methodLc = substr($methodLc, strrpos($methodLc, '::') + 2);
        }
        $wantSet = strtolower(SourcePreprocessor\PropertyHooks::setHookMethodName($propName));
        $wantGet = strtolower(SourcePreprocessor\PropertyHooks::getHookMethodName($propName));
        $wantUnset = strtolower(SourcePreprocessor\PropertyHooks::unsetHookMethodName($propName));

        return $methodLc === $wantSet
            || $methodLc === $wantGet
            || $methodLc === $wantUnset
            || $methodLc === strtolower($className.'::'.$wantSet)
            || $methodLc === strtolower($className.'::'.$wantGet)
            || $methodLc === strtolower($className.'::'.$wantUnset);
    }

    private function linkStaticTypedPropertySlot(Variable $storage, ClassEntry $entry, string $propDisplayName): void
    {
        // Always keep declared casing for property_exists() exact match (#23532).
        $storage->objectPropertyName = $propDisplayName;
        if (!$storage->hasDeclaredTypeConstraint()) {
            return;
        }
        $storage->staticPropertyClassLc = strtolower($entry->name);
    }

    private function linkStaticPropertyHooks(ClassEntry $entry): void
    {
        foreach (array_keys($entry->staticProperties) as $propLc) {
            $hooks = [];
            $setLc = strtolower(SourcePreprocessor\PropertyHooks::setHookMethodName($propLc));
            if (isset($entry->methods[$setLc]) && $this->methodIsStatic($entry->methods[$setLc])) {
                $hooks['set'] = $setLc;
            }
            $getLc = strtolower(SourcePreprocessor\PropertyHooks::getHookMethodName($propLc));
            if (isset($entry->methods[$getLc]) && $this->methodIsStatic($entry->methods[$getLc])) {
                $hooks['get'] = $getLc;
            }
            $unsetLc = strtolower(SourcePreprocessor\PropertyHooks::unsetHookMethodName($propLc));
            if (isset($entry->methods[$unsetLc]) && $this->methodIsStatic($entry->methods[$unsetLc])) {
                $hooks['unset'] = $unsetLc;
            }
            if ([] !== $hooks) {
                $lcClass = strtolower($entry->name);
                $propMeta = $this->context->propertyHookRegistry[$lcClass][$propLc] ?? null;
                if (is_array($propMeta) && !empty($propMeta['virtual'])) {
                    $hooks['virtual'] = true;
                }
                $entry->staticPropertyHooks[$propLc] = $hooks;
            }
        }
    }

    private function methodIsStatic(Func $func): bool
    {
        if (!$func instanceof Func\PHP) {
            return false;
        }
        $decl = $func->block->func;

        return null !== $decl && (($decl->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) !== 0;
    }

    /**
     * php-cfg MagicStringResolver lowers parent:: to the direct parent class name; treat
     * static-looking calls to that class from an instance method as parent-scope (#1858, #6735).
     */
    private function isDirectParentScopeInstanceCall(Frame $frame, string $resolvedLcClass): bool
    {
        if (null === $this->resolveCallerThis($frame)) {
            return false;
        }
        $callerClassLc = $this->callerClassLc($frame);
        if (null === $callerClassLc || !isset($this->context->classes[$callerClassLc])) {
            return false;
        }
        $directParentLc = $this->context->classes[$callerClassLc]->parentLc;

        return null !== $directParentLc && $directParentLc === strtolower($resolvedLcClass);
    }

    /**
     * Zend zend_vm_def.h ZEND_INIT_STATIC_METHOD_CALL: bind non-static methods when
     * EX(This) is set and instanceof the called class CE (#28050).
     */
    private function instanceThisAllowsNonStaticCall(Frame $frame, string $calledClassLc): bool
    {
        $thisVar = $this->resolveCallerThis($frame);
        if (null === $thisVar || Variable::TYPE_OBJECT !== $thisVar->type) {
            return false;
        }
        $objectClassLc = strtolower($thisVar->toObject()->class->name);

        return $this->isClassSameOrSubclassOf($objectClassLc, strtolower($calledClassLc));
    }

    /**
     * Zend zend_std_get_static_method: instance methods are not callable via Class::name() (#5339).
     */
    private function assertMethodCallableStatically(ClassEntry $declaringClass, string $methodLc): void
    {
        if ($declaringClass->isEnum && 'cases' === $methodLc) {
            VM\EnumSupport::ensureBuiltinCasesMethod($declaringClass);

            return;
        }
        if ($declaringClass->usesLazyGhostTrait && 'createlazyghost' === $methodLc) {
            VM\LazyGhostTraitSupport::ensureBuiltinLazyGhostMethods($declaringClass);

            return;
        }
        $vis = $declaringClass->methodVisibility[$methodLc] ?? 0;
        if (($vis & \PHPCfg\Func::FLAG_STATIC) !== 0) {
            return;
        }
        $func = $declaringClass->methods[$methodLc];
        if ($this->methodIsStatic($func)) {
            return;
        }
        $declaringName = $declaringClass->name;
        $declaredName = $declaringClass->methodNames[$methodLc] ?? $methodLc;
        if ($func instanceof Func\PHP && null !== $func->block->func && null !== $func->block->func->class) {
            $declaringName = $func->block->func->class->value;
            $declLc = strtolower($declaringName);
            if (isset($this->context->classes[$declLc]->methodNames[$methodLc])) {
                $declaredName = $this->context->classes[$declLc]->methodNames[$methodLc];
            }
        }
        throw new \Error(
            'Non-static method '.$declaringName.'::'.$declaredName.'() cannot be called statically'
        );
    }

    /**
     * @return array{get?: string, set?: string}|null
     */
    private function resolveStaticPropertyHooks(string $classLc, string $propLc): ?array
    {
        $currentLc = $classLc;
        while (isset($this->context->classes[$currentLc])) {
            $entry = $this->context->classes[$currentLc];
            if (isset($entry->staticPropertyHooks[$propLc])) {
                return $entry->staticPropertyHooks[$propLc];
            }
            if (isset($entry->staticProperties[$propLc])) {
                if (null === $entry->parentLc) {
                    return null;
                }
                $currentLc = $entry->parentLc;

                continue;
            }
            $currentLc = $entry->parentLc;
            if (null === $currentLc) {
                break;
            }
        }

        return null;
    }

    private function fetchStaticPropertyWithHooks(
        string $classLc,
        string $propName,
        string $getMethodLc,
        Frame $frame
    ): Variable {
        [$owner, $methodLc] = $this->resolveStaticMethod($classLc, $getMethodLc);
        $func = $owner->methods[$methodLc];
        if (!$func instanceof Func\PHP) {
            throw new \LogicException('Static property get hook must be a user method');
        }

        $result = $this->invokeStaticPropertyHookRaw($func, $propName, $classLc, $frame);
        $catchFrame = $this->enforcePropertyHookGetReturnForClass($classLc, $propName, null, $result, $frame);
        if (null !== $catchFrame) {
            throw new VM\PropertyHookRefWriteSignal($catchFrame);
        }

        return $result;
    }

    private function invokeStaticPropertyHookRaw(
        Func\PHP $func,
        string $rawProperty,
        string $classLc,
        Frame $parentFrame,
        Variable ...$args
    ): Variable {
        // Keep hook frames on the fiber run stack so Fiber::suspend() can resume (#9862).
        $savedStack = null !== $this->context->currentFiber
            ? null
            : $this->context->swapRunStack(null);
        $savedExternalCatch = $this->context->propertyHookExternalCatchFrame;
        $this->context->propertyHookExternalCatchFrame = null;
        $savedCallSiteLine = $parentFrame->callSiteLine;
        if ($parentFrame->callSiteLine <= 0) {
            $fromOp = VM\FatalSite::lineFromOpcodes($parentFrame);
            if ($fromOp > 0) {
                $parentFrame->callSiteLine = $fromOp;
            }
        }
        try {
            $this->emitPropertyHookDeprecationNotice($func, $rawProperty, $parentFrame);
            $child = $func->getFrame($this->context, $parentFrame);
            $child->propertyHookRawProperty = $rawProperty;
            $child->calledClass = $classLc;
            $child->calledArgs = $args;
            $out = new Variable();
            $child->returnVar = $out;
            $this->context->push($child);
            $result = $this->runFrames();
            if (null !== $this->context->propertyHookExternalCatchFrame) {
                throw new VM\PropertyHookRefWriteSignal($this->context->propertyHookExternalCatchFrame);
            }
            if (self::FIBER_SUSPEND === $result) {
                throw new VM\PropertyHookFiberSuspendSignal($parentFrame);
            }
            if (self::SUCCESS !== $result) {
                throw new \LogicException('Static property hook invocation failed in this compiler build');
            }

            return $out->resolveIndirect();
        } finally {
            $parentFrame->callSiteLine = $savedCallSiteLine;
            $this->context->propertyHookExternalCatchFrame = $savedExternalCatch;
            if (null !== $savedStack) {
                $this->context->swapRunStack($savedStack);
            }
        }
    }

    private function linkPropertyHooks(ClassEntry $entry, VM\ClassProperty $prop): void
    {
        $setLc = strtolower(SourcePreprocessor\PropertyHooks::setHookMethodName($prop->name));
        if (isset($entry->methods[$setLc])) {
            $prop->setHookMethodLc = $setLc;
        }
        $getLc = strtolower(SourcePreprocessor\PropertyHooks::getHookMethodName($prop->name));
        if (isset($entry->methods[$getLc])) {
            $prop->getHookMethodLc = $getLc;
        }
        $unsetLc = strtolower(SourcePreprocessor\PropertyHooks::unsetHookMethodName($prop->name));
        if (isset($entry->methods[$unsetLc])) {
            $prop->unsetHookMethodLc = $unsetLc;
        }
        $lcClass = strtolower($entry->name);
        $propMeta = $this->context->propertyHookRegistry[$lcClass][$prop->name]
            ?? $this->context->propertyHookRegistry[$lcClass][strtolower($prop->name)]
            ?? null;
        if (is_array($propMeta) && !empty($propMeta['virtual'])) {
            $prop->propertyHookVirtual = true;
        }
        if (is_array($propMeta) && !empty($propMeta['finalProperty'])) {
            $prop->propertyFinal = true;
        }
        if (is_array($propMeta) && !empty($propMeta['getParameterized'])) {
            $prop->getHookParameterized = true;
        }
        if (is_array($propMeta) && !empty($propMeta['getByRef'])) {
            $prop->getHookByRef = true;
        }
    }

    private function classPropertyMeta(ObjectEntry $object, string $propertyName, ?Frame $frame = null): ?VM\ClassProperty
    {
        $matches = [];
        foreach ($object->class->properties as $prop) {
            if ($prop->name === $propertyName) {
                $matches[] = $prop;
            }
        }
        if ([] === $matches) {
            return null;
        }
        if (1 === \count($matches)) {
            return $matches[0];
        }
        $callerLc = null !== $frame ? $this->callerClassLc($frame) : null;
        if (null !== $callerLc) {
            foreach ($matches as $prop) {
                if (
                    ($prop->visibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0
                    && $prop->declaringClassLc === $callerLc
                ) {
                    return $prop;
                }
            }
        }
        foreach ($matches as $prop) {
            if (($prop->visibility & \PHPCfg\Func::FLAG_PRIVATE) === 0) {
                return $prop;
            }
        }

        // Most-derived private (child props are listed before inherited parent privates).
        return $matches[0];
    }

    private function enforcePropertyHookGetReturn(
        ObjectEntry $object,
        string $propName,
        ?VM\ClassProperty $meta,
        Variable $value,
        Frame $frame
    ): ?Frame {
        $meta ??= $this->classPropertyMeta($object, $propName);
        if (null === $meta) {
            return null;
        }
        $strict = null !== $frame->parent
            ? $frame->parent->block->strictTypes
            : $frame->block->strictTypes;
        try {
            TypeCheck::assertPropertyHookGetReturn($value, $meta->prototype, $strict, $this->context);
        } catch (\TypeError $e) {
            return $this->dispatchVmTypeError($e, $frame);
        }

        return null;
    }

    private function enforcePropertyHookGetReturnForClass(
        string $classLc,
        string $propName,
        ?Variable $typePrototype,
        Variable $value,
        Frame $frame
    ): ?Frame {
        $typePrototype ??= $this->staticPropertyTypePrototype($classLc, $propName);
        if (null === $typePrototype) {
            return null;
        }
        $strict = null !== $frame->parent
            ? $frame->parent->block->strictTypes
            : $frame->block->strictTypes;
        try {
            TypeCheck::assertPropertyHookGetReturn($value, $typePrototype, $strict, $this->context);
        } catch (\TypeError $e) {
            return $this->dispatchVmTypeError($e, $frame);
        }

        return null;
    }

    private function staticPropertyTypePrototype(string $classLc, string $propName): ?Variable
    {
        if (!isset($this->context->classes[$classLc])) {
            return null;
        }
        $propLc = strtolower($propName);

        return $this->context->classes[$classLc]->staticProperties[$propLc] ?? null;
    }

    /**
     * Read a hooked property through a reference binding (#6426).
     */
    public function readPropertyHookRef(Variable $writeLvalue): Variable
    {
        $frame = $this->requireExecutingFrame();
        $owner = $this->resolvePropertyWriteOwner($writeLvalue);
        $propName = $this->resolvePropertyWriteName($writeLvalue);
        if (null !== $owner && null !== $propName) {
            $catchFrame = $this->enforceWriteOnlyVirtualPropertyRead($owner, $propName, $frame);
            if (null !== $catchFrame) {
                throw new VM\PropertyHookRefWriteSignal($catchFrame);
            }
            $hookValue = $this->fetchPropertyWithHooks($owner, $propName, $frame);
            if (null !== $hookValue) {
                return $hookValue;
            }
        }
        $target = $writeLvalue->resolveIndirect();
        $classLc = $target->staticPropertyClassLc;
        $staticPropName = $target->objectPropertyName;
        if (is_string($classLc) && is_string($staticPropName) && '' !== $staticPropName) {
            $hooks = $this->resolveStaticPropertyHooks($classLc, strtolower($staticPropName));
            $getLc = $hooks['get'] ?? null;
            if (null !== $getLc) {
                return $this->fetchStaticPropertyWithHooks($classLc, $staticPropName, $getLc, $frame);
            }
        }
        $out = new Variable();
        $out->copyFrom($target);

        return $out;
    }

    /**
     * Write a hooked property through a reference binding (#6426).
     */
    public function writePropertyHookRef(Variable $writeLvalue, Variable $value): void
    {
        $frame = $this->requireExecutingFrame();
        $catchFrame = $this->enforceAsymmetricPropertyWrite($writeLvalue, $frame);
        if (null !== $catchFrame) {
            throw new VM\PropertyHookRefWriteSignal($catchFrame);
        }
        if ($this->dispatchPropertySetHookAssign($writeLvalue, $value, $frame)) {
            return;
        }
        if ($this->context->propertyHookSetAborted) {
            $this->context->propertyHookSetAborted = false;

            return;
        }
        $catchFrame = $this->enforceVirtualPropertyHookWrite($writeLvalue, $frame);
        if (null !== $catchFrame) {
            throw new VM\PropertyHookRefWriteSignal($catchFrame);
        }
        $writeLvalue->resolveIndirect()->copyFrom($value);
    }

    private function requireExecutingFrame(): Frame
    {
        if (null === $this->executingFrame) {
            throw new \LogicException('No active frame for property hook reference');
        }

        return $this->executingFrame;
    }

    /** Active user opcode frame during runFrames (not on runStack — see #14132). */
    public function currentExecutingFrame(): ?Frame
    {
        return $this->executingFrame;
    }

    /**
     * Mark an ASSIGN_REF alias so TypeErrors use "reference held by property" (#25622).
     */
    private function markTypedPropertyByRefAlias(Variable $alias, Variable $storage): void
    {
        $resolved = $storage->resolveIndirect();
        if (
            (null !== $resolved->objectPropertyOwner && null !== $resolved->objectPropertyName)
            || (null !== $resolved->staticPropertyClassLc && null !== $resolved->objectPropertyName)
        ) {
            $alias->typedPropertyByRef = true;
        }
    }

    /**
     * Builtin serialize/unserialize invoke property hooks with a user PHP frame parent (#6474).
     */
    private function resolvePropertyHookParentFrame(?Frame $frame): Frame
    {
        $cursor = $frame ?? $this->executingFrame;
        while (null !== $cursor) {
            if (null !== $cursor->handler && null !== $cursor->block) {
                return $cursor;
            }
            $cursor = $cursor->parent;
        }

        return $this->requireExecutingFrame();
    }

    /**
     * Property lvalue for assign-by-ref when rhs is a hooked property (#6426, #22475).
     */
    private function resolvePropertyHookRefWriteLvalue(Variable $operand, Frame $frame): ?Variable
    {
        $propName = $this->resolvePropertyWriteName($operand);
        if (null === $propName || $this->isPropertyHookRawWrite($frame, $propName)) {
            return null;
        }
        $owner = $this->resolvePropertyWriteOwner($operand);
        if (null !== $owner) {
            $meta = $this->classPropertyMeta($owner, $propName);
            if (
                null === $meta
                || (
                    !$meta->propertyHookVirtual
                    && null === $meta->setHookMethodLc
                    && null === $meta->getHookMethodLc
                )
            ) {
                return null;
            }

            return $operand;
        }
        $target = $operand->resolveIndirect();
        $classLc = $operand->staticPropertyClassLc ?? $target->staticPropertyClassLc;
        $staticPropName = $operand->objectPropertyName ?? $target->objectPropertyName;
        if (is_string($classLc) && is_string($staticPropName) && '' !== $staticPropName) {
            if (null !== $this->resolveStaticPropertyHooks($classLc, strtolower($staticPropName))) {
                return $operand;
            }
        }

        return null;
    }

    /**
     * True when the hooked property declares `&get` (ZEND_ACC_RETURN_REFERENCE, #21098 / #22475).
     */
    private function propertyHookGetIsByRef(Variable $lvalue): bool
    {
        $propName = $this->resolvePropertyWriteName($lvalue);
        if (null === $propName) {
            return false;
        }
        $owner = $this->resolvePropertyWriteOwner($lvalue);
        if (null !== $owner) {
            $meta = $this->classPropertyMeta($owner, $propName);

            return (bool) ($meta?->getHookByRef);
        }
        $target = $lvalue->resolveIndirect();
        $classLc = $lvalue->staticPropertyClassLc ?? $target->staticPropertyClassLc;
        $staticPropName = $lvalue->objectPropertyName ?? $target->objectPropertyName;
        if (is_string($classLc) && is_string($staticPropName) && '' !== $staticPropName) {
            $propMeta = $this->context->propertyHookRegistry[$classLc][$staticPropName]
                ?? $this->context->propertyHookRegistry[$classLc][strtolower($staticPropName)]
                ?? null;

            return is_array($propMeta) && !empty($propMeta['getByRef']);
        }

        return false;
    }

    /**
     * php-src zend_object_handlers.c — assign-ref / get_ptr without `&get` (#22475).
     */
    private function indirectModificationOfHookedPropertyMessage(Variable $lvalue): string
    {
        $propName = $this->resolvePropertyWriteName($lvalue) ?? '?';
        $owner = $this->resolvePropertyWriteOwner($lvalue);
        if (null !== $owner) {
            return sprintf('Indirect modification of %s::$%s is not allowed', $owner->class->name, $propName);
        }
        $target = $lvalue->resolveIndirect();
        $classLc = $lvalue->staticPropertyClassLc ?? $target->staticPropertyClassLc;
        if (is_string($classLc) && isset($this->context->classes[$classLc])) {
            return sprintf(
                'Indirect modification of %s::$%s is not allowed',
                $this->context->classes[$classLc]->name,
                $propName
            );
        }

        return sprintf('Indirect modification of $%s is not allowed', $propName);
    }

    /**
     * Bind `$r = &$obj->prop` when `&get` is declared (zend_property_hooks.c, #22475).
     *
     * Prefer recorded getBacking (same as dim writes, #21098) so `$r = &$obj->hooked; $r = $v`
     * mutates the private arrow-target — fetchPropertyWithHooksByRef alone can return a value
     * copy when return-by-ref of object props is not yet a live alias (#26368). When a set hook
     * is also present, PropertyHookRef write-through stays valid for private backing cells.
     *
     * @return ?Frame catch frame when hook throws
     */
    private function bindAssignRefToByRefGetHook(Variable $writeTarget, Variable $hookLvalue, Frame $frame): ?Frame
    {
        $owner = $this->resolvePropertyWriteOwner($hookLvalue);
        $propName = $this->resolvePropertyWriteName($hookLvalue);
        if (null !== $owner && null !== $propName) {
            $meta = $this->classPropertyMeta($owner, $propName);
            // `&get`+`set` (virtual): Prefer PropertyHookRef so writes stay in-hook scope (#22475).
            if (null !== $meta && null !== $meta->setHookMethodLc) {
                $stableLvalue = $this->stablePropertyHookRefWriteLvalue($hookLvalue);
                $hookRefVar = new Variable();
                $hookRefVar->propertyHookRef(new VM\PropertyHookRef($this, $stableLvalue));
                $writeTarget->indirect($hookRefVar);

                return null;
            }
            // `&get`-only with known backing: alias the storage cell directly (#26368).
            $backing = $this->resolveByRefGetHookBackingStorage($owner, $propName);
            if (null !== $backing) {
                $this->bindAssignRefSharedCell($writeTarget, $backing);

                return null;
            }
            try {
                $byRef = $this->fetchPropertyWithHooksByRef($owner, $propName, $frame);
            } catch (VM\PropertyHookRefWriteSignal $signal) {
                return $signal->catchFrame;
            }
            if (null === $byRef) {
                return $this->dispatchVmError(
                    $this->indirectModificationOfHookedPropertyMessage($hookLvalue),
                    $frame
                );
            }
            $cell = $byRef->isIndirect()
                ? ($byRef->directIndirectTarget() ?? $byRef->resolveIndirect())
                : $byRef;
            $this->bindAssignRefSharedCell($writeTarget, $cell);

            return null;
        }
        if ($this->propertyWriteHasSetHook($hookLvalue)) {
            $stableLvalue = $this->stablePropertyHookRefWriteLvalue($hookLvalue);
            $hookRefVar = new Variable();
            $hookRefVar->propertyHookRef(new VM\PropertyHookRef($this, $stableLvalue));
            $writeTarget->indirect($hookRefVar);

            return null;
        }

        return $this->dispatchVmError(
            $this->indirectModificationOfHookedPropertyMessage($hookLvalue),
            $frame
        );
    }

    /**
     * Object property cell named by registry getBacking for an `&get` arrow/block (#21098 / #26368).
     */
    private function resolveByRefGetHookBackingStorage(ObjectEntry $owner, string $propName): ?Variable
    {
        $lcClass = strtolower($owner->class->name);
        $propMeta = $this->context->propertyHookRegistry[$lcClass][$propName]
            ?? $this->context->propertyHookRegistry[$lcClass][strtolower($propName)]
            ?? null;
        $backingName = is_array($propMeta) ? ($propMeta['getBacking'] ?? null) : null;
        if (!is_string($backingName) || '' === $backingName || !$owner->hasProperty($backingName)) {
            return null;
        }

        return $owner->getProperty($backingName);
    }

    /**
     * Promote storage to a shared IS_REFERENCE-style cell and bind the assign-ref LHS (#22475).
     */
    private function bindAssignRefSharedCell(Variable $writeTarget, Variable $cell): void
    {
        if (Variable::TYPE_INDIRECT !== $cell->type) {
            $shared = new Variable();
            $shared->copyFrom($cell);
            $cell->indirect($shared);
        }
        $writeTarget->indirect($cell->resolveIndirect());
    }

    /** Live property storage cell for hooked ref bindings (#6426). */
    private function stablePropertyHookRefWriteLvalue(Variable $operand): Variable
    {
        $owner = $this->resolvePropertyWriteOwner($operand);
        $propName = $this->resolvePropertyWriteName($operand);
        if (null !== $owner && null !== $propName && $owner->hasProperty($propName)) {
            return $owner->getProperty($propName);
        }
        $target = $operand->resolveIndirect();
        $classLc = $target->staticPropertyClassLc;
        $staticPropName = $target->objectPropertyName;
        if (is_string($classLc) && is_string($staticPropName) && isset($this->context->classes[$classLc])) {
            return $this->context->classes[$classLc]->staticProperties[strtolower($staticPropName)];
        }

        return $operand;
    }

    /**
     * foreach ($iterable as &$obj->hooked) — write iteration scalar to hook backing without set hook (#6435).
     */
    private function writeHookedPropertyForeachIterationValue(
        Variable $writeLvalue,
        Variable $value,
        Frame $frame,
    ): void {
        $owner = $this->resolvePropertyWriteOwner($writeLvalue);
        $propName = $this->resolvePropertyWriteName($writeLvalue);
        if (null !== $owner && null !== $propName) {
            $lcClass = strtolower($owner->class->name);
            $propMeta = $this->context->propertyHookRegistry[$lcClass][$propName]
                ?? $this->context->propertyHookRegistry[$lcClass][strtolower($propName)]
                ?? null;
            $backingName = is_array($propMeta)
                ? ($propMeta['getBacking'] ?? $propMeta['setBacking'] ?? null)
                : null;
            if (null !== $backingName && $owner->hasProperty($backingName)) {
                $owner->getProperty($backingName)->copyFrom($value->resolveIndirect());

                return;
            }
            if ($owner->hasProperty($propName)) {
                $owner->getProperty($propName)->copyFrom($value->resolveIndirect());

                return;
            }
        } else {
            $target = $writeLvalue->resolveIndirect();
            $classLc = $writeLvalue->staticPropertyClassLc ?? $target->staticPropertyClassLc;
            $staticPropName = $writeLvalue->objectPropertyName ?? $target->objectPropertyName;
            if (is_string($classLc) && is_string($staticPropName) && '' !== $staticPropName) {
                $propLc = strtolower($staticPropName);
                $propMeta = $this->context->propertyHookRegistry[$classLc][$staticPropName]
                    ?? $this->context->propertyHookRegistry[$classLc][$propLc]
                    ?? null;
                $backingName = is_array($propMeta)
                    ? ($propMeta['getBacking'] ?? $propMeta['setBacking'] ?? null)
                    : null;
                if (null !== $backingName) {
                    $backingStorage = $this->resolveStaticPropertyStorage($classLc, strtolower($backingName));
                    if (null !== $backingStorage) {
                        $backingStorage->copyFrom($value->resolveIndirect());

                        return;
                    }
                }
            }
        }
        $this->stablePropertyHookRefWriteLvalue($writeLvalue)->copyFrom($value->resolveIndirect());
    }

    private function propertyWriteHasSetHook(Variable $lvalue): bool
    {
        $propName = $this->resolvePropertyWriteName($lvalue);
        if (null === $propName) {
            return false;
        }
        $target = $lvalue->resolveIndirect();
        $classLc = $target->staticPropertyClassLc;
        $staticPropName = $target->objectPropertyName;
        if (is_string($classLc) && is_string($staticPropName) && '' !== $staticPropName) {
            $hooks = $this->resolveStaticPropertyHooks($classLc, strtolower($staticPropName));

            return null !== $hooks && !empty($hooks['set']);
        }
        $owner = $this->resolvePropertyWriteOwner($lvalue);
        if (null === $owner) {
            return false;
        }
        $meta = $this->classPropertyMeta($owner, $propName);
        if (null !== ReflectionPropertyHookSupport::runtimeHookClosure(
            $this->context,
            $owner->class,
            $propName,
            'set'
        )) {
            return true;
        }
        $setLc = $meta?->setHookMethodLc
            ?? strtolower(SourcePreprocessor\PropertyHooks::setHookMethodName($propName));

        return isset($owner->class->methods[$setLc]);
    }

    /**
     * Assignment expression result after set hook — hook owns backing storage (#7251, zend_property_hooks.c).
     */
    private function deliverPropertySetHookAssignResult(Variable $dest, Variable $rhs): void
    {
        if ($dest->isIndirect()) {
            $dest->reset();
        }
        $dest->duplicateFrom($rhs);
    }

    /**
     * Invoke set hook instead of direct assign when applicable (#3145).
     */
    private function dispatchPropertySetHookAssign(Variable $lvalue, Variable $value, Frame $frame): bool
    {
        $target = $lvalue->resolveIndirect();
        $classLc = $lvalue->staticPropertyClassLc ?? $target->staticPropertyClassLc;
        $staticPropName = $lvalue->objectPropertyName ?? $target->objectPropertyName;
        if (
            is_string($classLc)
            && is_string($staticPropName)
            && !$this->isPropertyHookRawWrite($frame, $staticPropName)
        ) {
            if (!isset($this->context->classes[$classLc])) {
                return false;
            }
            $entry = $this->context->classes[$classLc];
            $propLc = strtolower($staticPropName);
            $hooks = $this->resolveStaticPropertyHooks($classLc, $propLc);
            $setLc = $hooks['set'] ?? null;
            if (null === $setLc || !isset($entry->methods[$setLc])) {
                return false;
            }
            $func = $entry->methods[$setLc];
            if (!$func instanceof Func\PHP) {
                return false;
            }
            $this->context->propertyHookSetAborted = false;
            $this->invokeStaticPropertyHookRaw($func, $staticPropName, $classLc, $frame, $value->resolveIndirect());
            if ($this->context->propertyHookSetAborted) {
                return false;
            }

            return true;
        }
        $owner = $this->resolvePropertyWriteOwner($lvalue);
        $propName = $this->resolvePropertyWriteName($lvalue);
        if (null === $owner || null === $propName || $this->isPropertyHookRawWrite($frame, $propName)) {
            return false;
        }
        $meta = $this->classPropertyMeta($owner, $propName);
        $runtimeSet = ReflectionPropertyHookSupport::runtimeHookClosure(
            $this->context,
            $owner->class,
            $propName,
            'set'
        );
        if (null !== $runtimeSet) {
            $this->context->propertyHookSetAborted = false;
            $thisVar = new Variable();
            $thisVar->object($owner);
            $this->invokeReflectionRuntimePropertyHook($runtimeSet, $thisVar, $value->resolveIndirect(), $frame);
            if ($this->context->propertyHookSetAborted) {
                return false;
            }

            return true;
        }
        $setLc = $meta?->setHookMethodLc
            ?? strtolower(SourcePreprocessor\PropertyHooks::setHookMethodName($propName));
        if (!isset($owner->class->methods[$setLc])) {
            return false;
        }
        $func = $owner->class->methods[$setLc];
        if (!$func instanceof Func\PHP) {
            return false;
        }
        $this->context->propertyHookSetAborted = false;
        $thisVar = new Variable();
        $thisVar->object($owner);
        $this->invokePhpFunctionWithPropertyHookRaw($func, $propName, $frame, $thisVar, $value->resolveIndirect());
        if ($this->context->propertyHookSetAborted) {
            return false;
        }

        return true;
    }

    /**
     * Invoke a ReflectionProperty::setHook() closure with $this bound (#22116).
     */
    private function invokeReflectionRuntimePropertyHook(
        VM\ClosureState $state,
        Variable $thisVar,
        ?Variable $setValue,
        Frame $frame
    ): Variable {
        $prevBound = $state->boundThis;
        $state->boundThis = $thisVar;
        try {
            if (null !== $setValue) {
                return $this->invokeClosure($state, $setValue);
            }

            return $this->invokeClosure($state);
        } finally {
            $state->boundThis = $prevBound;
        }
    }

    /**
     * Object foreach value read — invoke get hooks like get_object_vars() (#9470, zend_property_hooks.c).
     */
    public function readObjectForeachProperty(
        ObjectEntry $object,
        string $name,
        Frame $frame,
        bool $byRef
    ): Variable {
        $meta = $this->classPropertyMeta($object, $name);
        if (!$byRef && null !== $meta?->getHookMethodLc) {
            $hookValue = $this->fetchPropertyWithHooks($object, $name, $frame);
            if (null !== $hookValue) {
                $copy = new Variable();
                $copy->copyFrom($hookValue->resolveIndirect());

                return $copy;
            }
        }
        $prop = $object->getProperty($name);
        if ($byRef) {
            return $prop;
        }
        $copy = new Variable();
        $copy->copyFrom($prop->resolveIndirect());

        return $copy;
    }

    private function fetchPropertyWithHooks(ObjectEntry $object, string $name, Frame $frame): ?Variable
    {
        $fiber = $this->context->currentFiber;
        if (null !== $fiber?->propertyHookResumeRead) {
            $result = new Variable();
            $result->copyFrom($fiber->propertyHookResumeRead->resolveIndirect());
            $fiber->propertyHookResumeRead = null;
            $meta = $this->classPropertyMeta($object, $name);
            $catchFrame = $this->enforcePropertyHookGetReturn($object, $name, $meta, $result, $frame);
            if (null !== $catchFrame) {
                throw new VM\PropertyHookRefWriteSignal($catchFrame);
            }

            return $result;
        }
        if ($this->isPropertyHookRawWrite($frame, $name)) {
            $catchFrame = $this->enforceVirtualPropertyHookRawAccess($object, $name, true, $frame);
            if (null !== $this->context->propertyHookExternalCatchFrame) {
                throw new VM\PropertyHookRefWriteSignal($this->context->propertyHookExternalCatchFrame);
            }
            if (null !== $catchFrame) {
                throw new VM\PropertyHookRefWriteSignal($catchFrame);
            }

            return null;
        }
        $meta = $this->classPropertyMeta($object, $name);
        if (
            null !== $meta
            && $meta->lazy
            && null !== $meta->getHookMethodLc
            && isset($object->lazyRawInitializedProperties[$name])
        ) {
            $cached = new Variable();
            $cached->copyFrom($object->getProperty($name)->resolveIndirect());

            return $cached;
        }
        $runtimeGet = ReflectionPropertyHookSupport::runtimeHookClosure(
            $this->context,
            $object->class,
            $name,
            'get'
        );
        if (null !== $runtimeGet) {
            $thisVar = new Variable();
            $thisVar->object($object);
            $result = $this->invokeReflectionRuntimePropertyHook($runtimeGet, $thisVar, null, $frame);
            $catchFrame = $this->enforcePropertyHookGetReturn($object, $name, $meta, $result, $frame);
            if (null !== $catchFrame) {
                throw new VM\PropertyHookRefWriteSignal($catchFrame);
            }
            if (null !== $meta) {
                VM\LazyPropertySupport::cacheLazyGetHookResult($object, $name, $meta, $result);
            }

            return $result;
        }
        $getLc = $meta?->getHookMethodLc
            ?? strtolower(SourcePreprocessor\PropertyHooks::getHookMethodName($name));
        if (!isset($object->class->methods[$getLc])) {
            return null;
        }
        $func = $object->class->methods[$getLc];
        if (!$func instanceof Func\PHP) {
            return null;
        }
        $thisVar = new Variable();
        $thisVar->object($object);

        $result = $this->invokePhpFunctionWithPropertyHookRaw($func, $name, $frame, $thisVar);
        $catchFrame = $this->enforcePropertyHookGetReturn($object, $name, $meta, $result, $frame);
        if (null !== $catchFrame) {
            throw new VM\PropertyHookRefWriteSignal($catchFrame);
        }
        if (null !== $meta) {
            VM\LazyPropertySupport::cacheLazyGetHookResult($object, $name, $meta, $result);
        }

        return $result;
    }

    private function invokePhpFunctionWithPropertyHookRaw(Func\PHP $func, string $rawProperty, Frame $parentFrame, Variable ...$args): Variable
    {
        // Keep hook frames on the fiber run stack so Fiber::suspend() can resume (#9862).
        $savedStack = null !== $this->context->currentFiber
            ? null
            : $this->context->swapRunStack(null);
        $savedExternalCatch = $this->context->propertyHookExternalCatchFrame;
        $this->context->propertyHookExternalCatchFrame = null;
        $savedCallSiteLine = $parentFrame->callSiteLine;
        // Stamp assign/fetch site so set-hook param TypeErrors cite "called in … on line N" (#29666).
        if ($parentFrame->callSiteLine <= 0) {
            $fromOp = VM\FatalSite::lineFromOpcodes($parentFrame);
            if ($fromOp > 0) {
                $parentFrame->callSiteLine = $fromOp;
            }
        }
        try {
            $this->emitPropertyHookDeprecationNotice($func, $rawProperty, $parentFrame);
            $child = $func->getFrame($this->context, $parentFrame);
            $child->propertyHookRawProperty = $rawProperty;
            $child->calledArgs = $args;
            if (
                [] !== $args
                && null !== $func->block->func
                && null !== $func->block->func->class
            ) {
                $thisIdx = $func->block->slotIndexForVariableName('this');
                if (null !== $thisIdx) {
                    $child->scope[$thisIdx] = $args[0];
                }
            }
            $out = new Variable();
            $child->returnVar = $out;
            $this->context->push($child);
            $result = $this->runFrames();
            if (null !== $this->context->propertyHookExternalCatchFrame) {
                throw new VM\PropertyHookRefWriteSignal($this->context->propertyHookExternalCatchFrame);
            }
            if (self::FIBER_SUSPEND === $result) {
                throw new VM\PropertyHookFiberSuspendSignal($parentFrame);
            }
            if (self::SUCCESS !== $result) {
                throw new \LogicException('Property hook invocation failed in this compiler build');
            }

            return $out->resolveIndirect();
        } finally {
            $parentFrame->callSiteLine = $savedCallSiteLine;
            $this->context->propertyHookExternalCatchFrame = $savedExternalCatch;
            if (null !== $savedStack) {
                $this->context->swapRunStack($savedStack);
            }
        }
    }

    /**
     * Reject unset($scalar[$key]) — Zend ZEND_UNSET_DIM on non-array/string (#4880, zend_execute.c).
     *
     * @return Frame|null catch frame when try/catch (Error) handles the throw
     */
    /**
     * unset($GLOBALS['name']) on the script $GLOBALS operand (#5868).
     */
    private function isGlobalsSuperglobalUnset(Frame $frame, int $containerSlot, string $name): bool
    {
        if ('' === $name) {
            return false;
        }
        $globalsSlot = $frame->block->slotIndexForVariableName('GLOBALS');

        return null !== $globalsSlot && $globalsSlot === $containerSlot;
    }

    private function dispatchUnsetDimNonContainerError(Frame $frame, string $message): ?Frame
    {
        $thrown = VM\BuiltinExceptionSupport::materializeError($this->context, $message);
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    /**
     * Reject unset() on readonly properties; returns catch frame or throws when uncaught.
     *
     * php-src zend_std_unset_property / verify_readonly_initialization_access (#29131):
     * uninitialized readonly may be unset from declaring-class scope (same window as first
     * init), including inside __construct, so a later write can initialize. Once initialized,
     * unset always Errors — even mid-construction.
     */
    private function enforceReadonlyPropertyUnset(ObjectEntry $object, string $propName, Frame $frame): ?Frame
    {
        if (VM\ObjectReadonlySupport::isDynamicReadonly($object)) {
            // Stamp user site via dispatchVmError (#25556 / #7343).
            return $this->dispatchVmError(
                VM\ObjectReadonlySupport::unsetObjectMessage($object),
                $frame
            );
        }

        $declaringClass = $this->readonlyPropertyDeclaringClass($object, $propName);
        if (null === $declaringClass) {
            return null;
        }

        // Uninitialized + declaring-class scope → allow (reinit via later assign; #29131).
        if ($this->allowReadonlyPropertyFirstInit($object, $propName, $frame)) {
            return null;
        }

        $uninitialized = !$object->hasProperty($propName)
            || VM\TypedPropertyCheck::isUninitialized($object->getProperty($propName));
        if ($uninitialized) {
            return $this->dispatchVmError(
                sprintf(
                    'Cannot unset readonly property %s::$%s from %s',
                    $declaringClass,
                    $propName,
                    $this->propertyWriteScopeLabel($frame)
                ),
                $frame
            );
        }

        return $this->dispatchVmError(
            sprintf('Cannot unset readonly property %s::$%s', $declaringClass, $propName),
            $frame
        );
    }

    /**
     * Reject unset() outside set-visibility scope (zend_object_handlers.c, #23338).
     * Same gate as writes; message verb is "unset" instead of "modify".
     */
    private function enforceAsymmetricPropertyUnset(ObjectEntry $object, string $propName, Frame $frame): ?Frame
    {
        $msg = $this->asymmetricPropertyUnsetMessage($object, $propName, $frame);
        if (null === $msg) {
            return null;
        }
        $thrown = VM\BuiltinExceptionSupport::materializeError($this->context, $msg);
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    /** Reject asymmetric set visibility for unset(); returns message or null (#23338). */
    private function asymmetricPropertyUnsetMessage(ObjectEntry $object, string $propName, Frame $frame): ?string
    {
        $meta = $this->classPropertyMeta($object, $propName);
        if (null === $meta) {
            return null;
        }
        // Implicit PHP 8.4 protected(set) on readonly — wording is readonly, not aviz (#29273).
        if ($meta->readonly || $object->class->readonly) {
            return null;
        }
        $setVis = PropertyVisibility::effectiveSetVisibility($meta->visibility, $meta->setVisibility);
        $readVis = PropertyVisibility::effectiveGetVisibility($meta->visibility, $meta->getVisibility);
        if ($setVis === $readVis) {
            return null;
        }
        $declaringLc = '' !== $meta->declaringClassLc
            ? $meta->declaringClassLc
            : strtolower($object->class->name);
        $declaringDisplay = $this->context->classes[$declaringLc]->name
            ?? $object->class->name;
        $callerLc = $this->callerClassLc($frame);
        try {
            PropertyVisibility::assertUnsettable(
                $setVis,
                $callerLc,
                $declaringLc,
                $declaringDisplay,
                $propName,
                fn (string $child, string $parent): bool => $this->isSubclassOf($child, $parent),
                MethodVisibility::mask($readVis),
                $meta->asymmetricExplicitRead,
                $this->callerScopeDisplay($frame, $callerLc)
            );
        } catch (\LogicException $e) {
            return $e->getMessage();
        }

        return null;
    }

    /**
     * Reject unset() on virtual hooked instance properties without an unset hook (#6425, #6491, #26373).
     * Backed hooked properties are rejected in dispatchHookedInstancePropertyUnset.
     */
    private function enforceVirtualPropertyHookUnset(ObjectEntry $object, string $propName, Frame $frame): ?Frame
    {
        $meta = $this->classPropertyMeta($object, $propName);
        if (null === $meta || !$meta->propertyHookVirtual) {
            return null;
        }
        $hasSet = null !== $meta->setHookMethodLc;
        $hasGet = null !== $meta->getHookMethodLc;
        if (null !== $meta->unsetHookMethodLc) {
            return null;
        }
        if (!$hasSet && !$hasGet) {
            return null;
        }
        $className = $object->class->name;
        if ('' !== $meta->declaringClassLc && isset($this->context->classes[$meta->declaringClassLc])) {
            $className = $this->context->classes[$meta->declaringClassLc]->name;
        }

        return $this->raiseVirtualPropertyHookUnsetError($className, $propName, $frame);
    }

    /**
     * Reject unset() on static properties (Zend zend_std_unset_static_property).
     * Typed (#6648) and untyped (#23691) both Error; hook raw-writes may clear backing.
     */
    private function enforceStaticPropertyUnset(
        string $classLc,
        string $propNameRaw,
        Frame $frame
    ): ?Frame {
        if ($this->isPropertyHookRawWrite($frame, $propNameRaw)) {
            return null;
        }
        $className = $this->context->classes[$classLc]->name ?? $classLc;
        $thrown = VM\BuiltinExceptionSupport::materializeError(
            $this->context,
            sprintf('Attempt to unset static property %s::$%s', $className, $propNameRaw)
        );
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    /** Reject unset() on virtual hooked static properties without an unset hook (#6425, #6491, #26373). */
    private function enforceVirtualStaticPropertyHookUnset(
        string $classLc,
        string $propLc,
        string $propNameRaw,
        Frame $frame
    ): ?Frame {
        $hooks = $this->resolveStaticPropertyHooks($classLc, $propLc);
        if (null === $hooks || empty($hooks['virtual'])) {
            return null;
        }
        $hasSet = !empty($hooks['set']);
        $hasGet = !empty($hooks['get']);
        if (!empty($hooks['unset'])) {
            return null;
        }
        if (!$hasSet && !$hasGet) {
            return null;
        }
        $className = $this->context->classes[$classLc]->name ?? $classLc;

        return $this->raiseVirtualPropertyHookUnsetError($className, $propNameRaw, $frame);
    }

    private function raiseVirtualPropertyHookUnsetError(
        string $className,
        string $propName,
        Frame $frame
    ): ?Frame {
        $message = sprintf('Cannot unset hooked property %s::$%s', $className, $propName);
        $thrown = VM\BuiltinExceptionSupport::materializeError(
            $this->context,
            $message
        );
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    /** Reject reads/isset/empty on write-only virtual hooked instance properties (#6484, #19163, zend_property_hooks.c). */
    private function enforceWriteOnlyVirtualPropertyRead(ObjectEntry $object, string $propName, Frame $frame): ?Frame
    {
        if (!$this->instancePropertyIsWriteOnlyVirtualHook($object, $propName)) {
            return null;
        }
        $meta = $this->classPropertyMeta($object, $propName);
        $className = $object->class->name;
        if (null !== $meta && '' !== $meta->declaringClassLc && isset($this->context->classes[$meta->declaringClassLc])) {
            $className = $this->context->classes[$meta->declaringClassLc]->name;
        }

        return $this->raiseWriteOnlyVirtualPropertyReadError($className, $propName, $frame);
    }

    /** Reject reads on write-only virtual hooked static properties (#6484, #19163). */
    private function enforceWriteOnlyVirtualStaticPropertyRead(string $classLc, string $propName, Frame $frame): ?Frame
    {
        $hooks = $this->resolveStaticPropertyHooks($classLc, strtolower($propName));
        if (null === $hooks || empty($hooks['set']) || !empty($hooks['get'])) {
            return null;
        }
        if (!$this->staticPropertyIsWriteOnlyVirtualHook($classLc, $propName, $hooks)) {
            return null;
        }
        $className = $this->context->classes[$classLc]->name ?? $classLc;

        return $this->raiseWriteOnlyVirtualPropertyReadError($className, $propName, $frame);
    }

    private function enforceWriteOnlyVirtualPropertyReadForLvalue(Variable $lvalue, Frame $frame): ?Frame
    {
        $propName = $this->resolvePropertyWriteName($lvalue);
        if ($this->isPropertyHookRawWrite($frame, $propName ?? '')) {
            return null;
        }
        $owner = $this->resolvePropertyWriteOwner($lvalue);
        if (null !== $owner && null !== $propName) {
            return $this->enforceWriteOnlyVirtualPropertyRead($owner, $propName, $frame);
        }
        $target = $lvalue->resolveIndirect();
        $classLc = $lvalue->staticPropertyClassLc ?? $target->staticPropertyClassLc;
        $staticPropName = $lvalue->objectPropertyName ?? $target->objectPropertyName;
        if (is_string($classLc) && is_string($staticPropName) && '' !== $staticPropName) {
            return $this->enforceWriteOnlyVirtualStaticPropertyRead($classLc, $staticPropName, $frame);
        }

        return null;
    }

    private function raiseWriteOnlyVirtualPropertyReadError(string $className, string $propName, Frame $frame): ?Frame
    {
        // php-src PHP 8.4: zend_object_handlers.c — "Property %s::$%s is write-only" (#29240).
        $thrown = VM\BuiltinExceptionSupport::materializeError(
            $this->context,
            sprintf('Property %s::$%s is write-only', $className, $propName)
        );
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    /**
     * Inside a property hook, re-entrant $this->prop skips the hook (zend_should_call_hook).
     * Virtual: "Must not read/write virtual property" (#10005).
     * Backed typed, uninitialized: typed-property Error (#21467, php-src-strict).
     *
     * Virtuality is judged for the **hook-declaring class** on this frame, not the leaf
     * object's override — parent::$prop::set()/get() must still touch the parent's backing
     * when the child marks the property virtual (#22476, zend_property_hooks.c).
     */
    private function enforceVirtualPropertyHookRawAccess(
        ObjectEntry $object,
        string $propName,
        bool $isRead,
        Frame $frame
    ): ?Frame {
        if (!$this->isPropertyHookRawWrite($frame, $propName)) {
            return null;
        }
        if ($isRead && !$this->frameIsPropertyGetHook($frame)) {
            return null;
        }
        if (!$isRead && !$this->frameIsPropertySetHook($frame)) {
            return null;
        }
        $hookClassLc = $this->propertyHookFrameDeclaringClassLc($frame);
        $className = null !== $hookClassLc && isset($this->context->classes[$hookClassLc])
            ? $this->context->classes[$hookClassLc]->name
            : $this->resolveHookedPropertyClassName($object, $propName);
        if ($this->instancePropertyIsVirtualHookForHookFrame($object, $propName, $hookClassLc)) {
            return $this->raiseVirtualPropertyHookRawAccessError($className, $propName, $isRead, $frame);
        }
        if (!$isRead) {
            return null;
        }
        $meta = $this->classPropertyMeta($object, $propName);
        if (null === $meta?->getHookMethodLc) {
            return null;
        }
        if ($this->hookedPropertyUsesDistinctBacking($object, $propName)) {
            return null;
        }
        // Parent hook with same-name backing on a child that overrode as virtual: probe the
        // declaring class registry, not the child's virtual leaf meta (#22476).
        if (null !== $hookClassLc && $this->hookedPropertyUsesDistinctBackingForClass($hookClassLc, $propName)) {
            return null;
        }
        $backing = $this->hookedPropertyBackingValue($object, $propName);
        if (false !== $backing && ($backing->isUndefined() || VM\TypedPropertyCheck::isUninitialized($backing))) {
            return $this->dispatchVmError(VM\TypedPropertyCheck::errorMessage($backing), $frame);
        }

        return null;
    }

    /** Declaring class lc of the __phpc_property_* method on this frame, if any. */
    private function propertyHookFrameDeclaringClassLc(Frame $frame): ?string
    {
        $func = $frame->block->func ?? null;
        if (null === $func || null === $func->class) {
            return null;
        }
        $className = $func->class->value ?? null;
        if (!is_string($className) || '' === $className) {
            return null;
        }

        return strtolower(ltrim($className, '\\'));
    }

    /**
     * Virtual check scoped to the running hook's class (#22476 parent::$prop::set/get).
     */
    private function instancePropertyIsVirtualHookForHookFrame(
        ObjectEntry $object,
        string $propName,
        ?string $hookClassLc
    ): bool {
        if (null !== $hookClassLc) {
            $propMeta = $this->context->propertyHookRegistry[$hookClassLc][$propName]
                ?? $this->context->propertyHookRegistry[$hookClassLc][strtolower($propName)]
                ?? null;
            if (is_array($propMeta)) {
                return !empty($propMeta['virtual']);
            }
            if (isset($this->context->classes[$hookClassLc])) {
                foreach ($this->context->classes[$hookClassLc]->properties as $prop) {
                    if ($prop->name === $propName || 0 === strcasecmp($prop->name, $propName)) {
                        return $prop->propertyHookVirtual;
                    }
                }
            }
        }

        return $this->instancePropertyIsVirtualHook($object, $propName);
    }

    /** Distinct backing declared on a specific class's hook registry. */
    private function hookedPropertyUsesDistinctBackingForClass(string $classLc, string $propName): bool
    {
        $propMeta = $this->context->propertyHookRegistry[$classLc][$propName]
            ?? $this->context->propertyHookRegistry[$classLc][strtolower($propName)]
            ?? null;
        if (!is_array($propMeta)) {
            return false;
        }
        $backingName = $propMeta['setBacking'] ?? $propMeta['getBacking'] ?? null;

        return null !== $backingName && 0 !== strcasecmp($backingName, $propName);
    }

    private function resolveHookedPropertyClassName(ObjectEntry $object, string $propName): string
    {
        $meta = $this->classPropertyMeta($object, $propName);
        $className = $object->class->name;
        if (null !== $meta && '' !== $meta->declaringClassLc && isset($this->context->classes[$meta->declaringClassLc])) {
            $className = $this->context->classes[$meta->declaringClassLc]->name;
        }

        return $className;
    }

    private function hookedPropertyUsesDistinctBacking(ObjectEntry $object, string $propName): bool
    {
        $lcClass = strtolower($object->class->name);
        $propMeta = $this->context->propertyHookRegistry[$lcClass][$propName]
            ?? $this->context->propertyHookRegistry[$lcClass][strtolower($propName)]
            ?? null;
        if (!is_array($propMeta)) {
            return false;
        }
        $backingName = $propMeta['setBacking'] ?? $propMeta['getBacking'] ?? null;

        return null !== $backingName && 0 !== strcasecmp($backingName, $propName);
    }

    private function instancePropertyIsVirtualHook(ObjectEntry $object, string $propName): bool
    {
        $meta = $this->classPropertyMeta($object, $propName);
        if (null !== $meta && $meta->propertyHookVirtual) {
            return true;
        }
        $lcClass = strtolower($object->class->name);
        $propMeta = $this->context->propertyHookRegistry[$lcClass][$propName]
            ?? $this->context->propertyHookRegistry[$lcClass][strtolower($propName)]
            ?? null;

        return is_array($propMeta) && !empty($propMeta['virtual']);
    }

    /** Set-only hook with short `set =>` backing or explicit virtual — external reads forbidden (#6484, #12941, #19163). */
    private function instancePropertyIsWriteOnlyVirtualHook(ObjectEntry $object, string $propName): bool
    {
        $meta = $this->classPropertyMeta($object, $propName);
        if (null === $meta || null === $meta->setHookMethodLc || null !== $meta->getHookMethodLc) {
            return false;
        }
        $lcClass = strtolower($object->class->name);
        $propMeta = $this->context->propertyHookRegistry[$lcClass][$propName]
            ?? $this->context->propertyHookRegistry[$lcClass][strtolower($propName)]
            ?? null;

        return VM\AbstractPropertyHookCheck::isWriteOnlyVirtualHook(
            $propMeta,
            $meta->propertyHookVirtual,
            $propName
        );
    }

    /**
     * @param array<string, mixed> $hooks
     */
    private function staticPropertyIsWriteOnlyVirtualHook(string $classLc, string $propName, array $hooks): bool
    {
        $propMeta = $this->context->propertyHookRegistry[$classLc][$propName]
            ?? $this->context->propertyHookRegistry[$classLc][strtolower($propName)]
            ?? null;

        return VM\AbstractPropertyHookCheck::isWriteOnlyVirtualHook(
            $propMeta,
            !empty($hooks['virtual']),
            $propName
        );
    }

    private function raiseVirtualPropertyHookRawAccessError(
        string $className,
        string $propName,
        bool $isRead,
        Frame $frame
    ): ?Frame {
        return $this->dispatchVmError(
            sprintf(
                'Must not %s virtual property %s::$%s',
                $isRead ? 'read from' : 'write to',
                $className,
                $propName
            ),
            $frame
        );
    }

    /**
     * Compound assignment ($obj->prop += 1) reuses one operand slot (arg1 === arg2).
     * Reject when the lvalue is a readonly instance property after construction (#3149).
     */
    private function enforceReadonlyForCompoundAssign(Frame $frame, OpCode $op, Variable $lvalue): ?Frame
    {
        if ($op->arg1 !== $op->arg2) {
            return null;
        }
        $catchFrame = $this->enforceAsymmetricPropertyWrite($lvalue, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $catchFrame = $this->enforceVirtualPropertyHookWrite($lvalue, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }

        $catchFrame = $this->enforceReadonlyPropertyWrite($lvalue, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }

        return $this->enforceFinalPropertyWrite($lvalue, $frame);
    }

    /**
     * Reject writes to get-only VIRTUAL hooked properties (#4687, #18072, #26006, #29674).
     *
     * php-src: Zend/zend_object_handlers.c — omitting {@code set} on a *backed* property uses
     * default write into the backing store (manual: "omitting a get or set hook means the default
     * read or write behavior will be used"). Only VIRTUAL get-only props Error on write.
     *
     * PHP-8.4 external virtual write: "Property … is read-only".
     * "Must not write to virtual property" is only for raw backing-slot access inside a hook
     * ({@see raiseVirtualPropertyHookRawAccessError} / zend_throw_no_prop_backing_value_access).
     * php-src master tip uses "Cannot write to get-only virtual property …" for the external path —
     * keep the PHP-8.4 string under PROFILE=8.4.
     */
    private function enforceVirtualPropertyHookWrite(Variable $lvalue, Frame $frame): ?Frame
    {
        $target = $lvalue->resolveIndirect();
        $propName = $target->objectPropertyName;
        if (null === $propName) {
            return null;
        }
        if ($this->isPropertyHookRawWrite($frame, $propName)) {
            $owner = $this->resolvePropertyWriteOwner($lvalue);
            if (null !== $owner) {
                return $this->enforceVirtualPropertyHookRawAccess($owner, $propName, false, $frame);
            }

            return null;
        }
        $className = null;
        $virtual = false;
        $hasGetHook = false;
        $hasSetHook = false;
        $classLc = $target->staticPropertyClassLc;
        if (is_string($classLc) && isset($this->context->classes[$classLc])) {
            $entry = $this->context->classes[$classLc];
            $hooks = $this->resolveStaticPropertyHooks($classLc, strtolower($propName)) ?? [];
            $virtual = !empty($hooks['virtual']);
            $hasGetHook = !empty($hooks['get']);
            $hasSetHook = !empty($hooks['set']);
            $className = $entry->name;
        } else {
            $owner = $this->resolvePropertyWriteOwner($lvalue);
            if (null === $owner) {
                return null;
            }
            $meta = $this->classPropertyMeta($owner, $propName);
            if (null === $meta) {
                return null;
            }
            $virtual = $meta->propertyHookVirtual;
            $hasGetHook = null !== $meta->getHookMethodLc;
            $hasSetHook = null !== $meta->setHookMethodLc;
            $className = $owner->class->name;
        }
        if (!$hasGetHook || $hasSetHook) {
            return null;
        }
        // Backed get-only: default write to backing (ctor promo, `$this->x =`, external) — #29674.
        if (!$virtual) {
            return null;
        }
        if ($this->propertyHasDistinctAsymmetricSetVisibility($classLc, $propName, $lvalue)) {
            return $this->enforceAsymmetricPropertyWrite($lvalue, $frame);
        }

        $message = sprintf('Property %s::$%s is read-only', $className, $propName);
        $thrown = VM\BuiltinExceptionSupport::materializeError(
            $this->context,
            $message
        );
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    /**
     * Reject dynamic property creation on readonly classes (Zend zend_objects.c).
     * Returns catch frame or raises uncaught Error (#4799).
     *
     * Route through {@see dispatchVmError} so file/line stamp the user assignment site
     * (php-src zend_object_handlers.c / #25556, #29457).
     *
     * @return ?Frame catch frame when handled; null when no violation or after uncaught raise
     */
    private function enforceReadonlyDynamicPropertyCreate(ObjectEntry $object, string $name, Frame $frame): ?Frame
    {
        if (VM\ObjectReadonlySupport::isDynamicReadonly($object)) {
            return $this->dispatchVmError(
                VM\ObjectReadonlySupport::modifyObjectMessage($object),
                $frame
            );
        }

        if (!$object->class->readonly || $this->hasInstanceMethod($object->class, '__set')) {
            return null;
        }
        if ($object->hasProperty($name)) {
            return null;
        }

        return $this->dispatchVmError(
            sprintf('Cannot create dynamic property %s::$%s', $object->class->name, $name),
            $frame
        );
    }

    /**
     * ZEND_ACC_NO_DYNAMIC_PROPERTIES → catchable Error (zend_object_handlers.c; #26371).
     * Closure/Fiber/Generator/WeakMap reject; Dom\* and other internals allow with E_DEPRECATED (#26566).
     *
     * Route through {@see dispatchVmError} so getFile()/getLine() and uncaught fatals cite the
     * user assignment, not ExceptionSupport.php (#29457, re-#25556).
     *
     * @return ?Frame catch frame when handled; null when allowed or after uncaught raise
     */
    private function enforceInternalDynamicPropertyCreate(ObjectEntry $object, string $name, Frame $frame): ?Frame
    {
        if (!$object->class->noDynamicProperties) {
            return null;
        }
        if ($object->hasProperty($name)) {
            return null;
        }
        // Declared ClassProperty (possibly not yet distinguished from dynamic) — leave to write path.
        if (null !== $this->classPropertyMeta($object, $name, $frame)) {
            return null;
        }
        if ($this->hasInstanceMethod($object->class, '__set')) {
            return null;
        }
        if (SplArrayStorage::hasArrayAsProps($object)) {
            return null;
        }

        return $this->dispatchVmError(
            sprintf('Cannot create dynamic property %s::$%s', $object->class->name, $name),
            $frame
        );
    }

    /**
     * ReflectionProperty::setValue on instance props — same readonly guard as ordinary writes (#15749, php_reflection.c).
     *
     * @throws \Error
     */
    private function assertReadonlyPropertyWriteAllowedForReflection(
        ObjectEntry $object,
        string $propName,
        Frame $frame
    ): void {
        if (VM\ObjectReadonlySupport::isDynamicReadonly($object)) {
            throw new \Error(VM\ObjectReadonlySupport::modifyObjectMessage($object));
        }
        $declaringClass = $this->readonlyPropertyDeclaringClass($object, $propName);
        if (null === $declaringClass) {
            return;
        }
        if (!$object->constructed) {
            // NIWC / mid-ctor: first init only from declaring-class scope (zend_readonly.c, #25745).
            // Prior check skipped null callerClassLc → global `$o->x = …` after NIWC wrongly succeeded.
            if ($this->allowReadonlyPropertyFirstInit($object, $propName, $frame)) {
                return;
            }
            throw new \Error($this->readonlyPropertyWriteErrorMessage($object, $propName, $declaringClass, $frame));
        }
        // Clone-with reinit unlocks readonly once; asymmetric set still applies (#29186).
        if (isset($object->reinitableProperties[$propName])) {
            $avizMsg = $this->asymmetricPropertyWriteMessageForMeta($object, $propName, $frame, true);
            if (null !== $avizMsg) {
                throw new \Error($avizMsg);
            }
            if (VM\CloneWithSupport::consumeReinit($object, $propName)) {
                return;
            }
        }
        // First write after construction from declaring-class scope is initialization (#23475).
        if ($this->allowReadonlyPropertyFirstInit($object, $propName, $frame)) {
            return;
        }

        throw new \Error($this->readonlyPropertyWriteErrorMessage($object, $propName, $declaringClass, $frame));
    }

    /**
     * ReflectionProperty::setValue — plain `final` does not block writes (php-src-strict, #23683).
     *
     * Verified Zend PHP 8.4.23 / 8.5.8: `final` is inheritance-only (zend_inheritance.c).
     * Asymmetric set visibility (private(set), #23068/#23110) still governs Reflection writes.
     */
    private function assertFinalPropertyWriteAllowedForReflection(
        ObjectEntry $object,
        string $propName
    ): void {
        // No-op: php-src has no "Cannot modify final property" write path.
        unset($object, $propName);
    }

    /**
     * Reject `&$obj->readonlyProp` at fetch-for-write / ASSIGN_REF time (#25620).
     *
     * php-src: Zend/zend_readonly.c / zend_object_handlers.c get_property_ptr_ptr —
     * initialized props use "Cannot modify…"; uninitialized use "Cannot indirectly modify…".
     */
    private function enforceReadonlyPropertyFetchByRef(Variable $lvalue, Frame $frame): ?Frame
    {
        $owner = $this->resolvePropertyWriteOwner($lvalue);
        if (null !== $owner && VM\ObjectReadonlySupport::isDynamicReadonly($owner)) {
            return $this->dispatchVmError(
                VM\ObjectReadonlySupport::modifyObjectMessage($owner),
                $frame
            );
        }
        if (null === $owner) {
            return null;
        }
        $prop = $this->resolvePropertyWriteName($lvalue) ?? 'property';
        $declaringClass = $this->readonlyPropertyDeclaringClass($owner, $prop);
        if (null === $declaringClass) {
            return null;
        }
        $uninitialized = !$owner->hasProperty($prop)
            || VM\TypedPropertyCheck::isUninitialized($owner->getProperty($prop));
        $declaringClass = MethodVisibility::formatAnonymousScopeForMessage($declaringClass);
        $message = $uninitialized
            ? sprintf('Cannot indirectly modify readonly property %s::$%s', $declaringClass, $prop)
            : sprintf('Cannot modify readonly property %s::$%s', $declaringClass, $prop);

        return $this->dispatchVmError($message, $frame);
    }

    /**
     * Reject readonly property writes; returns catch frame or throws when uncaught.
     *
     * Route through {@see dispatchVmError} so file/line stamp the user assignment site
     * (php-src zend_readonly_property_modification_error / #25556, re-#7343).
     */
    private function enforceReadonlyPropertyWrite(Variable $lvalue, Frame $frame): ?Frame
    {
        if ($this->shouldDeferReadonlyForPropertySetHook($lvalue, $frame)) {
            return null;
        }
        $owner = $this->resolvePropertyWriteOwner($lvalue);
        if (null !== $owner && VM\ObjectReadonlySupport::isDynamicReadonly($owner)) {
            return $this->dispatchVmError(
                VM\ObjectReadonlySupport::modifyObjectMessage($owner),
                $frame
            );
        }

        if (null === $owner) {
            return null;
        }
        $prop = $this->resolvePropertyWriteName($lvalue) ?? 'property';
        $declaringClass = $this->readonlyPropertyDeclaringClass($owner, $prop);
        if (null === $declaringClass) {
            return null;
        }
        if (!$owner->constructed) {
            // NIWC / mid-ctor: first init only from declaring-class scope (zend_readonly.c, #25745).
            // Prior check skipped null callerClassLc → global `$o->x = …` after NIWC wrongly succeeded.
            if ($this->allowReadonlyPropertyFirstInit($owner, $prop, $frame)) {
                return null;
            }

            return $this->dispatchVmError(
                $this->readonlyPropertyWriteErrorMessage($owner, $prop, $declaringClass, $frame),
                $frame
            );
        }
        // Clone-with reinit unlocks readonly once; asymmetric set still applies (#29186).
        if (isset($owner->reinitableProperties[$prop])) {
            $avizMsg = $this->asymmetricPropertyWriteMessageForMeta($owner, $prop, $frame, true);
            if (null !== $avizMsg) {
                return $this->dispatchVmError($avizMsg, $frame);
            }
            if (VM\CloneWithSupport::consumeReinit($owner, $prop)) {
                return null;
            }
        }
        // First write after construction from declaring-class scope is initialization (#23475).
        if ($this->allowReadonlyPropertyFirstInit($owner, $prop, $frame)) {
            return null;
        }

        return $this->dispatchVmError(
            $this->readonlyPropertyWriteErrorMessage($owner, $prop, $declaringClass, $frame),
            $frame
        );
    }

    /**
     * Zend allows the first assignment to an uninitialized readonly property from any
     * instance method of the declaring class — not only `__construct` (#23475).
     *
     * php-src: Zend/zend_object_handlers.c / Zend/zend_readonly.c
     */
    private function allowReadonlyPropertyFirstInit(ObjectEntry $owner, string $prop, Frame $frame): bool
    {
        $declaringClassLc = $this->readonlyPropertyDeclaringClassLc($owner, $prop);
        $callerClassLc = $this->callerClassLc($frame);
        if (null === $declaringClassLc || null === $callerClassLc || $callerClassLc !== $declaringClassLc) {
            return false;
        }
        if (!$owner->hasProperty($prop)) {
            return true;
        }

        return VM\TypedPropertyCheck::isUninitialized($owner->getProperty($prop));
    }

    /**
     * Plain `final` properties are inheritance-only in php-src (#23683, re-#22450).
     *
     * Verified Zend PHP 8.4.23 / 8.5.8: post-construct writes succeed; child redeclaration
     * fatals via {@see Compiler\FinalPropertyOverrideCheck}. External private(set) denies
     * remain on the asymmetric write path (#23110).
     */
    private function enforceFinalPropertyWrite(Variable $lvalue, Frame $frame): ?Frame
    {
        unset($lvalue, $frame);

        return null;
    }

    /**
     * True when set visibility differs from the property's read visibility flags (#3165, #23110).
     */
    private function classPropertyHasDistinctAsymmetricSetVisibility(VM\ClassProperty $meta): bool
    {
        return PropertyVisibility::effectiveSetVisibility($meta->visibility, $meta->setVisibility)
            !== MethodVisibility::mask($meta->visibility);
    }

    /** Zend zend_readonly_property_modification_error — init vs modify wording (#5463). */
    private function readonlyPropertyWriteErrorMessage(
        ObjectEntry $owner,
        string $prop,
        string $declaringClass,
        Frame $frame
    ): string {
        // Strip @anonymous\0file:line$id provenance for Error messages (#29250 / #26031).
        $declaringClass = MethodVisibility::formatAnonymousScopeForMessage($declaringClass);
        if ($owner->hasProperty($prop)) {
            $slot = $owner->getProperty($prop);
            if (VM\TypedPropertyCheck::isUninitialized($slot)) {
                return sprintf(
                    'Cannot initialize readonly property %s::$%s from %s',
                    $declaringClass,
                    $prop,
                    $this->propertyWriteScopeLabel($frame)
                );
            }
        }

        return sprintf('Cannot modify readonly property %s::$%s', $declaringClass, $prop);
    }

    /**
     * Zend routes external writes through set hooks; readonly backing checks run on raw writes inside the hook (#4518).
     */
    private function shouldDeferReadonlyForPropertySetHook(Variable $lvalue, Frame $frame): bool
    {
        $propName = $this->resolvePropertyWriteName($lvalue);
        if (null === $propName || $this->isPropertyHookRawWrite($frame, $propName)) {
            return false;
        }
        $owner = $this->resolvePropertyWriteOwner($lvalue);
        if (null !== $owner) {
            $meta = $this->classPropertyMeta($owner, $propName);

            return null !== $meta && null !== $meta->setHookMethodLc;
        }
        $target = $lvalue->resolveIndirect();
        $classLc = $lvalue->staticPropertyClassLc ?? $target->staticPropertyClassLc;
        $staticPropName = $lvalue->objectPropertyName ?? $target->objectPropertyName;
        if (!is_string($classLc) || !is_string($staticPropName) || '' === $staticPropName) {
            return false;
        }
        $hooks = $this->resolveStaticPropertyHooks($classLc, strtolower($staticPropName));

        return null !== $hooks && !empty($hooks['set']);
    }

    private function propertyWriteScopeLabel(Frame $frame): string
    {
        $callerClassLc = $this->callerClassLc($frame);
        if (null === $callerClassLc) {
            return 'global scope';
        }
        $className = isset($this->context->classes[$callerClassLc])
            ? $this->context->classes[$callerClassLc]->name
            : $callerClassLc;

        return 'scope ' . $className;
    }

    private function enforcePropertyVisibilityWrite(Variable $lvalue, Frame $frame): ?Frame
    {
        $target = $lvalue->resolveIndirect();
        $owner = $target->objectPropertyOwner;
        if (null === $owner) {
            return null;
        }

        return $this->enforcePropertyWriteVisibility($owner, $target->objectPropertyName ?? 'property', $frame);
    }

    private function enforcePropertyVisibilityRead(ObjectEntry $object, string $propName, Frame $frame): ?Frame
    {
        return $this->enforcePropertyReadVisibility($object, $propName, $frame);
    }

    private function isParentPrivatePropertyInvisibleFromCaller(
        VM\ClassProperty $meta,
        Frame $frame,
        ObjectEntry $object
    ): bool {
        return PropertyVisibility::isParentPrivatePropertyInvisibleFromChildScope(
            $meta->visibility,
            $this->callerClassLc($frame),
            $meta->declaringClassLc,
            fn (string $classLc, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLc, $ancestorLc),
            $meta->getVisibility,
            strtolower($object->class->name)
        );
    }

    private function enforcePropertyReadVisibility(ObjectEntry $object, string $propName, Frame $frame): ?Frame
    {
        $meta = $this->classPropertyMeta($object, $propName, $frame);
        if (null === $meta) {
            return null;
        }
        if ($this->isParentPrivatePropertyInvisibleFromCaller($meta, $frame, $object)) {
            return null;
        }
        $readVis = PropertyVisibility::effectiveGetVisibility($meta->visibility, $meta->getVisibility);
        if (MethodVisibility::isPublic($readVis)) {
            return null;
        }
        $declaringDisplay = $this->context->classes[$meta->declaringClassLc]->name
            ?? $meta->declaringClassLc;
        try {
            PropertyVisibility::assertAccessible(
                $meta->visibility,
                $this->callerClassLc($frame),
                $meta->declaringClassLc,
                $declaringDisplay,
                $propName,
                strtolower($object->class->name),
                fn (string $classLc, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLc, $ancestorLc),
                $meta->getVisibility
            );
        } catch (\LogicException $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }

        return null;
    }

    private function enforcePropertyWriteVisibility(ObjectEntry $object, string $propName, Frame $frame): ?Frame
    {
        if (null !== $this->context->lazyGhostInitializing
            && $this->context->lazyGhostInitializing === $object) {
            return null;
        }
        $meta = $this->classPropertyMeta($object, $propName, $frame);
        if (null === $meta) {
            return null;
        }
        $writeVis = PropertyVisibility::effectiveSetVisibility($meta->visibility, $meta->setVisibility);
        $readVis = PropertyVisibility::effectiveGetVisibility($meta->visibility, $meta->getVisibility);
        if ($writeVis !== $readVis) {
            return null;
        }
        if (MethodVisibility::isPublic($writeVis)) {
            return null;
        }
        $declaringDisplay = $this->context->classes[$meta->declaringClassLc]->name
            ?? $meta->declaringClassLc;
        try {
            PropertyVisibility::assertAccessible(
                $meta->visibility,
                $this->callerClassLc($frame),
                $meta->declaringClassLc,
                $declaringDisplay,
                $propName,
                strtolower($object->class->name),
                fn (string $classLc, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLc, $ancestorLc),
                0
            );
        } catch (\LogicException $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }

        return null;
    }

    private function enforceDirectTraitConstAccess(ClassEntry $classEntry, string $constName, Frame $frame): ?Frame
    {
        if (!$classEntry->isTrait || 'class' === strtolower($constName)) {
            return null;
        }
        if ($this->isInTraitMethodScopeForTrait($frame, $classEntry)) {
            return null;
        }

        return $this->dispatchVmError(
            "Cannot access trait constant {$classEntry->name}::{$constName} directly",
            $frame
        );
    }

    /** self::CONST inside trait methods lowers to T::CONST — allow in-trait scope (#9187, Zend/zend_traits.c). */
    private function isInTraitMethodScopeForTrait(Frame $frame, ClassEntry $traitEntry): bool
    {
        if (!$traitEntry->isTrait) {
            return false;
        }
        $traitLc = strtolower(ltrim($traitEntry->name, '\\'));
        if (null !== $frame->block?->func?->class) {
            $funcClassLc = strtolower($frame->block->func->class->value);
            if ($funcClassLc === $traitLc) {
                return true;
            }
        }
        $declaringLc = null;
        if (null !== $frame->block?->func?->class) {
            $declaringLc = strtolower($frame->block->func->class->value);
        } elseif (null !== $frame->calledClass && '' !== $frame->calledClass) {
            $declaringLc = strtolower($frame->calledClass);
        }
        if (null === $declaringLc) {
            return false;
        }
        $scopeTraitLc = $this->traitScopeLcForFrameMethod($frame, $declaringLc);

        return null !== $scopeTraitLc && $scopeTraitLc === $traitLc;
    }

    private function enforceClassConstVisibility(ClassEntry $classEntry, string $constName, Frame $frame): ?Frame
    {
        $constKey = ClassConstName::key($constName);
        $vis = $classEntry->constVisibility[$constKey] ?? \PHPCfg\Func::FLAG_PUBLIC;
        if (MethodVisibility::isPublic($vis)) {
            return null;
        }
        // Trait methods keep access to private/protected consts imported from that trait onto the
        // composing class when self:: binds to the composing class (#9187, #19629, zend_traits.c).
        $sourceTrait = $classEntry->traitConstSources[$constKey] ?? null;
        if (null !== $sourceTrait && '' !== $sourceTrait) {
            $traitLc = strtolower(ltrim($sourceTrait, '\\'));
            $traitEntry = $this->context->classes[$traitLc] ?? null;
            if (null !== $traitEntry && $this->isInTraitMethodScopeForTrait($frame, $traitEntry)) {
                return null;
            }
        }
        try {
            ClassConstVisibility::assertAccessible(
                $vis,
                $this->callerClassLc($frame),
                strtolower($classEntry->name),
                $classEntry->name,
                $constName,
                fn (string $classLc, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLc, $ancestorLc)
            );
        } catch (\LogicException $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }

        return null;
    }

    /**
     * @return array{
     *     visibility: int,
     *     setVisibility: int,
     *     getVisibility: int,
     *     asymmetricExplicitRead: bool,
     *     declaringClassLc: string,
     *     declaringClassDisplay: string
     * }|null
     */
    protected function resolveStaticPropertyVisibilityMeta(string $classLc, string $propLc): ?array
    {
        $currentLc = $classLc;
        while (isset($this->context->classes[$currentLc])) {
            $entry = $this->context->classes[$currentLc];
            if (isset($entry->staticProperties[$propLc])) {
                $declLc = $entry->staticPropertyDeclaringClassLc[$propLc] ?? $currentLc;
                $declEntry = $this->context->classes[$declLc] ?? $entry;

                return [
                    'visibility' => $entry->staticPropertyVisibility[$propLc] ?? \PHPCfg\Func::FLAG_PUBLIC,
                    'setVisibility' => $entry->staticPropertySetVisibility[$propLc] ?? 0,
                    'getVisibility' => $entry->staticPropertyGetVisibility[$propLc] ?? 0,
                    'asymmetricExplicitRead' => $entry->staticPropertyAsymmetricExplicitRead[$propLc] ?? false,
                    'declaringClassLc' => $declLc,
                    'declaringClassDisplay' => $declEntry->name,
                ];
            }
            if (null === $entry->parentLc) {
                break;
            }
            $currentLc = $entry->parentLc;
        }

        return null;
    }

    private function enforceStaticPropertyVisibilityWrite(Variable $lvalue, Frame $frame): ?Frame
    {
        $target = $lvalue->resolveIndirect();
        $classLc = $lvalue->staticPropertyClassLc ?? $target->staticPropertyClassLc;
        $propName = $lvalue->objectPropertyName ?? $target->objectPropertyName;
        if ((!is_string($classLc) || !is_string($propName) || '' === $propName)
            && $this->isStaticPropertyStorageCell($target)) {
            foreach ($this->context->classes as $entry) {
                foreach ($entry->staticProperties as $propLc => $storage) {
                    if ($storage !== $target) {
                        continue;
                    }
                    $classLc = strtolower($entry->name);
                    $propName = $entry->staticProperties[$propLc]->objectPropertyName ?? $propLc;
                    break 2;
                }
            }
        }
        if (!is_string($classLc) || !is_string($propName) || '' === $propName) {
            return null;
        }
        $catchFrame = $this->enforceStaticPropertyWriteVisibility($classLc, $propName, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $msg = $this->asymmetricStaticPropertyWriteMessage($classLc, $propName, $frame);
        if (null !== $msg) {
            return $this->dispatchVmError($msg, $frame);
        }

        return null;
    }

    private function enforceStaticPropertyWriteVisibility(string $classLc, string $propName, Frame $frame): ?Frame
    {
        $meta = $this->resolveStaticPropertyVisibilityMeta($classLc, strtolower($propName));
        if (null === $meta) {
            return null;
        }
        $writeVis = PropertyVisibility::effectiveSetVisibility($meta['visibility'], $meta['setVisibility']);
        $readVis = PropertyVisibility::effectiveGetVisibility($meta['visibility'], $meta['getVisibility']);
        if ($writeVis !== $readVis) {
            return null;
        }
        if (MethodVisibility::isPublic($writeVis)) {
            return null;
        }
        try {
            // Error names the fetched class (self/static/parent/explicit), not the declarer (#29524).
            PropertyVisibility::assertAccessible(
                $meta['visibility'],
                $this->callerClassLc($frame),
                $meta['declaringClassLc'],
                $this->staticPropertyFetchClassDisplay($classLc),
                $propName,
                $this->callerClassLc($frame) ?? $meta['declaringClassLc'],
                fn (string $classLcArg, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLcArg, $ancestorLc),
                0
            );
        } catch (\LogicException $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }

        return null;
    }

    private function asymmetricStaticPropertyWriteMessage(string $classLc, string $propName, Frame $frame): ?string
    {
        $meta = $this->resolveStaticPropertyVisibilityMeta($classLc, strtolower($propName));
        if (null === $meta) {
            return null;
        }
        $setVis = PropertyVisibility::effectiveSetVisibility($meta['visibility'], $meta['setVisibility']);
        $readVis = PropertyVisibility::effectiveGetVisibility($meta['visibility'], $meta['getVisibility']);
        if ($setVis === $readVis) {
            return null;
        }
        $callerLc = $this->callerClassLc($frame);
        try {
            PropertyVisibility::assertWritable(
                $setVis,
                $callerLc,
                $meta['declaringClassLc'],
                $this->staticPropertyFetchClassDisplay($classLc),
                $propName,
                fn (string $child, string $parent): bool => $this->isSubclassOf($child, $parent),
                MethodVisibility::mask($readVis),
                $meta['asymmetricExplicitRead'] ?? false,
                $this->callerScopeDisplay($frame, $callerLc)
            );
        } catch (\LogicException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function enforceStaticPropertyReadVisibility(
        string $classLc,
        string $propNameRaw,
        Frame $frame
    ): ?Frame {
        $propLc = strtolower($propNameRaw);
        $meta = $this->resolveStaticPropertyVisibilityMeta($classLc, $propLc);
        if (null === $meta) {
            return null;
        }
        $readVis = PropertyVisibility::effectiveGetVisibility($meta['visibility'], $meta['getVisibility']);
        if (MethodVisibility::isPublic($readVis)) {
            return null;
        }
        $callerLc = $this->callerClassLc($frame);
        try {
            // php-src zend_std_get_static_property: Error uses the fetch CE (self→child), not declarer (#29524).
            PropertyVisibility::assertAccessible(
                $meta['visibility'],
                $callerLc,
                $meta['declaringClassLc'],
                $this->staticPropertyFetchClassDisplay($classLc),
                $propNameRaw,
                $callerLc ?? $meta['declaringClassLc'],
                fn (string $classLcArg, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLcArg, $ancestorLc),
                $meta['getVisibility']
            );
        } catch (\LogicException $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }

        return null;
    }

    /** Display name of the class used in a static property fetch (self/static/parent/Foo::). */
    private function staticPropertyFetchClassDisplay(string $classLc): string
    {
        return $this->context->classes[$classLc]->name ?? $classLc;
    }

    /**
     * Closure scope (ce) for self/parent/private — not late-static called_scope (#3673, #25793).
     *
     * Explicit bindTo($obj, null) leaves boundScopeClass null/empty; do not fall back to
     * the definition-site func->class or calledClass ($this) — that re-widens visibility
     * (#10097, #25838, zend_closures.c).
     */
    private function boundClosureScopeClassLc(Frame $frame): ?string
    {
        if (null === $frame->block || null === $frame->block->func) {
            return null;
        }
        if ((($frame->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE) === 0) {
            return null;
        }
        $state = $frame->closureCall ?? $frame->pendingClosureInvoke;
        if (null !== $state) {
            if (null === $state->boundScopeClass || '' === $state->boundScopeClass) {
                return null;
            }

            return strtolower($state->boundScopeClass);
        }
        if (null !== $frame->block->func->class && null !== $frame->block->func->class->value
            && '' !== $frame->block->func->class->value) {
            return strtolower($frame->block->func->class->value);
        }

        return null;
    }

    /**
     * Late-static called_scope for a closure: $this's class, else stored creation LSB, else scope.
     */
    private function closureCalledScopeClass(ClosureState $state): ?string
    {
        if (null !== $state->boundThis) {
            $thisObj = $state->boundThis->resolveIndirect();
            if (Variable::TYPE_OBJECT === $thisObj->type) {
                return $thisObj->toObject()->class->name;
            }
        }
        if (null !== $state->boundCalledScopeClass && '' !== $state->boundCalledScopeClass) {
            return $state->boundCalledScopeClass;
        }
        if (null !== $state->boundScopeClass && '' !== $state->boundScopeClass) {
            return $state->boundScopeClass;
        }

        return null;
    }

    private function callerClassLc(Frame $frame): ?string
    {
        $classLc = $this->boundClosureScopeClassLc($frame);
        if (null === $classLc) {
            // Closure frames: scope (ce) is the only visibility source. calledClass is
            // late-static ($this class from #25793) and must not grant protected/private
            // when bindTo left the scope unbound (#10097, #25838).
            $isClosure = null !== $frame->block
                && null !== $frame->block->func
                && (($frame->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE) !== 0;
            if (!$isClosure) {
                if (null !== $frame->block && null !== $frame->block->func && null !== $frame->block->func->class) {
                    $classLc = strtolower($frame->block->func->class->value);
                } elseif (null !== $frame->calledClass && '' !== $frame->calledClass) {
                    $classLc = strtolower($frame->calledClass);
                }
            }
        }
        if (null === $classLc) {
            return null;
        }
        // Trait methods: resolve to composing class for protected/public (#24732),
        // keep trait scope only for private (#4834).
        if (isset($this->context->classes[$classLc]) && $this->context->classes[$classLc]->isTrait) {
            $composing = $this->resolveTraitComposingClassLc($frame, $classLc);
            if (null !== $composing) {
                $classLc = $composing;
            }
        }
        $traitLc = $this->traitScopeLcForFrameMethod($frame, $classLc);

        return $traitLc ?? $classLc;
    }

    /**
     * php-src: unbound Closure::bind/bindTo uses lexical scope "Closure" in visibility errors (zend_closures.c).
     */
    private function callerScopeDisplay(Frame $frame, ?string $callerClassLc): ?string
    {
        if (null !== $callerClassLc && isset($this->context->classes[$callerClassLc])) {
            return $this->context->classes[$callerClassLc]->name;
        }
        if ($this->isUnscopedUserClosureFrame($frame)) {
            return 'Closure';
        }

        return null;
    }

    private function isUnscopedUserClosureFrame(Frame $frame): bool
    {
        $state = $frame->closureCall ?? $frame->pendingClosureInvoke;
        if (null === $state || !$state->isUserClosure()) {
            return false;
        }

        return null === $state->boundScopeClass || '' === $state->boundScopeClass;
    }

    /**
     * When executing inside a trait method, resolve the composing (using) class from $this or
     * calledClass — Zend rebinds trait methods into the using class's scope (#24732).
     */
    private function resolveTraitComposingClassLc(Frame $frame, string $traitClassLc): ?string
    {
        // Prefer the object's actual class from $this.
        if (!empty($frame->scope)) {
            foreach ($frame->scope as $var) {
                if (Variable::TYPE_OBJECT === $var->type) {
                    $objClassLc = strtolower($var->toObject()->class->name);
                    if ($objClassLc !== $traitClassLc
                        && (!isset($this->context->classes[$objClassLc]) || !$this->context->classes[$objClassLc]->isTrait)) {
                        return $objClassLc;
                    }
                }
                break; // slot 0 is $this
            }
        }
        if (null !== $frame->calledClass && '' !== $frame->calledClass) {
            $calledLc = strtolower($frame->calledClass);
            if ($calledLc !== $traitClassLc
                && (!isset($this->context->classes[$calledLc]) || !$this->context->classes[$calledLc]->isTrait)) {
                return $calledLc;
            }
        }

        return null;
    }

    /**
     * Trait-sourced methods use trait scope for private member access (#4834, zend_compile.c).
     * Protected/public trait methods use the composing class scope (#24732, Zend/zend_inheritance.c).
     */
    private function traitScopeLcForFrameMethod(Frame $frame, string $classLc): ?string
    {
        if (!isset($this->context->classes[$classLc])) {
            return null;
        }
        $func = $frame->block->func;
        if (null === $func || !isset($func->name)) {
            return null;
        }
        $methodLc = strtolower((string) $func->name);
        $classEntry = $this->context->classes[$classLc];
        $traitName = $classEntry->traitMethodSources[$methodLc] ?? null;
        if (null === $traitName) {
            return null;
        }
        $vis = $classEntry->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
        if (($vis & \PHPCfg\Func::FLAG_PRIVATE) === 0) {
            return null;
        }

        return strtolower(ltrim($traitName, '\\'));
    }

    /**
     * Resolve instance owner for a property-write lvalue, including indirect wrappers (#6146).
     */
    private function resolvePropertyWriteOwner(Variable $lvalue): ?ObjectEntry
    {
        $var = $lvalue;
        $seen = [];
        while (true) {
            $id = \spl_object_id($var);
            if (isset($seen[$id])) {
                break;
            }
            $seen[$id] = true;
            if (null !== $var->objectPropertyOwner) {
                return $var->objectPropertyOwner;
            }
            if (null !== $var->magicSetTarget) {
                return $var->magicSetTarget;
            }
            if (null !== $var->hookedPropertyDimWriteBackContainer) {
                $container = $var->hookedPropertyDimWriteBackContainer;
                if (null !== $container->objectPropertyOwner) {
                    return $container->objectPropertyOwner;
                }
            }
            if (!$var->isIndirect()) {
                break;
            }
            $next = $var->directIndirectTarget();
            if (null === $next) {
                break;
            }
            $var = $next;
        }

        return null;
    }

    /** Property name for a property-write lvalue when metadata lives on an indirect wrapper (#6146). */
    private function resolvePropertyWriteName(Variable $lvalue): ?string
    {
        $var = $lvalue;
        $seen = [];
        while (true) {
            $id = \spl_object_id($var);
            if (isset($seen[$id])) {
                break;
            }
            $seen[$id] = true;
            if (null !== $var->objectPropertyName) {
                return $var->objectPropertyName;
            }
            if (null !== $var->magicSetName) {
                return $var->magicSetName;
            }
            if (null !== $var->hookedPropertyDimWriteBackContainer) {
                $container = $var->hookedPropertyDimWriteBackContainer;
                if (null !== $container->objectPropertyName) {
                    return $container->objectPropertyName;
                }
            }
            if (!$var->isIndirect()) {
                break;
            }
            $next = $var->directIndirectTarget();
            if (null === $next) {
                break;
            }
            $var = $next;
        }

        return null;
    }

    private function readonlyPropertyDeclaringClass(ObjectEntry $object, string $propName): ?string
    {
        $meta = $this->classPropertyMeta($object, $propName);
        if (null !== $meta && ($meta->readonly || $object->class->readonly)) {
            if ('' !== $meta->declaringClassLc && isset($this->context->classes[$meta->declaringClassLc])) {
                return $this->context->classes[$meta->declaringClassLc]->name;
            }

            return $meta->declaringClassLc !== '' ? $meta->declaringClassLc : $object->class->name;
        }
        if ($object->class->readonly) {
            return $object->class->name;
        }

        return null;
    }

    private function readonlyPropertyDeclaringClassLc(ObjectEntry $object, string $propName): ?string
    {
        $meta = $this->classPropertyMeta($object, $propName);
        if (null !== $meta && ($meta->readonly || $object->class->readonly)) {
            return '' !== $meta->declaringClassLc ? $meta->declaringClassLc : strtolower($object->class->name);
        }
        if ($object->class->readonly) {
            return strtolower($object->class->name);
        }

        return null;
    }

    /** Reject asymmetric set visibility violations (#3165, #6898); returns catch frame or null. */
    private function enforceAsymmetricPropertyWrite(Variable $lvalue, Frame $frame): ?Frame
    {
        $msg = $this->asymmetricPropertyWriteMessage($lvalue, $frame);
        if (null === $msg) {
            return null;
        }

        return $this->dispatchVmError($msg, $frame);
    }

    /**
     * Hook-block asymmetric markers use {@code set (private);} (php-compiler) or decl-site
     * {@code private(set)}; bare {@code private(set);} on a hook is a compile fatal (#29388).
     */
    private function propertyHasDistinctAsymmetricSetVisibility(
        ?string $staticClassLc,
        string $propName,
        Variable $lvalue
    ): bool {
        if (is_string($staticClassLc) && isset($this->context->classes[$staticClassLc])) {
            $visMeta = $this->resolveStaticPropertyVisibilityMeta($staticClassLc, strtolower($propName));

            return null !== $visMeta
                && PropertyVisibility::effectiveSetVisibility($visMeta['visibility'], $visMeta['setVisibility'])
                    !== MethodVisibility::mask($visMeta['visibility']);
        }
        $owner = $this->resolvePropertyWriteOwner($lvalue);
        if (null === $owner) {
            return false;
        }
        $meta = $this->classPropertyMeta($owner, $propName);
        if (null === $meta) {
            return false;
        }

        return PropertyVisibility::effectiveSetVisibility($meta->visibility, $meta->setVisibility)
            !== MethodVisibility::mask($meta->visibility);
    }

    /** Reject asymmetric set visibility violations (#3165); returns message or null. */
    private function asymmetricPropertyWriteMessage(Variable $lvalue, Frame $frame): ?string
    {
        $target = $lvalue->resolveIndirect();
        $owner = $target->objectPropertyOwner;
        if (null === $owner) {
            return null;
        }
        $propName = $target->objectPropertyName ?? '';
        if ('' === $propName) {
            return null;
        }

        return $this->asymmetricPropertyWriteMessageForMeta($owner, $propName, $frame, false);
    }

    /**
     * @param bool $readonlyReinitWindow when true, enforce aviz even for readonly props (clone-with, #29186)
     */
    private function asymmetricPropertyWriteMessageForMeta(
        ObjectEntry $owner,
        string $propName,
        Frame $frame,
        bool $readonlyReinitWindow
    ): ?string {
        $meta = $this->classPropertyMeta($owner, $propName);
        if (null === $meta) {
            return null;
        }
        // Ordinary readonly writes use the readonly Error; aviz applies after reinit unlock (#29186).
        if ($meta->readonly && !$readonlyReinitWindow) {
            return null;
        }
        $setVis = PropertyVisibility::effectiveSetVisibility($meta->visibility, $meta->setVisibility);
        $readVis = PropertyVisibility::effectiveGetVisibility($meta->visibility, $meta->getVisibility);
        if ($setVis === $readVis) {
            return null;
        }
        // Use declaring class (not runtime object class) so private(set) denies child scopes (#23110).
        $declaringLc = '' !== $meta->declaringClassLc
            ? $meta->declaringClassLc
            : strtolower($owner->class->name);
        $declaringDisplay = $this->context->classes[$declaringLc]->name
            ?? $owner->class->name;
        $callerLc = $this->callerClassLc($frame);
        try {
            PropertyVisibility::assertWritable(
                $setVis,
                $callerLc,
                $declaringLc,
                $declaringDisplay,
                $propName,
                fn (string $child, string $parent): bool => $this->isSubclassOf($child, $parent),
                MethodVisibility::mask($readVis),
                $meta->asymmetricExplicitRead,
                $this->callerScopeDisplay($frame, $callerLc),
                $meta->readonly
            );
        } catch (\LogicException $e) {
            return $e->getMessage();
        }

        return null;
    }

    private function logicExceptionVariable(string $message): Variable
    {
        $lc = 'logicexception';
        if (!isset($this->context->classes[$lc])) {
            $entry = new ClassEntry('LogicException');
            $msgProto = new Variable(Variable::TYPE_STRING);
            $entry->properties[] = new VM\ClassProperty('message', null, $msgProto);
            $this->context->classes[$lc] = $entry;
        }
        $obj = new ObjectEntry($this->context->classes[$lc]);
        $obj->constructed = true;
        $obj->getProperty('message')->string($message);
        $var = new Variable(Variable::TYPE_OBJECT);
        $var->object($obj);

        return $var;
    }

    private function isSubclassOf(string $childLc, string $parentLc): bool
    {
        $current = $childLc;
        while (isset($this->context->classes[$current])) {
            $parent = $this->context->classes[$current]->parentLc;
            if (null === $parent) {
                return false;
            }
            if ($parent === $parentLc) {
                return true;
            }
            $current = $parent;
        }

        return false;
    }

    private function markObjectConstructedIfLeavingConstruct(Frame $frame): void
    {
        if (!$this->isConstructFrame($frame)) {
            return;
        }
        if (empty($frame->calledArgs)) {
            return;
        }
        $thisArg = $frame->calledArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $thisArg->type) {
            return;
        }
        $thisArg->toObject()->constructed = true;
    }

    private function markPendingNewObjectConstructed(Frame $frame): void
    {
        if (empty($frame->callArgs)) {
            return;
        }
        $objVar = $frame->callArgs[0]->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $objVar->type) {
            return;
        }
        $objVar->toObject()->constructed = true;
    }

    private function isConstructFrame(Frame $frame): bool
    {
        $func = $frame->block->func ?? null;
        if (null === $func) {
            return false;
        }
        $name = strtolower($func->name);

        return '__construct' === $name || str_ends_with($name, '::__construct');
    }

    private function variableIsGenerator(Variable $container): bool
    {
        $container = $container->resolveIndirect();

        return Variable::TYPE_OBJECT === $container->type
            && null !== $container->toObject()->generatorState;
    }

    /**
     * Zend FE_RESET_R: foreach by-value keeps an addRef'd snapshot so mutators (array_pop, etc.)
     * COW-separate the live variable without truncating iteration (Zend/zend_execute.c, #13138).
     */
    private function bindArrayForeachIteratorContainer(Frame $frame, int $slot, Variable $source): void
    {
        $source = $source->resolveIndirect();
        if (Variable::TYPE_ARRAY !== $source->type) {
            throw new \LogicException('Array foreach reset requires an array');
        }
        $ht = $source->toArray();
        $ht->addRef();
        $iterContainer = new Variable();
        $iterContainer->array($ht);
        $frame->iterators[$slot] = $iterContainer;
        $this->context->foreachIterators[$slot] = $iterContainer;
        $ht->iterReset();
    }

    /** Zend FE_RESET_RW: by-reference foreach iterates the live array HashTable. */
    private function rebindArrayForeachToLiveContainer(Frame $frame, int $slot): void
    {
        if (!isset($frame->scope[$slot])) {
            return;
        }
        $frame->iterators[$slot] = $frame->scope[$slot];
        $this->context->foreachIterators[$slot] = $frame->scope[$slot];
    }

    private function resolveForeachContainer(Frame $frame, int $slot): Variable
    {
        if (isset($this->context->foreachIterators[$slot])) {
            return $this->context->foreachIterators[$slot]->resolveIndirect();
        }
        if (isset($frame->iterators[$slot])) {
            return $frame->iterators[$slot]->resolveIndirect();
        }
        if ($this->isForeachObjectIteratorSlot($slot)) {
            throw new \LogicException('Foreach iterator container slot is not initialized');
        }

        return $frame->scope[$slot]->resolveIndirect();
    }

    private function objectForeachIterator(int $slot): ObjectPropertyIterator
    {
        if (!isset($this->context->objectPropertyIterators[$slot])) {
            throw new \LogicException('Object foreach iterator not initialized');
        }

        return $this->context->objectPropertyIterators[$slot];
    }

    private function weakMapForeachIterator(int $slot): WeakMapIterator
    {
        if (!isset($this->context->weakMapIterators[$slot])) {
            throw new \LogicException('WeakMap foreach iterator not initialized');
        }

        return $this->context->weakMapIterators[$slot];
    }

    private function isWeakMapForeachSlot(int $slot): bool
    {
        return isset($this->context->weakMapIterators[$slot]);
    }

    private function isForeachObjectIteratorSlot(int $slot): bool
    {
        return array_key_exists($slot, $this->context->foreachObjectAdvance);
    }

    private function isForeachInvalidSlot(int $slot): bool
    {
        return isset($this->context->foreachInvalidSlots[$slot]);
    }

    /**
     * Zend ZEND_FE_RESET_R invalid operand (zend_vm_def.h, #4879).
     *
     * Line comes from the ITER_RESET opcode's foreach site (#27953) — not the prior statement.
     */
    private function warnForeachNonTraversable(Variable $container, Frame $frame, ?OpCode $op = null): void
    {
        $resolved = $container->resolveIndirect();
        $line = 0;
        if (null !== $op?->sourceLocation && $op->sourceLocation->startLine > 0) {
            $line = $op->sourceLocation->startLine;
        }
        $this->context->errors->triggerErrorWithHandlerFirst(
            'foreach() argument must be of type array|object, '
            .VM\EnumCaseSupport::typeNameForTypeErrorActual($resolved).' given',
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $this->context,
            $frame,
            $line
        );
    }

    /**
     * @throws \Error zend_generators.c yield-from container validation (#4909, #5195)
     */
    private function throwYieldFromInvalidContainer(VM\Variable $container): void
    {
        throw new \Error('Can use "yield from" only with arrays and Traversables');
    }

    /**
     * Zend ZEND_YIELD_FROM completion: assign delegated return to the yield-from expression slot.
     */
    private function completeYieldFromDelegation(
        GeneratorState $gen,
        Frame $frame,
        OpCode $op,
        ?Variable $delegatedReturn,
    ): void {
        $gen->yieldFromActive = false;
        $gen->yieldFromIteratorAdvance = false;
        if (null === $op->arg1 || !isset($frame->scope[$op->arg1])) {
            return;
        }
        $slot = (int) $op->arg1;
        $gen->yieldResultSlot = $slot;
        if (null !== $delegatedReturn) {
            $frame->scope[$slot]->copyFrom($delegatedReturn->resolveIndirect());
        } else {
            $frame->scope[$slot]->null();
        }
    }

    private function yieldFromContainerIsTraversable(VM\Variable $container): bool
    {
        $container = $container->resolveIndirect();
        if (Variable::TYPE_ARRAY === $container->type) {
            return true;
        }
        if ($this->variableIsGenerator($container)) {
            return true;
        }
        if (Variable::TYPE_OBJECT !== $container->type) {
            return false;
        }
        $entry = $container->toObject()->class;
        if (VM\InterfaceCheck::entryImplements($entry, 'iteratoraggregate', $this->context)) {
            return true;
        }

        return VM\ForeachIterator::entryImplementsIteratorProtocol($entry, $this->context);
    }

    private function findGeneratorState(Frame $frame): ?GeneratorState
    {
        while (null !== $frame) {
            if (null !== $frame->generatorState) {
                return $frame->generatorState;
            }
            $frame = $frame->parent;
        }

        return null;
    }

    /**
     * Resume a generator (Generator::send / ::next / ::rewind / foreach), optionally injecting a send value.
     */
    public function resumeGenerator(GeneratorState $gen, ?Variable $sendValue = null): bool
    {
        if ($gen->done) {
            return false;
        }
        $sendStartsGenerator = null !== $sendValue && !$gen->started;
        if (null !== $sendValue) {
            $gen->pendingSend->copyFrom($sendValue);
            $gen->hasPendingSend = true;
        }

        $active = $this->advanceGeneratorIteration($gen);
        // Zend: first send() on an unstarted generator opens, then injects+resumes past the
        // first yield (bare `yield`, `$v = yield expr`, and plain `yield expr`) — #18108 / #23712.
        if ($sendStartsGenerator && $active) {
            // Plain `yield expr` has no receive slot: discard the sent value before resuming.
            if ($gen->hasPendingSend && null === $gen->yieldResultSlot) {
                $gen->hasPendingSend = false;
            }
            $active = $this->advanceGeneratorIteration($gen);
        }

        return $active;
    }

    /** Generator::throw() — inject Throwable at yield suspension (Zend zend_generators.c). */
    public function throwGenerator(GeneratorState $gen, Variable $exception): bool
    {
        if ($gen->done) {
            // Zend Generator::throw(): closed generator throws in caller context (#10414).
            $thrown = new Variable();
            $thrown->copyFrom($exception);
            throw new VM\GeneratorUncaughtThrow($thrown);
        }
        $gen->pendingThrow->copyFrom($exception);
        $gen->hasPendingThrow = true;

        return $this->advanceGeneratorIteration($gen);
    }

    /**
     * Close a started generator and run pending finally (Zend zend_generator_dtor_storage, #19905).
     *
     * Called from object release / unset / GC when the Generator instance is destroyed.
     * Foreach `break` alone does not close — only destroying the generator object does.
     *
     * @see https://github.com/php/php-src/blob/master/Zend/zend_generators.c zend_generator_dtor_storage
     */
    public function closeGenerator(GeneratorState $gen): void
    {
        if ($gen->done || $gen->forcedClose) {
            return;
        }
        $gen->forcedClose = true;
        try {
            if ($gen->started && null !== $gen->frame) {
                $this->resumeGeneratorFinallyOnForcedClose($gen);
            }
        } finally {
            if (!$gen->done) {
                $gen->markClosedWithoutReturn();
            }
        }
    }

    /**
     * Jump suspended generator into innermost pending finally and resume (php-src dtor_storage).
     */
    private function resumeGeneratorFinallyOnForcedClose(GeneratorState $gen): void
    {
        $suspended = $gen->frame;
        if (null === $suspended) {
            return;
        }
        if ($this->frameIsInFinallyBody($suspended)) {
            return;
        }

        for ($handler = $suspended; null !== $handler; $handler = $handler->parent) {
            if ($handler->generatorState !== $gen && $this->findGeneratorState($handler) !== $gen) {
                break;
            }
            if (!$this->hasPendingFinally($handler)) {
                continue;
            }
            $finallyOp = $this->findFinallyOpForHandler($handler);
            if (null === $finallyOp || null === $finallyOp->block1) {
                continue;
            }
            if (!$this->generatorSuspendedInsideTryBody($handler, $suspended)) {
                continue;
            }

            $this->context->completedFinallyHandlers[spl_object_id($handler)] = true;
            $finallyFrame = $finallyOp->block1->getFrame($this->context, $handler);
            $finallyFrame->generatorState = $gen;
            $gen->frame = $finallyFrame;
            $gen->clearCurrentValue();

            if ($this->advanceGeneratorIteration($gen)) {
                // Yield during forced-close finally — Zend Error (zend_generators.c).
                throw new \Error(GeneratorState::FORCED_CLOSE_YIELD_ERROR);
            }

            return;
        }
    }

    /** True when the suspended frame is still inside this handler's try body (before finally). */
    private function generatorSuspendedInsideTryBody(Frame $handler, Frame $suspended): bool
    {
        $tryOp = null;
        foreach ($handler->block->opCodes as $op) {
            if (OpCode::TYPE_TRY === $op->type) {
                $tryOp = $op;
                break;
            }
        }
        if (null === $tryOp || null === $tryOp->block1) {
            return false;
        }
        $tryBody = $tryOp->block1;
        if ($suspended->block === $tryBody) {
            return true;
        }

        return VM\GeneratorJitHelper::cfgBlockContains($tryBody, $suspended->block);
    }

    private function applyGeneratorPendingSend(GeneratorState $gen): void
    {
        if (!$gen->hasPendingSend || null === $gen->frame || null === $gen->yieldResultSlot) {
            return;
        }
        if (!isset($gen->frame->scope[$gen->yieldResultSlot])) {
            return;
        }
        $gen->frame->scope[$gen->yieldResultSlot]->copyFrom($gen->pendingSend);
        $gen->hasPendingSend = false;
    }

    private function applyGeneratorPendingThrow(GeneratorState $gen): void
    {
        if (!$gen->hasPendingThrow || null === $gen->frame) {
            return;
        }
        $thrown = new Variable();
        $thrown->copyFrom($gen->pendingThrow);
        $gen->hasPendingThrow = false;
        $frame = $gen->frame;
        $catchFrame = $this->findCatchFrameForGeneratorThrow($gen, $thrown);
        VM\ExceptionTrace::captureGeneratorThrowSite($this->context, $frame, $thrown);
        if (null !== $catchFrame) {
            $catchFrame->generatorState = $gen;
            $gen->frame = $catchFrame;

            return;
        }
        $gen->frame = null;
        $gen->markClosedWithoutReturn();
        throw new VM\GeneratorUncaughtThrow($thrown, $frame);
    }

    /** Catch handlers inside the generator function only (not caller try/catch). */
    private function findCatchFrameForGeneratorThrow(GeneratorState $gen, Variable $thrown): ?Frame
    {
        $this->stashPendingException($thrown);
        $throwFrame = $gen->frame;
        for ($handler = $gen->frame; null !== $handler; $handler = $handler->parent) {
            if ($handler->generatorState !== $gen && $this->findGeneratorState($handler) !== $gen) {
                break;
            }
            $catchFrame = $this->dispatchCatchForHandlerFrame($handler, $throwFrame ?? $handler);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
        }
        $this->clearTryCatchUnwindState();

        return null;
    }

    /** Catch handlers inside the fiber callback only (not caller try/catch) (#19592). */
    private function findCatchFrameForFiberThrow(FiberState $fiber, Variable $thrown): ?Frame
    {
        $this->stashPendingException($thrown);
        $throwFrame = $fiber->frame;
        for ($handler = $fiber->frame; null !== $handler; $handler = $handler->parent) {
            if ($handler->fiberState !== $fiber && $this->findFiberState($handler) !== $fiber) {
                break;
            }
            $catchFrame = $this->dispatchCatchForHandlerFrame($handler, $throwFrame ?? $handler);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
        }
        $this->clearTryCatchUnwindState();

        return null;
    }

    /**
     * Foreach / Iterator valid over a Generator; bridge uncaught generator throws to caller catch (#4338).
     *
     * @return Frame|null catch redirect frame when a handler consumed the throw
     */
    private function foreachAdvanceGenerator(Frame $frame, GeneratorState $gen, int $validSlot): ?Frame
    {
        $gen->foreachAdvance = true;
        try {
            // Zend foreach: rewind may leave the generator on the opening yield; first valid
            // must not advance past it (#23713). Later valids resume (next).
            if ($gen->hasCurrent && !$gen->done && !$gen->foreachNeedsAdvance) {
                $frame->scope[$validSlot]->bool(true);
                $gen->foreachNeedsAdvance = true;

                return null;
            }
            $frame->scope[$validSlot]->bool($this->advanceGeneratorIteration($gen));
            $gen->foreachNeedsAdvance = true;

            return null;
        } catch (VM\GeneratorUncaughtThrow $e) {
            return $this->dispatchUncaughtGeneratorThrow($e->thrown, $frame, null);
        } finally {
            $gen->foreachAdvance = false;
        }
    }

    private function advanceGeneratorIteration(GeneratorState $gen): bool
    {
        if ($gen->done) {
            return false;
        }
        if (null === $gen->frame) {
            $gen->frame = $gen->func->getFrame($this->context, null);
            $gen->frame->calledArgs = $gen->calledArgs;
            $gen->frame->generatorState = $gen;
            $gen->frame->pos = 0;
            if (null !== $gen->closureCall) {
                $this->applyClosureBinding($gen->frame, $gen->closureCall);
            }
            // Instance-method / bound-closure generators need $this in scope (#22067).
            VM\GeneratorTrace::ensureFrameThisBound($gen->frame, $gen);
        }
        // Zend zend_generator_resume clears ZEND_GENERATOR_AT_FIRST_YIELD (#23713).
        $gen->atFirstYield = false;
        $gen->started = true;
        $savedStack = $this->context->swapRunStack(null);
        // Isolate try/catch from the caller so a suspended generator try cannot absorb
        // uncaught exceptions after yield / throw→yield-in-catch (#22869).
        $savedTryHandlers = $this->context->activeTryHandlerFrames;
        $savedTryMergeIds = $this->context->tryMergeBlockIds;
        $this->context->activeTryHandlerFrames = $gen->suspendedTryHandlerFrames;
        $this->context->tryMergeBlockIds = $gen->suspendedTryMergeBlockIds;
        try {
            $this->applyGeneratorPendingSend($gen);
            $this->applyGeneratorPendingThrow($gen);
            $this->context->push($gen->frame);
            try {
                $result = $this->runFrames();
            } catch (\TypeError|\Error $e) {
                $thrown = VM\BuiltinExceptionSupport::materializeNativeError($this->context, $e);
                $frame = $gen->frame;
                VM\ExceptionTrace::captureOnThrow($this->context, $frame, $thrown);
                $generatorThrowTrace = null;
                $thrownObj = $thrown->resolveIndirect();
                if (Variable::TYPE_OBJECT === $thrownObj->type) {
                    $resolvedTrace = VM\ExceptionTrace::resolveTraceVariable($thrownObj->toObject());
                    if (Variable::TYPE_ARRAY === $resolvedTrace->type && $resolvedTrace->toArray()->getNumElements() > 0) {
                        $generatorThrowTrace = new Variable();
                        $generatorThrowTrace->duplicateFrom($resolvedTrace);
                    }
                }
                $catchFrame = $this->findCatchFrameForGeneratorThrow($gen, $thrown);
                if (null !== $catchFrame) {
                    $catchFrame->generatorState = $gen;
                    $gen->frame = $catchFrame;
                    // Keep live try state for the nested advance (still inside this isolation).
                    $gen->suspendedTryHandlerFrames = $this->context->activeTryHandlerFrames;
                    $gen->suspendedTryMergeBlockIds = $this->context->tryMergeBlockIds;

                    return $this->advanceGeneratorIteration($gen);
                }
                if (null !== $generatorThrowTrace) {
                    $thrownObj->toObject()
                        ->getProperty(VM\ExceptionSupport::PROP_TRACE)
                        ->duplicateFrom($generatorThrowTrace);
                } else {
                    VM\ExceptionTrace::captureGeneratorThrowSite($this->context, $frame, $thrown);
                }
                $gen->frame = null;
                $gen->markClosedWithoutReturn();
                throw new VM\GeneratorUncaughtThrow($thrown, $frame);
            }
        } finally {
            // Snapshot only while still suspended; closed/returned generators must not keep
            // try handlers that would leak into the caller on the next throw (#22869).
            if (!$gen->done && null !== $gen->frame) {
                $gen->suspendedTryHandlerFrames = $this->context->activeTryHandlerFrames;
                $gen->suspendedTryMergeBlockIds = $this->context->tryMergeBlockIds;
            } else {
                $gen->clearSuspendedTryState();
            }
            $this->context->activeTryHandlerFrames = $savedTryHandlers;
            $this->context->tryMergeBlockIds = $savedTryMergeIds;
            $this->context->swapRunStack($savedStack);
        }
        if (self::GENERATOR_YIELD === $result) {
            return $gen->hasCurrent;
        }
        $gen->frame = null;
        $gen->clearSuspendedTryState();
        if (self::SUCCESS === $result) {
            if (!$gen->hasReturned) {
                $gen->markReturned(null);
            }
        }

        return false;
    }

    /**
     * Inline `new` or nested FUNCCALL_INIT in a call arg overwrites pending outbound call state (#15217, #17970).
     */
    private function savePendingOutboundCallForInlineNew(Frame $frame): void
    {
        if (null === $frame->call) {
            return;
        }
        $frame->pendingOutboundCallRestore = [
            'call' => $frame->call,
            'callArgs' => $frame->callArgs,
            'callArgEntries' => $frame->callArgEntries,
            'callSiteLine' => $frame->callSiteLine,
            'builtinCalleeQualifiedMethod' => $frame->builtinCalleeQualifiedMethod,
        ];
    }

    private function restorePendingOutboundCallAfterInlineNew(Frame $frame): void
    {
        if (null === $frame->pendingOutboundCallRestore) {
            return;
        }
        $saved = $frame->pendingOutboundCallRestore;
        $frame->call = $saved['call'];
        $frame->callArgs = $saved['callArgs'];
        $frame->callArgEntries = $saved['callArgEntries'];
        $frame->callSiteLine = $saved['callSiteLine'];
        $frame->builtinCalleeQualifiedMethod = $saved['builtinCalleeQualifiedMethod'];
        $frame->pendingOutboundCallRestore = null;
    }

    /**
     * @return list<Variable>
     */
    private function resolveOutgoingCallArgs(Frame $frame): array
    {
        if (null === $frame->call) {
            return $frame->callArgs;
        }

        if (null !== $frame->magicCallMethodName) {
            // Zend: __call/__callStatic receive ($name, $arguments) where $arguments
            // preserves named-arg string keys — do not resolve against __call params (#23336).
            $methodName = $frame->magicCallMethodName;
            $frame->magicCallMethodName = null;
            $nameVar = new Variable(Variable::TYPE_STRING);
            $nameVar->string($methodName);
            $argsVar = VM\MagicCallArgs::packUserArguments($this, $frame);

            $args = array_merge($frame->callArgs, [$nameVar, $argsVar]);
            $this->separateInternalByRefArgsForWrite(
                $frame->call,
                $args,
                $frame->builtinCalleeQualifiedMethod
            );

            return $args;
        }

        [$paramNames, $variadicIndex] = $this->calleeParamMetadata($frame->call, $frame);

        $userArgs = $this->resolveUserCallArgs(
            $frame,
            $paramNames,
            $variadicIndex,
            $this->internalBuiltinFunctionName($frame->call, $frame),
            $frame->call instanceof Func\Internal
        );
        if ([] === $frame->callArgs) {
            $this->separateInternalByRefArgsForWrite(
                $frame->call,
                $userArgs,
                $frame->builtinCalleeQualifiedMethod
            );

            return $userArgs;
        }

        $args = $this->mergeOutgoingCallArgs($frame->callArgs, $userArgs);
        $this->separateInternalByRefArgsForWrite(
            $frame->call,
            $args,
            $frame->builtinCalleeQualifiedMethod
        );

        return $args;
    }

    /**
     * Merge implicit call prefix ($this on new/method calls) with user args without
     * renumbering named-parameter indices (issue #11844, Zend/zend_execute.c).
     *
     * @param list<Variable>        $prefix
     * @param array<int, Variable>  $userArgs
     *
     * @return array<int, Variable>
     */
    private function mergeOutgoingCallArgs(array $prefix, array $userArgs): array
    {
        if ([] === $prefix) {
            return $userArgs;
        }
        if ([] === $userArgs) {
            return $prefix;
        }

        $args = $prefix;
        $offset = \count($prefix);
        foreach ($userArgs as $idx => $value) {
            $args[$offset + (int) $idx] = $value;
        }

        return $args;
    }

    /**
     * COW-separate array zvals passed by reference to internal builtins (Zend zval separation, #6689).
     *
     * @param list<Variable> $calledArgs
     */
    /**
     * @param list<Variable> $calledArgs
     */
    private function separateInternalByRefArgsForWrite(Func $call, array $calledArgs, ?string $qualifiedMethod = null): void
    {
        if (!$call instanceof Func\Internal) {
            return;
        }
        $name = $qualifiedMethod ?? $call->getName();
        foreach (BuiltinByRefParams::forFunction($name) as $idx) {
            if (isset($calledArgs[$idx])) {
                $calledArgs[$idx]->separateArrayForWrite();
            }
        }
        $variadicFrom = BuiltinByRefParams::variadicByRefFromIndex($name);
        if (null === $variadicFrom) {
            return;
        }
        $n = \count($calledArgs);
        for ($i = $variadicFrom; $i < $n; ++$i) {
            if (
                isset($calledArgs[$i])
                && BuiltinByRefParams::isByRefArg($name, $i, $calledArgs[$i])
            ) {
                $calledArgs[$i]->separateArrayForWrite();
            }
        }
    }

    /**
     * Resolve an operand slot — use compile-time constants when scope is unset or clobbered (#5933, #5636).
     */
    private function resolveOutgoingCallArgValue(Frame $frame, int $slot): Variable
    {
        $const = null;
        if (null !== $frame->block && isset($frame->block->constants[$slot])) {
            $const = $frame->block->constants[$slot];
        }
        if (isset($frame->scope[$slot])) {
            // Zend CV init: explicit NULL must not lose to block constants from skipped list dim temps (#10507).
            if (isset($frame->initializedSlots[$slot])) {
                return $frame->scope[$slot];
            }
            // Named locals must stay tied to scope for by-ref outgoing calls (#9505, #9700).
            if (null !== $frame->block && $frame->block->isNamedVariableSlot($slot)) {
                return $frame->scope[$slot];
            }
            $resolved = $frame->scope[$slot]->resolveIndirect();
            if (null !== $const && $this->isImmortalEnumCaseBlockConstant($const)) {
                if (VM\EnumCaseSupport::isEnumCaseVariable($resolved)) {
                    return $frame->scope[$slot];
                }
                // Enum case ->name/->value in call args reuse the case slot; prefer the
                // property-fetch runtime value over immortal enum const (#9684, zend_enum.c).
                if ($resolved->isUndefined() || Variable::TYPE_NULL === $resolved->type) {
                    $value = new Variable();
                    $value->copyFrom($const);

                    return $value;
                }

                return $frame->scope[$slot];
            }
            if (Variable::TYPE_NULL !== $resolved->type && !$resolved->isUndefined()) {
                if (
                    Variable::TYPE_OBJECT === $resolved->type
                    && null !== $const
                    && Variable::TYPE_ARRAY === $const->type
                ) {
                    return $frame->scope[$slot];
                }
                if (null === $const || $resolved->type === $const->type) {
                    return $frame->scope[$slot];
                }
                // Object-cast assign ($a = (object)[...]) keeps array block constants on the CV slot (#15874).
                if (null !== $frame->block) {
                    $operand = $frame->block->operandForScopeSlot($slot);
                    if (null !== $operand && null !== Block::resolveVariableName($operand)) {
                        return $frame->scope[$slot];
                    }
                }
                // Array dim fetch / spread temps hold live objects; do not substitute NULL block constants (#8814).
                if (!$this->isEnumSlotClobberCandidate($resolved)) {
                    return $frame->scope[$slot];
                }
            }
        }
        if (null !== $const) {
            if (null !== $frame->block) {
                $operand = $frame->block->operandForScopeSlot($slot);
                if (null !== $operand && null !== Block::resolveVariableName($operand)) {
                    $resolved = $frame->scope[$slot]->resolveIndirect();
                    if (Variable::TYPE_NULL !== $resolved->type && !$resolved->isUndefined()) {
                        return $frame->scope[$slot];
                    }
                }
            }
            $value = new Variable();
            $value->copyFrom($const);

            return $value;
        }

        return $frame->scope[$slot];
    }

    /**
     * Whether an outgoing call argument binds by reference (Zend ZEND_SEND_REF).
     */
    private function outgoingCallArgNeedsReference(Frame $frame, int $argIndex, ?Variable $value = null): bool
    {
        if (null === $frame->call) {
            return false;
        }
        if ($frame->call instanceof Func\Internal) {
            // Prefer Class::method so instance &$params use the correct index (#5747 Collator::asort).
            $name = $frame->builtinCalleeQualifiedMethod ?? $frame->call->getName();

            return BuiltinByRefParams::isByRefArg($name, $argIndex, $value);
        }
        if ($frame->call instanceof Func\PHP) {
            $block = $frame->call->block;
            if ([] === $block->paramByRef) {
                return false;
            }
            $thisArgOffset = 0;
            if (
                null !== $block->func
                && null !== $block->func->class
                && !(($block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC)
                && !(($block->func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE)
            ) {
                $thisArgOffset = 1;
            }
            $paramIdx = $argIndex - $thisArgOffset;
            if (isset($block->paramByRef[$paramIdx])) {
                if (
                    null !== $block->variadicParamIndex
                    && $paramIdx === $block->variadicParamIndex
                ) {
                    return VM\ReferencableCheck::outgoingUserArgNeedsVariadicByRef(
                        $block,
                        $argIndex,
                        $thisArgOffset,
                        $argIndex + 1
                    );
                }

                return true;
            }

            return VM\ReferencableCheck::outgoingUserArgNeedsVariadicByRef(
                $block,
                $argIndex,
                $thisArgOffset,
                $argIndex + 1
            );
        }

        return false;
    }

    private function isImmortalEnumCaseBlockConstant(Variable $const): bool
    {
        if (Variable::TYPE_ENUM_CASE === $const->type) {
            return true;
        }

        return Variable::TYPE_OBJECT === $const->type
            && VM\EnumCaseSupport::isEnumCaseVariable($const);
    }

    /**
     * Scalar types that may clobber an enum-case scope slot (#5636); not resources/objects/arrays (#6204).
     */
    private function isEnumSlotClobberCandidate(Variable $resolved): bool
    {
        if (VM\ResourceSupport::isVmResource($resolved)) {
            return false;
        }
        if (VM\EnumCaseSupport::isEnumCaseVariable($resolved)) {
            return false;
        }

        return \in_array($resolved->type, [
            Variable::TYPE_NULL,
            Variable::TYPE_BOOLEAN,
            Variable::TYPE_INTEGER,
            Variable::TYPE_FLOAT,
            Variable::TYPE_STRING,
        ], true);
    }

    /**
     * @param list<string> $paramNames
     *
     * @return list<Variable>
     */
    private function resolveUserCallArgs(
        Frame $frame,
        array $paramNames,
        ?int $variadicIndex,
        ?string $functionName = null,
        bool $internalFunction = false
    ): array {
        if ([] === $frame->callArgEntries) {
            return [];
        }

        $entries = [];
        foreach ($frame->callArgEntries as $entry) {
            if ('u' === $entry[0]) {
                foreach (
                    VM\CallUnpack::expandToEntries(
                        $this,
                        $frame,
                        $entry[1],
                        $paramNames,
                        $variadicIndex,
                        $functionName
                    ) as $expanded
                ) {
                    $entries[] = $expanded;
                }
                continue;
            }
            $entries[] = $entry;
        }

        return NamedArgs::resolve($entries, $paramNames, $variadicIndex, $functionName, $internalFunction);
    }

    /**
     * @return array{0: list<string>, 1: ?int}
     */
    private function calleeParamMetadata(Func $call, ?Frame $frame = null): array
    {
        if ($call instanceof Func\PHP) {
            return [$call->block->paramNames, $call->block->variadicParamIndex];
        }
        if ($call instanceof Func\Internal) {
            $qualified = $frame?->builtinCalleeQualifiedMethod;
            if (null !== $qualified) {
                // Explicit BuiltinParamNames table, then php-types InternalArgInfo (#25182).
                // Bare getName() ("saveXML") has no param table and rejects Zend named args.
                $names = BuiltinParamNames::paramNamesForInternalFunction($qualified);
                if (null !== $names) {
                    return [
                        $names,
                        BuiltinParamNames::variadicParamIndexForFunction(strtolower($qualified)),
                    ];
                }
            }
            $name = $call->getName();

            return [
                BuiltinParamNames::paramNamesForInternalFunction($name) ?? [],
                BuiltinParamNames::variadicParamIndexForFunction($name),
            ];
        }

        return [[], null];
    }

    private function internalBuiltinFunctionName(Func $call, ?Frame $frame = null): ?string
    {
        if (!$call instanceof Func\Internal) {
            return null;
        }
        if (null !== $frame?->builtinCalleeQualifiedMethod) {
            return $frame->builtinCalleeQualifiedMethod;
        }

        return $call->getName();
    }

    protected function scopeSlot(Frame $frame, int $slot): Variable
    {
        if (!isset($frame->scope[$slot])) {
            $frame->scope[$slot] = new Variable();
        }

        return $frame->scope[$slot];
    }

    /**
     * @param list<array{name: string, slot: int, byRef: bool}> $captureSpecs
     *
     * @return list<array{slot: int, var: Variable, byRef: bool}>
     */
    protected function bindClosureCaptures(Frame $frame, array $captureSpecs): array
    {
        $captures = [];
        foreach ($captureSpecs as $spec) {
            $src = $this->resolveClosureCaptureSource($spec['name'], $frame);
            $stored = new Variable();
            if (null === $src) {
                $stored->null();
            } elseif ($spec['byRef']) {
                $stored->indirect($src->byRefTarget());
            } else {
                $stored->copyFrom($src->resolveIndirect());
            }
            $captures[] = [
                'slot' => $spec['slot'],
                'var' => $stored,
                'byRef' => $spec['byRef'],
            ];
        }

        return $captures;
    }

    /**
     * Parent CV for closure `use` — include declared-but-unassigned slots for self-referential
     * `use (&$fn)` on `$fn = function () use (&$fn)` (Zend/zend_closures.c, #17089).
     */
    protected function resolveClosureCaptureSource(string $name, Frame $frame): ?Variable
    {
        $src = Block::findVariableInParentFramesByName($name, $frame);
        if (null !== $src) {
            return $src;
        }
        $blockScriptGlobals = null !== $frame->block && $frame->block->blocksScriptGlobalInheritance();
        for ($f = $frame; null !== $f; $f = $f->parent) {
            if (
                $blockScriptGlobals
                && null !== $f->block
                && $f->block->isMainScript()
            ) {
                break;
            }
            if (null === $f->block) {
                continue;
            }
            $idx = $f->block->slotIndexForVariableName($name);
            if (null === $idx) {
                continue;
            }
            if (!isset($f->scope[$idx])) {
                $f->scope[$idx] = new Variable();
            }

            return $f->scope[$idx];
        }

        return null;
    }

    /** True when $slot is a by-ref closure `use` capture in this frame (#17089). */
    private function frameScopeSlotIsClosureByRefCapture(Frame $frame, int $slot): bool
    {
        if (isset($frame->block->closureCaptureByRef[$slot])) {
            return true;
        }
        $state = $frame->closureCall;
        if (null === $state) {
            return false;
        }
        foreach ($state->captures as $capture) {
            if ($capture['byRef'] && (int) $capture['slot'] === $slot) {
                return true;
            }
        }

        return false;
    }

    protected function resolvePendingClosureState(Frame $frame): ?ClosureState
    {
        if (null !== $frame->pendingClosureInvoke) {
            return $frame->pendingClosureInvoke;
        }
        if (null !== $frame->closureCall) {
            return $frame->closureCall;
        }
        if (null !== $frame->closureCallableSlot && isset($frame->scope[$frame->closureCallableSlot])) {
            $callable = $frame->scope[$frame->closureCallableSlot]->resolveIndirect();
            if (Variable::TYPE_OBJECT === $callable->type) {
                return $callable->toObject()->closureState;
            }
        }

        return null;
    }

    protected function frameUsesClosureStaticStorage(Frame $frame): bool
    {
        if (null === $frame->closureCall) {
            return false;
        }
        $func = $frame->block->func ?? null;
        if (null === $func) {
            return false;
        }

        // Per-closure statics only inside closure bodies; nested user-function calls from
        // a closure must not inherit the caller's ClosureState (#11451, Zend/zend_execute.c).
        return (($func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE) !== 0;
    }

    /**
     * Enclosing-function statics use {@see Context} keys {@code func\0var}; closure-body
     * statics use bare names (Zend/zend_closures.c static_variables, issue #4872).
     * Captured parent statics via {@code use (&$n)} keep the context key (#14077).
     */
    protected function functionStaticUsesContextStorage(string $storageKey): bool
    {
        return str_contains($storageKey, "\0");
    }

    protected function ensureFunctionStaticForFrame(Frame $frame, string $storageKey): Variable
    {
        if (
            $this->frameUsesClosureStaticStorage($frame)
            && !$this->functionStaticUsesContextStorage($storageKey)
        ) {
            return $frame->closureCall->ensureStatic($storageKey);
        }

        return $this->context->ensureFunctionStatic($storageKey);
    }

    protected function isFunctionStaticInitializedForFrame(Frame $frame, string $storageKey): bool
    {
        if (
            $this->frameUsesClosureStaticStorage($frame)
            && !$this->functionStaticUsesContextStorage($storageKey)
        ) {
            return $frame->closureCall->isStaticInitialized($storageKey);
        }

        return $this->context->isFunctionStaticInitialized($storageKey);
    }

    protected function markFunctionStaticInitializedForFrame(Frame $frame, string $storageKey): void
    {
        if (
            $this->frameUsesClosureStaticStorage($frame)
            && !$this->functionStaticUsesContextStorage($storageKey)
        ) {
            $frame->closureCall->markStaticInitialized($storageKey);

            return;
        }
        $this->context->markFunctionStaticInitialized($storageKey);
    }

    protected function applyFunctionStaticTypeMetadata(Variable $storage, Frame $frame, OpCode $op): void
    {
        $resolved = $storage->resolveIndirect();
        // Always mark storage (typed or not) so frame teardown skips releaseRef (#28039).
        $resolved->functionStaticStorage = true;
        if (null !== $op->functionStaticVarName && '' !== $op->functionStaticVarName) {
            $resolved->functionStaticVarName = $op->functionStaticVarName;
        }
        if (null === $op->functionStaticTypeSlot || !isset($frame->block->constants[$op->functionStaticTypeSlot])) {
            return;
        }
        $proto = $frame->block->constants[$op->functionStaticTypeSlot];
        $resolved->typeConstraint = $proto->typeConstraint;
        $resolved->classConstraint = $proto->classConstraint;
        $resolved->literalBoolType = $proto->literalBoolType;
        $resolved->unionTypeConstraints = $proto->unionTypeConstraints;
        $resolved->declaredTypeLabel = $proto->declaredTypeLabel;
        $resolved->genericArrayTypeSpec = $proto->genericArrayTypeSpec;
        $resolved->dnfArms = $proto->dnfArms;
    }

    protected function enforceFunctionStaticWrite(
        Variable $storage,
        Frame $frame,
        ?string $varName
    ): ?Frame {
        if (null === $storage->resolveIndirect()->typeConstraint && null === $storage->resolveIndirect()->dnfArms) {
            return null;
        }
        if (null !== $varName && '' !== $varName) {
            $storage->resolveIndirect()->functionStaticVarName = $varName;
        }
        $strict = null !== $frame->parent
            ? $frame->parent->block->strictTypes
            : $frame->block->strictTypes;
        $probe = new Variable();
        $probe->indirect($storage);
        try {
            TypeCheck::coerceFunctionStaticWrite($probe, $strict);
        } catch (\TypeError $e) {
            return $this->dispatchVmTypeError($e, $frame);
        }

        return null;
    }

    protected function bindClosureCallCaptures(Frame $callee, ?ClosureState $closureState): void
    {
        if (null === $closureState || [] === $closureState->captures) {
            return;
        }
        foreach ($closureState->captures as $capture) {
            $slot = (int) $capture['slot'];
            $dest = $this->scopeSlot($callee, $slot);
            if ($capture['byRef']) {
                $dest->indirect($capture['var']->byRefTarget());
            } else {
                $dest->copyFrom($capture['var']);
            }
            // Captured CVs are bound at closure entry — not undefined locals (#10304, #10358).
            $this->markScopeSlotInitialized($callee, $slot);
        }
    }

    protected function initClosureCall(Frame $frame, ClosureState $state): void
    {
        if (null !== $state->methodName && null !== $state->methodReceiver) {
            $calledScope = $this->closureCalledScopeClass($state);
            if (null !== $calledScope && '' !== $calledScope) {
                $frame->calledClass = $calledScope;
            }
            $this->initMethodCall($frame, $state->methodReceiver, $state->methodName);
            $frame->closureCall = null;

            return;
        }
        // Static magic fake closure: methodName + __callStatic, no receiver (#25757).
        if (
            null !== $state->methodName
            && '' !== $state->methodName
            && null !== $state->wrappedFunc
            && null === $state->methodReceiver
        ) {
            $calledScope = $this->closureCalledScopeClass($state);
            if (null !== $calledScope && '' !== $calledScope) {
                $frame->calledClass = $calledScope;
            }
            $frame->magicCallMethodName = $state->methodName;
            $frame->call = $state->wrappedFunc;
            $frame->closureCall = null;
            $frame->callArgs = [];
            $frame->callArgEntries = [];
            $frame->builtinCalleeQualifiedMethod = null;

            return;
        }
        if (null !== $state->wrappedFunc) {
            $calledScope = $this->closureCalledScopeClass($state);
            if (null !== $calledScope && '' !== $calledScope) {
                $frame->calledClass = $calledScope;
            }
            $frame->call = $state->wrappedFunc;
            $frame->closureCall = null;
            // Scoped parent/self FCC (#17655/#26630) and fromCallable instance wrappers clear
            // methodReceiver and call wrappedFunc directly. Instance methods still need $this
            // as callArgs[0] so user args land at ARG_RECV indices 1..n (#27834).
            $frame->callArgs = $this->wrappedFuncInstanceThisPrefix($state);
            $frame->callArgEntries = [];
            $frame->builtinCalleeQualifiedMethod = null;

            return;
        }
        $frame->call = $state->func;
        if (
            null === $frame->closureCall
            || null === $frame->block?->func
            || (($frame->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_CLOSURE) === 0
        ) {
            $frame->closureCall = $state;
        }
        $frame->pendingClosureInvoke = $state;
        $frame->callArgs = [];
        $frame->callArgEntries = [];
        $frame->builtinCalleeQualifiedMethod = null;
    }

    /**
     * $this prefix for wrappedFunc instance-method FCC / fromCallable (#27834).
     *
     * @return list<Variable>
     */
    private function wrappedFuncInstanceThisPrefix(ClosureState $state): array
    {
        if (null === $state->boundThis || null === $state->wrappedFunc) {
            return [];
        }
        if ($this->methodIsStatic($state->wrappedFunc)) {
            return [];
        }
        $wrapped = $state->wrappedFunc;
        if ($wrapped instanceof Func\PHP) {
            $decl = $wrapped->block->func ?? null;
            if (null === $decl || null === $decl->class) {
                return [];
            }
        }

        return [$state->boundThis];
    }

    protected function applyClosureBinding(Frame $callee, ?ClosureState $closureState): void
    {
        $this->bindClosureCallCaptures($callee, $closureState);
        if (null === $closureState) {
            return;
        }
        $callee->closureCall = $closureState;
        if (null !== $closureState->boundThis) {
            $thisIdx = $closureState->func->block->slotIndexForVariableName('this');
            if (null !== $thisIdx) {
                if (!isset($callee->scope[$thisIdx])) {
                    $callee->scope[$thisIdx] = new Variable();
                }
                $boundThis = $closureState->boundThis;
                if (EnumCaseSupport::isEnumCaseVariable($boundThis)) {
                    $boundThis = EnumCaseSupport::materializeConstantValue($this->context, $boundThis);
                }
                $callee->scope[$thisIdx]->copyFrom($boundThis);
            }
        }
        $calledScope = $this->closureCalledScopeClass($closureState);
        if (null !== $calledScope && '' !== $calledScope) {
            $callee->calledClass = $calledScope;
        }
    }

    protected function resolveStaticClassName(string $className, Frame $frame): string
    {
        return $this->resolveClassScopeName($className, $frame);
    }

    /**
     * Resolve the class for $operand::$prop when the left side is a class name or instance (#5477).
     */
    protected function resolveStaticPropertyClassLc(Variable $classOperand, Frame $frame): string
    {
        $classOperand = $classOperand->resolveIndirect();
        if (Variable::TYPE_OBJECT === $classOperand->type) {
            return strtolower($classOperand->toObject()->class->name);
        }

        return $this->resolveStaticClassName($classOperand->toString(), $frame);
    }

    /**
     * Static property storage for $class::$prop, walking ancestors (Zend inheritance; #4668).
     */
    protected function resolveStaticPropertyStorage(string $classLc, string $propLc): ?Variable
    {
        $currentLc = $classLc;
        while (isset($this->context->classes[$currentLc])) {
            $entry = $this->context->classes[$currentLc];
            if (isset($entry->staticProperties[$propLc])) {
                return $entry->staticProperties[$propLc];
            }
            if (null === $entry->parentLc) {
                break;
            }
            $currentLc = $entry->parentLc;
        }

        return null;
    }

    /** True when $cell is a class static property slot (not a frame local). */
    private function isStaticPropertyStorageCell(Variable $cell): bool
    {
        foreach ($this->context->classes as $entry) {
            foreach ($entry->staticProperties as $storage) {
                if ($storage === $cell) {
                    return true;
                }
            }
        }

        return false;
    }

    protected function resolveClassScopeName(string $className, Frame $frame): string
    {
        $lcClass = strtolower($className);
        if ('self' === $lcClass) {
            return $this->declaringClassLc($frame, 'self');
        }
        if ('static' === $lcClass) {
            return $this->lateStaticClassLc($frame);
        }
        if ('parent' === $lcClass) {
            $declaring = $this->declaringClassLc($frame, 'parent');
            if (!isset($this->context->classes[$declaring])) {
                PseudoClassScope::fatalNoActiveClassScope('parent');
            }
            $parentLc = $this->context->classes[$declaring]->parentLc;
            if (null === $parentLc) {
                throw new \LogicException('parent:: used when class has no parent');
            }

            return $parentLc;
        }

        return $lcClass;
    }

    protected function declaringClassLc(Frame $frame, string $scopeKeyword = 'self'): string
    {
        $boundScope = $this->boundClosureScopeClassLc($frame);
        if (null !== $boundScope) {
            return $boundScope;
        }
        if (null !== $frame->block->func && null !== $frame->block->func->class) {
            $funcClassValue = $frame->block->func->class->value;
            $funcClassLc = strtolower($funcClassValue);
            if ('parent' === $scopeKeyword || 'self' === $scopeKeyword) {
                $funcIsTrait = ($this->context->classes[$funcClassLc] ?? null)?->isTrait ?? false;
                if ($funcIsTrait) {
                    return VM\TraitSelfClassScope::resolveComposingClassLc(
                        $funcClassValue,
                        true,
                        $frame->calledClass,
                        $funcClassLc,
                        strtolower($frame->block->func->name),
                        fn (string $classLc, string $method): ?string => $this->context->classes[$classLc]->traitMethodSources[$method] ?? null,
                        fn (string $classLc): ?string => $this->context->classes[$classLc]->parentLc ?? null,
                        fn (string $classLc): bool => ($this->context->classes[$classLc] ?? null)?->isTrait ?? false,
                    );
                }
            }

            return $funcClassLc;
        }
        // Bound closure scope (Closure::bind/bindTo $newScope) — #3673.
        if (null !== $frame->calledClass && '' !== $frame->calledClass) {
            return strtolower($frame->calledClass);
        }

        PseudoClassScope::fatalNoActiveClassScope($scopeKeyword);
    }

    protected function lateStaticClassLc(Frame $frame): string
    {
        return VM\LateStaticBinding::resolveLateStaticClassLc(
            $frame->calledClass,
            $this->declaringClassLc($frame, 'static')
        );
    }

    protected function inferCalledClass(Frame $frame): ?string
    {
        if (null !== $frame->staticCallClass) {
            $called = $frame->staticCallClass;
            $frame->staticCallClass = null;

            return $called;
        }
        if (!empty($frame->callArgs)) {
            $receiver = $frame->callArgs[0]->resolveIndirect();
            if (Variable::TYPE_OBJECT === $receiver->type) {
                return $receiver->toObject()->class->name;
            }
        }

        return $frame->calledClass;
    }

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
        bool $resolveScopeKeywords = true
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
            if (!$this->instanceThisAllowsNonStaticCall($frame, $lcClass)) {
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

    protected function resolveTraitEntry(string $traitName): ClassEntry
    {
        $traitLc = strtolower(ltrim($traitName, '\\'));
        if (!isset($this->context->classes[$traitLc])) {
            $this->context->autoloadClass($traitName);
        }
        if (!isset($this->context->classes[$traitLc])) {
            throw new \LogicException("Trait {$traitName} not found");
        }
        $trait = $this->context->classes[$traitLc];
        if (!$trait->isTrait) {
            throw new \LogicException("{$traitName} is not a trait");
        }

        return $trait;
    }

    /**
     * Methods that block trait import — composing-class own methods only.
     *
     * Zend precedence (zend_traits.c): trait methods override inherited parent
     * methods of the same name; only methods declared on the using class win
     * over the trait (#19630, re-#18878). Parent methods are merged later via
     * inheritFromParent() and skip slots already filled by the trait.
     *
     * @param array<string, true> $ownMethods
     *
     * @return array<string, true>
     */
    protected function traitMethodExclusions(ClassEntry $entry, array $ownMethods): array
    {
        return $ownMethods;
    }

    protected function applyTraitUse(ClassEntry $entry, string $traitName, array $ownMethods = [], ?Frame $warningFrame = null): void
    {
        $this->applyTraitUsesWithAdaptations($entry, [$traitName], [], $ownMethods, $warningFrame);
    }

    /**
     * @param list<string> $traitNames
     */
    protected function canResolveAllTraitEntries(array $traitNames): bool
    {
        foreach ($traitNames as $traitName) {
            $traitLc = strtolower(ltrim($traitName, '\\'));
            if (!isset($this->context->classes[$traitLc])) {
                $this->context->autoloadClass($traitName);
            }
            if (!isset($this->context->classes[$traitLc])) {
                return false;
            }
            if (!$this->context->classes[$traitLc]->isTrait) {
                throw new \LogicException("{$traitName} is not a trait");
            }
        }

        return true;
    }

    /**
     * @param list<string> $traitNames
     * @param list<array<string, mixed>> $adaptations
     * @param array<string, true> $ownMethods
     */
    protected function queueDeferredTraitUse(
        ClassEntry $entry,
        array $traitNames,
        array $adaptations,
        array $ownMethods,
        ?Frame $warningFrame = null
    ): void {
        $this->context->deferredTraitUses[] = [
            'entry' => $entry,
            'traitNames' => $traitNames,
            'adaptations' => $adaptations,
            'ownMethods' => $ownMethods,
            'warningFrame' => $warningFrame,
        ];
    }

    protected function flushDeferredTraitUses(?Frame $warningFrame = null): void
    {
        if ([] === $this->context->deferredTraitUses) {
            return;
        }
        $remaining = [];
        foreach ($this->context->deferredTraitUses as $deferred) {
            if (!$this->canResolveAllTraitEntries($deferred['traitNames'])) {
                $remaining[] = $deferred;

                continue;
            }
            $this->applyTraitUsesWithAdaptations(
                $deferred['entry'],
                $deferred['traitNames'],
                $deferred['adaptations'],
                $deferred['ownMethods'],
                $deferred['warningFrame'] ?? $warningFrame
            );
        }
        $this->context->deferredTraitUses = $remaining;
    }

    protected function flushDeferredParentInheritance(?Frame $frame = null): void
    {
        if ([] === $this->context->deferredParentInheritance) {
            return;
        }
        $remaining = [];
        foreach ($this->context->deferredParentInheritance as $deferred) {
            $childLc = $deferred['childLc'];
            if (!isset($this->context->classes[$childLc])) {
                $remaining[] = $deferred;

                continue;
            }
            $entry = $this->context->classes[$childLc];
            if (null === $entry->parentLc || !isset($this->context->classes[$entry->parentLc])) {
                $remaining[] = $deferred;

                continue;
            }
            $this->assertAllowedBySealedParents($entry->name, $entry->parentLc, $entry->interfaces);
            $this->inheritFromParent($entry);
            $this->linkStaticPropertyHooks($entry);
            VM\ClassValidator::finalizeClassDefinition($entry, $this->context, $frame);
        }
        $this->context->deferredParentInheritance = $remaining;
    }

    protected function finalizeDeferredParentInheritance(?Frame $frame = null): void
    {
        $this->flushDeferredParentInheritance($frame);
        if ([] === $this->context->deferredParentInheritance) {
            return;
        }
        $deferred = $this->context->deferredParentInheritance[0];
        $parentName = $deferred['parentName'];
        // Zend does not leave the child class defined when the parent is missing (#25627).
        // Do not re-invoke autoload here: TYPE_DECLARE_CLASS already tried, and a nested
        // autoload frame would re-enter finalize at SUCCESS and recurse (#25627).
        unset($this->context->classes[$deferred['childLc']]);
        $this->context->deferredParentInheritance = [];
        throw new \Error($this->classNotFoundMessage($parentName));
    }

    protected function finalizeDeferredTraitUses(): void
    {
        $this->flushDeferredTraitUses();
        if ([] === $this->context->deferredTraitUses) {
            return;
        }
        $missing = $this->context->deferredTraitUses[0]['traitNames'][0] ?? 'unknown';

        throw new \LogicException("Trait {$missing} not found");
    }

    protected function flushDeferredClassConstants(): void
    {
        if ([] === $this->context->deferredClassConstants) {
            return;
        }
        $remaining = [];
        foreach ($this->context->deferredClassConstants as $deferred) {
            $stillPending = $this->finalizeDeferredClassConstants(
                $deferred['entry'],
                $deferred['block'],
                $deferred['frame'],
                $deferred['classBodyOps'],
                $deferred['segments']
            );
            if ([] !== $stillPending) {
                $deferred['segments'] = $stillPending;
                $remaining[] = $deferred;
            }
        }
        $this->context->deferredClassConstants = $remaining;
    }

    protected function finalizeAllDeferredClassConstants(): void
    {
        $this->flushDeferredClassConstants();
        if ([] === $this->context->deferredClassConstants) {
            return;
        }
        $first = $this->context->deferredClassConstants[0];
        $pendingName = array_key_first($first['segments']);
        if (false === $pendingName) {
            return;
        }
        $declareOp = $first['classBodyOps'][$first['segments'][$pendingName]['declareIndex']];
        $canonical = $first['frame']->scope[$declareOp->arg1]->toString();
        throw new \LogicException(
            "Cannot resolve class constant {$first['entry']->name}::{$canonical}"
        );
    }

    private function assertDeferredDefinitionsBeforeRuntime(int $opType): void
    {
        static $declarationOpcodes = [
            OpCode::TYPE_DECLARE_CLASS => true,
            OpCode::TYPE_DECLARE_ENUM => true,
            OpCode::TYPE_DECLARE_TRAIT => true,
            OpCode::TYPE_DECLARE_INTERFACE => true,
            OpCode::TYPE_FUNCDEF => true,
            OpCode::TYPE_DECLARE_GLOBAL_CONST => true,
        ];
        if (!isset($declarationOpcodes[$opType])) {
            $this->finalizeDeferredTraitUses();
            // Forward-ref class constants (e.g. C::ITEM = E::A before enum E) may stay
            // pending until a later declaration opcode flushes them (#9664, #15737).
            $this->flushDeferredClassConstants();
            $this->finalizeDeferredParentInheritance();
        }
    }

    /**
     * @param list<string> $traitNames
     * @param list<array<string, mixed>> $adaptations
     * @param array<string, true> $ownMethods
     */
    protected function applyTraitUsesWithAdaptations(
        ClassEntry $entry,
        array $traitNames,
        array $adaptations,
        array $ownMethods = [],
        ?Frame $warningFrame = null
    ): void {
        if ([] === $traitNames) {
            return;
        }

        if (!$this->canResolveAllTraitEntries($traitNames)) {
            $this->queueDeferredTraitUse($entry, $traitNames, $adaptations, $ownMethods, $warningFrame);

            return;
        }

        $dedupedTraitNames = [];
        $seenTraitLc = [];
        foreach ($traitNames as $traitName) {
            $traitLc = strtolower(ltrim($traitName, '\\'));
            if (isset($seenTraitLc[$traitLc])) {
                continue;
            }
            $seenTraitLc[$traitLc] = true;
            $dedupedTraitNames[] = $traitName;
        }
        $traitNames = $dedupedTraitNames;
        if ([] === $traitNames) {
            return;
        }

        $excludedMethods = $this->traitMethodExclusions($entry, $ownMethods);

        /** @var array<string, array<string, array{method: Func, vis: int, traitName: string, methodNames: string, attrs: ?list<string>, deprecated: mixed, attributeEntries: mixed, parameterMetadata: mixed}>> */
        $perTraitMethods = [];
        /** @var array<string, true> */
        $excludedByPrecedence = [];
        /** @var array<string, string> */
        $usedTraitNameByLc = [];

        foreach ($traitNames as $traitName) {
            $trait = $this->resolveTraitEntry($traitName);
            $traitLc = strtolower(ltrim($trait->name, '\\'));
            if (VM\LazyGhostTraitSupport::isLazyGhostTrait($traitLc)) {
                $entry->usesLazyGhostTrait = true;
            }
            $this->emitTraitUseDeprecation($trait, $entry, $warningFrame);
            $entry->usedTraits[$trait->name] = $trait->name;
            $usedTraitNameByLc[$traitLc] = $trait->name;
            if (!isset($perTraitMethods[$traitLc])) {
                $perTraitMethods[$traitLc] = [];
            }
            foreach ($trait->methods as $name => $method) {
                $perTraitMethods[$traitLc][$name] = [
                    'method' => $method,
                    'vis' => $trait->methodVisibility[$name] ?? \PHPCfg\Func::FLAG_PUBLIC,
                    'traitName' => $trait->name,
                    'methodNames' => $trait->methodNames[$name] ?? $name,
                    'attrs' => $trait->methodAttributeNames[$name] ?? null,
                    'deprecated' => $trait->methodDeprecated[$name] ?? null,
                    'attributeEntries' => $trait->methodAttributeEntries[$name] ?? null,
                    'parameterMetadata' => $trait->methodParameterMetadata[$name] ?? null,
                    'sourceLocation' => $trait->methodSourceLocations[$name] ?? null,
                ];
            }
            foreach ($trait->abstractMethods as $name => $_) {
                if (!isset($entry->methods[$name]) && !isset($entry->abstractMethods[$name])) {
                    $entry->abstractMethods[$name] = true;
                }
            }
            foreach ($trait->staticProperties as $name => $storage) {
                if (isset($entry->staticProperties[$name])) {
                    $declaringLc = $entry->staticPropertyDeclaringClassLc[$name] ?? null;
                    if ($declaringLc === $traitLc) {
                        continue;
                    }
                    $existing = $entry->staticProperties[$name];
                    if ($this->traitStaticPropertiesCompatible($entry, $name, $existing, $trait, $storage)) {
                        // Zend: identical definitions merge; keep the earlier property (#22850).
                        continue;
                    }
                    $prevTrait = $usedTraitNameByLc[$declaringLc]
                        ?? $this->context->classes[$declaringLc]->name
                        ?? $declaringLc;
                    $this->throwTraitPropertyCompositionFatal(
                        TraitCompositionConflictMessage::incompatibleProperty(
                            $prevTrait,
                            $trait->name,
                            $name,
                            $entry->name
                        ),
                        $entry
                    );
                }
                $entry->staticProperties[$name] = $this->cloneStaticPropertyStorage($storage);
                $this->linkStaticTypedPropertySlot(
                    $entry->staticProperties[$name],
                    $entry,
                    $storage->objectPropertyName ?? $name
                );
                $entry->traitStaticPropertyNames[$name] = true;
                $entry->staticPropertyVisibility[$name] = $trait->staticPropertyVisibility[$name]
                    ?? \PHPCfg\Func::FLAG_PUBLIC;
                $entry->staticPropertySetVisibility[$name] = $trait->staticPropertySetVisibility[$name] ?? 0;
                $entry->staticPropertyGetVisibility[$name] = $trait->staticPropertyGetVisibility[$name] ?? 0;
                if (isset($trait->staticPropertyAsymmetricExplicitRead[$name])) {
                    $entry->staticPropertyAsymmetricExplicitRead[$name] = $trait->staticPropertyAsymmetricExplicitRead[$name];
                }
                $entry->staticPropertyDeclaringClassLc[$name] = $trait->staticPropertyDeclaringClassLc[$name]
                    ?? $traitLc;
                if (isset($trait->staticPropertyFinal[$name])) {
                    $entry->staticPropertyFinal[$name] = true;
                }
            }
            $this->inheritTraitStaticPropertyHooks($entry, $trait);
            $this->inheritTraitInstanceProperties($entry, $trait, $trait->name);
            foreach ($trait->constants as $name => $value) {
                if (isset($entry->constants[$name])) {
                    if ($this->classConstValuesIdentical($entry->constants[$name], $value)) {
                        continue;
                    }
                    $prevTrait = $entry->traitConstSources[$name] ?? $entry->name;
                    $constDisplay = $entry->constNames[$name]
                        ?? $trait->constNames[$name]
                        ?? $name;
                    throw new \LogicException(sprintf(
                        '%s and %s define the same constant (%s) in the composition of %s. '
                        .'However, the definition differs and is considered incompatible. Class was composed',
                        $prevTrait,
                        $trait->name,
                        $constDisplay,
                        $entry->name
                    ));
                }
                $entry->constants[$name] = $value;
                $entry->traitConstSources[$name] = $trait->name;
                if (isset($trait->constNames[$name])) {
                    $entry->constNames[$name] = $trait->constNames[$name];
                }
                $entry->constDeclaringClassLc[$name] = $trait->constDeclaringClassLc[$name]
                    ?? strtolower(ltrim($trait->name, '\\'));
                if (isset($trait->constVisibility[$name])) {
                    $entry->constVisibility[$name] = $trait->constVisibility[$name];
                }
                if (isset($trait->constDeprecated[$name])) {
                    $entry->constDeprecated[$name] = $trait->constDeprecated[$name];
                }
                if (isset($trait->constFinal[$name])) {
                    $entry->constFinal[$name] = true;
                }
                if (isset($trait->constDeclaredTypes[$name])) {
                    $entry->constDeclaredTypes[$name] = $trait->constDeclaredTypes[$name];
                }
                if (isset($trait->constSourceLocations[$name])) {
                    $entry->constSourceLocations[$name] = $trait->constSourceLocations[$name];
                }
            }
        }

        foreach ($adaptations as $adaptation) {
            if ('precedence' !== ($adaptation['kind'] ?? '')) {
                continue;
            }
            $winnerTraitLc = strtolower(ltrim((string) ($adaptation['trait'] ?? ''), '\\'));
            if ('' === $winnerTraitLc) {
                throw new \LogicException('Trait precedence adaptation must specify a trait');
            }
            if (!isset($usedTraitNameByLc[$winnerTraitLc])) {
                // Zend: "Could not find trait X" (even though this name is in an insteadof list).
                throw new \LogicException('Could not find trait ' . (string) ($adaptation['trait'] ?? ''));
            }
            $methodLc = strtolower((string) $adaptation['method']);
            if (!isset($perTraitMethods[$winnerTraitLc][$methodLc])) {
                throw new \LogicException(
                    'A precedence rule was defined for '
                    . $usedTraitNameByLc[$winnerTraitLc]
                    . '::' . (string) ($adaptation['method'] ?? '')
                    . ' but this method does not exist'
                );
            }
            foreach ($adaptation['insteadof'] as $loserTrait) {
                $loserLc = strtolower(ltrim((string) $loserTrait, '\\'));
                if (!isset($usedTraitNameByLc[$loserLc])) {
                    throw new \LogicException('Could not find trait ' . (string) $loserTrait);
                }
                if (!isset($perTraitMethods[$loserLc][$methodLc])) {
                    throw new \LogicException(
                        'A precedence rule was defined for '
                        . $usedTraitNameByLc[$winnerTraitLc]
                        . '::' . (string) ($adaptation['method'] ?? '')
                        . ' but this method does not exist in '
                        . $usedTraitNameByLc[$loserLc]
                    );
                }
                $excludedByPrecedence["{$loserLc}\0{$methodLc}"] = true;
            }
        }

        /** @var array<string, array{traitLc: string, method: Func, vis: int, traitName: string, methodNames: string, attrs: ?list<string>, deprecated: mixed, attributeEntries: mixed, parameterMetadata: mixed}> */
        $merged = [];
        foreach ($perTraitMethods as $traitLc => $methods) {
            foreach ($methods as $methodLc => $data) {
                if (isset($excludedByPrecedence["{$traitLc}\0{$methodLc}"])) {
                    continue;
                }
                if (isset($excludedMethods[$methodLc])) {
                    continue;
                }
                if (isset($merged[$methodLc])) {
                    if ($merged[$methodLc]['traitLc'] === $traitLc) {
                        continue;
                    }
                    $prevTrait = $merged[$methodLc]['traitName'];
                    throw new \LogicException(
                        "Trait method {$data['traitName']}::{$methodLc} has not been applied as {$entry->name}::{$methodLc}, "
                        ."because of collision with {$prevTrait}::{$methodLc}"
                    );
                }
                $merged[$methodLc] = [
                    'traitLc' => $traitLc,
                    'method' => $data['method'],
                    'vis' => $data['vis'],
                    'traitName' => $data['traitName'],
                    'methodNames' => $data['methodNames'],
                    'attrs' => $data['attrs'],
                    'deprecated' => $data['deprecated'],
                    'attributeEntries' => $data['attributeEntries'],
                    'parameterMetadata' => $data['parameterMetadata'],
                    'sourceLocation' => $data['sourceLocation'] ?? null,
                ];
            }
        }

        foreach ($adaptations as $adaptation) {
            if ('alias' !== ($adaptation['kind'] ?? '')) {
                continue;
            }
            $methodLc = strtolower((string) $adaptation['method']);
            $traitLcFilter = null !== ($adaptation['trait'] ?? null)
                ? strtolower(ltrim((string) $adaptation['trait'], '\\'))
                : null;
            $newName = $adaptation['newName'] ?? null;
            $newModifier = $adaptation['newModifier'] ?? null;
            if (null === $newName && null === $newModifier) {
                continue;
            }

            $traitPrefix = null !== ($adaptation['trait'] ?? null)
                ? (string) $adaptation['trait'] . '::'
                : '';

            if (null === $newName) {
                if (!isset($merged[$methodLc])) {
                    // Own class methods are excluded from $merged before adaptations so they
                    // can suppress trait collisions (Zend). Visibility-only `g as private`
                    // must still succeed when the class also declares g() — the trait method
                    // exists in $perTraitMethods; class method wins on install (#25577).
                    $existsInTraits = false;
                    if (null !== $traitLcFilter) {
                        $existsInTraits = isset($perTraitMethods[$traitLcFilter][$methodLc]);
                    } else {
                        foreach ($perTraitMethods as $methods) {
                            if (isset($methods[$methodLc])) {
                                $existsInTraits = true;
                                break;
                            }
                        }
                    }
                    if (isset($excludedMethods[$methodLc]) && $existsInTraits) {
                        continue;
                    }
                    throw new \LogicException(
                        'An alias was defined for ' . $traitPrefix . (string) ($adaptation['method'] ?? '')
                        . ' but this method does not exist'
                    );
                }
                if (null !== $traitLcFilter && $merged[$methodLc]['traitLc'] !== $traitLcFilter) {
                    throw new \LogicException(
                        'An alias was defined for ' . (string) ($adaptation['trait'] ?? '') . '::' . (string) ($adaptation['method'] ?? '')
                        . ' but this method does not exist'
                    );
                }
                if (null !== $newModifier) {
                    $merged[$methodLc]['vis'] = (int) $newModifier;
                }

                continue;
            }

            $newNameLc = strtolower((string) $newName);

            if (null !== $traitLcFilter) {
                if (!isset($usedTraitNameByLc[$traitLcFilter]) || !isset($perTraitMethods[$traitLcFilter][$methodLc])) {
                    throw new \LogicException(
                        'An alias was defined for ' . (string) ($adaptation['trait'] ?? '') . '::' . (string) ($adaptation['method'] ?? '')
                        . ' but this method does not exist'
                    );
                }
                $orig = $perTraitMethods[$traitLcFilter][$methodLc];
                $data = [
                    'traitLc' => $traitLcFilter,
                    'method' => $orig['method'],
                    'vis' => $orig['vis'],
                    'traitName' => $orig['traitName'],
                    'methodNames' => $orig['methodNames'],
                    'attrs' => $orig['attrs'],
                    'deprecated' => $orig['deprecated'],
                    'attributeEntries' => $orig['attributeEntries'],
                    'parameterMetadata' => $orig['parameterMetadata'],
                    'sourceLocation' => $orig['sourceLocation'] ?? null,
                ];
            } else {
                if (isset($merged[$methodLc])) {
                    $data = $merged[$methodLc];
                } else {
                    $source = null;
                    foreach ($perTraitMethods as $methods) {
                        if (isset($methods[$methodLc])) {
                            $source = $methods[$methodLc];
                            break;
                        }
                    }
                    if (null === $source) {
                        throw new \LogicException(
                            'An alias was defined for ' . $traitPrefix . (string) ($adaptation['method'] ?? '')
                            . ' but this method does not exist'
                        );
                    }
                    $data = $source;
                }
            }

            // Zend zend_traits.c: alias onto an existing composed name is a trait collision
            // fatal (not "Cannot redefine method") — #25080.
            if (isset($merged[$newNameLc])) {
                $prev = $merged[$newNameLc];
                $aliasName = (string) $newName;
                $sourceMethod = (string) ($adaptation['method'] ?? '');
                throw new \LogicException(
                    "Trait method {$data['traitName']}::{$sourceMethod} has not been applied as {$entry->name}::{$aliasName}, "
                    ."because of collision with {$prev['traitName']}::{$aliasName}"
                );
            }

            if (null !== $newModifier) {
                $data['vis'] = (int) $newModifier;
            }
            $data['methodNames'] = (string) $newName;
            // Zend zend_traits.c: `as` aliases — original method stays callable (#22718).
            // Trait-qualified `TB::f as g` likewise keeps the merged winner `f`.
            $merged[$newNameLc] = $data;
            $entry->traitAliases[(string) $newName] = $data['traitName'] . '::' . (string) $adaptation['method'];
        }

        foreach ($merged as $methodLc => $data) {
            if (isset($excludedMethods[$methodLc])) {
                continue;
            }
            if (isset($entry->methods[$methodLc]) && !isset($entry->traitMethodSources[$methodLc])) {
                continue;
            }
            if (isset($entry->traitMethodSources[$methodLc])) {
                $prevTrait = $entry->traitMethodSources[$methodLc];
                if ($prevTrait === $data['traitName']) {
                    continue;
                }
                throw new \CompileError(
                    "Trait method {$data['traitName']}::{$methodLc} has not been applied as {$entry->name}::{$methodLc}, "
                    ."because of collision with {$prevTrait}::{$methodLc}"
                );
            }
            $entry->methods[$methodLc] = TraitMethodFunctionStatic::bindMethod(
                $data['method'],
                $entry->name,
                $data['traitName'],
                $data['methodNames']
            );
            $entry->traitMethodSources[$methodLc] = $data['traitName'];
            $entry->methodVisibility[$methodLc] = $data['vis'];
            $entry->methodDeclaringClassLc[$methodLc] = strtolower(ltrim($data['traitName'], '\\'));
            $entry->methodNames[$methodLc] = $data['methodNames'];
            if (null !== ($data['attrs'] ?? null)) {
                $entry->methodAttributeNames[$methodLc] = $data['attrs'];
            }
            if (null !== ($data['deprecated'] ?? null)) {
                $entry->methodDeprecated[$methodLc] = $data['deprecated'];
            }
            if (null !== ($data['attributeEntries'] ?? null)) {
                $entry->methodAttributeEntries[$methodLc] = $data['attributeEntries'];
            }
            if (null !== ($data['parameterMetadata'] ?? null)) {
                $entry->methodParameterMetadata[$methodLc] = $data['parameterMetadata'];
            }
            if (null !== ($data['sourceLocation'] ?? null)) {
                $entry->methodSourceLocations[$methodLc] = $data['sourceLocation'];
            }
            if ('__construct' === $methodLc && null === $entry->constructor) {
                $entry->constructor = $entry->methods[$methodLc];
            }
        }
        $this->linkStaticPropertyHooks($entry);
    }

    /**
     * Merge trait static property-hook metadata into using class (#6624, zend_property_hooks.c + zend_traits.c).
     */
    protected function inheritTraitStaticPropertyHooks(ClassEntry $entry, ClassEntry $trait): void
    {
        $traitLc = strtolower($trait->name);
        $childLc = strtolower($entry->name);
        if (isset($this->context->propertyHookRegistry[$traitLc])) {
            foreach ($this->context->propertyHookRegistry[$traitLc] as $prop => $meta) {
                if (!isset($this->context->propertyHookRegistry[$childLc][$prop])) {
                    $this->context->propertyHookRegistry[$childLc][$prop] = $meta;
                }
            }
        }
        foreach ($trait->staticPropertyHooks as $name => $hooks) {
            if (!isset($entry->staticPropertyHooks[$name])) {
                $entry->staticPropertyHooks[$name] = $hooks;
            }
        }
    }

    /**
     * Zend linkage-time fatal for incompatible trait properties (#17995, zend_inheritance.c).
     *
     * @return never
     */
    protected function throwTraitPropertyCompositionFatal(
        string $message,
        ClassEntry $entry,
        ?SourceLocation $opLocation = null,
        ?Frame $frame = null,
    ): void {
        $location = $opLocation ?? $entry->sourceLocation;
        $file = $location?->filename ?? '';
        if ('' === $file && null !== $frame && '' !== $frame->scriptPath) {
            $file = $frame->scriptPath;
        }
        $line = $location?->startLine ?? 1;
        TraitCompositionConflictMessage::throwRuntimeFatal($message, $file, $line);
    }

    /**
     * Compare an existing class/trait static slot against a trait static being merged (#22850).
     */
    private function traitStaticPropertiesCompatible(
        ClassEntry $entry,
        string $name,
        Variable $existing,
        ClassEntry $trait,
        Variable $incoming,
    ): bool {
        return $this->traitStaticPropertySlotsCompatible(
            $existing,
            (int) ($entry->staticPropertyVisibility[$name] ?? \PHPCfg\Func::FLAG_PUBLIC),
            (int) ($entry->staticPropertySetVisibility[$name] ?? 0),
            (int) ($entry->staticPropertyGetVisibility[$name] ?? 0),
            !empty($entry->staticPropertyAsymmetricExplicitRead[$name]),
            $incoming,
            (int) ($trait->staticPropertyVisibility[$name] ?? \PHPCfg\Func::FLAG_PUBLIC),
            (int) ($trait->staticPropertySetVisibility[$name] ?? 0),
            (int) ($trait->staticPropertyGetVisibility[$name] ?? 0),
            !empty($trait->staticPropertyAsymmetricExplicitRead[$name]),
        );
    }

    private function traitStaticPropertySlotsCompatible(
        Variable $left,
        int $leftVisibility,
        int $leftSetVisibility,
        int $leftGetVisibility,
        bool $leftAsymmetricExplicitRead,
        Variable $right,
        int $rightVisibility,
        int $rightSetVisibility,
        int $rightGetVisibility,
        bool $rightAsymmetricExplicitRead,
    ): bool {
        return VM\TraitPropertyCompatibility::staticPropertiesCompatible(
            $left,
            $leftVisibility,
            $left,
            $right,
            $rightVisibility,
            $right,
            $leftSetVisibility,
            $rightSetVisibility,
            $leftGetVisibility,
            $rightGetVisibility,
            $leftAsymmetricExplicitRead,
            $rightAsymmetricExplicitRead,
        );
    }

    protected function inheritTraitInstanceProperties(ClassEntry $entry, ClassEntry $trait, string $traitName): void
    {
        $traitLc = strtolower(ltrim($traitName, '\\'));
        $classLc = strtolower($entry->name);
        foreach ($trait->properties as $property) {
            $propLc = strtolower($property->name);
            foreach ($entry->properties as $existing) {
                if (strtolower($existing->name) === $propLc) {
                    $existingFromTraitLc = isset($entry->traitPropertySources[$propLc])
                        ? strtolower(ltrim($entry->traitPropertySources[$propLc], '\\'))
                        : (
                            // Legacy / trait-using-trait: declaringClassLc may still name the trait.
                            (isset($this->context->classes[$existing->declaringClassLc])
                                && $this->context->classes[$existing->declaringClassLc]->isTrait)
                                ? $existing->declaringClassLc
                                : null
                        );
                    if ($existingFromTraitLc === $traitLc) {
                        continue 2;
                    }
                    if (null === $existingFromTraitLc && $existing->declaringClassLc === $classLc) {
                        if ($trait->isTrait
                            && VM\AbstractPropertyHookCheck::isAbstractHookProperty($trait, $property, $this->context)) {
                            $this->mergeTraitAbstractPropertyHookOverride($entry, $trait, $property, $existing);
                            continue 2;
                        }
                        // Identical class+trait definitions merge; keep the class property (#22850).
                        if (VM\TraitPropertyCompatibility::instancePropertiesCompatible($existing, $property)) {
                            continue 2;
                        }
                        $this->throwTraitPropertyCompositionFatal(
                            TraitCompositionConflictMessage::incompatibleClassTraitProperty(
                                $entry->name,
                                $traitName,
                                $property->name
                            ),
                            $entry
                        );
                    }
                    if (VM\TraitPropertyCompatibility::instancePropertiesCompatible($existing, $property)) {
                        // Two traits with identical definitions — keep the first (#22850).
                        continue 2;
                    }
                    $prevTrait = $entry->traitPropertySources[$propLc]
                        ?? (
                            isset($this->context->classes[$existing->declaringClassLc])
                                ? $this->context->classes[$existing->declaringClassLc]->name
                                : $existing->declaringClassLc
                        );
                    $this->throwTraitPropertyCompositionFatal(
                        TraitCompositionConflictMessage::incompatibleProperty(
                            $prevTrait,
                            $traitName,
                            $property->name,
                            $entry->name
                        ),
                        $entry
                    );
                }
            }
            $cloned = $this->cloneClassPropertyForEntry($property, $entry);
            // zend_inheritance.c: trait instance properties are owned by the composing class (#26593).
            if (!$entry->isTrait) {
                $cloned->declaringClassLc = $classLc;
                $entry->traitPropertySources[$propLc] = $trait->name !== '' ? $trait->name : $traitName;
            }
            $entry->properties[] = $cloned;
            if (isset($trait->propertyAttributeNames[$propLc])) {
                $entry->propertyAttributeNames[$propLc] = $trait->propertyAttributeNames[$propLc];
            }
            if (isset($trait->propertyAttributeEntries[$propLc])) {
                $entry->propertyAttributeEntries[$propLc] = $trait->propertyAttributeEntries[$propLc];
            }
            if (isset($trait->propDeprecated[$propLc])) {
                $entry->propDeprecated[$propLc] = $trait->propDeprecated[$propLc];
            }
            if (isset($trait->propertySourceLocations[$propLc])) {
                $entry->propertySourceLocations[$propLc] = $trait->propertySourceLocations[$propLc];
            }
        }
    }

    /**
     * Class concrete hooks satisfy trait semicolon hook stubs — keep class property (#7316).
     */
    protected function mergeTraitAbstractPropertyHookOverride(
        ClassEntry $entry,
        ClassEntry $trait,
        VM\ClassProperty $traitProp,
        VM\ClassProperty $classProp
    ): void {
        $traitLc = strtolower($trait->name);
        $childLc = strtolower($entry->name);
        $prop = $traitProp->name;
        $meta = $this->context->propertyHookRegistry[$traitLc][$prop]
            ?? $this->context->propertyHookRegistry[$traitLc][strtolower($prop)]
            ?? null;
        if (!is_array($meta)) {
            return;
        }
        $mergeMeta = $this->propertyHookMetaForInheritedBackingField($entry, $classProp, $meta, $childLc, $prop);
        $this->context->propertyHookRegistry[$childLc][$prop] = $mergeMeta;
        $this->linkPropertyHooks($entry, $classProp);
    }

    private function cloneClassPropertyForEntry(VM\ClassProperty $property, ClassEntry $entry): VM\ClassProperty
    {
        $prototype = clone $property->prototype;
        $default = null !== $property->default ? clone $property->default : null;
        $declaringLc = '' !== $property->declaringClassLc
            ? $property->declaringClassLc
            : strtolower($entry->name);
        $cloned = new VM\ClassProperty(
            $property->name,
            $default,
            $prototype,
            $property->readonly,
            $property->visibility,
            $declaringLc,
            $property->setVisibility,
            $property->getVisibility,
            $property->asymmetricExplicitRead
        );
        $cloned->getHookMethodLc = $property->getHookMethodLc;
        $cloned->setHookMethodLc = $property->setHookMethodLc;
        $cloned->unsetHookMethodLc = $property->unsetHookMethodLc;
        $cloned->getHookParameterized = $property->getHookParameterized;
        $cloned->getHookByRef = $property->getHookByRef;
        $cloned->propertyHookVirtual = $property->propertyHookVirtual;
        $cloned->propertyFinal = $property->propertyFinal;
        $cloned->fromConstructorPromotion = $property->fromConstructorPromotion;
        $cloned->defaultInitBlock = $property->defaultInitBlock;
        $cloned->defaultInitResultSlot = $property->defaultInitResultSlot;

        return $cloned;
    }

    /**
     * @param list<string> $pendingTraits
     * @param array<string, true> $ownMethods
     */
    protected function flushPendingTraitUses(
        ClassEntry $entry,
        array $pendingTraits,
        array $ownMethods = [],
        ?Frame $warningFrame = null
    ): void {
        if ([] === $pendingTraits) {
            return;
        }
        $this->applyTraitUsesWithAdaptations($entry, $pendingTraits, [], $ownMethods, $warningFrame);
    }

    protected function inheritFromInterfaces(ClassEntry $entry): void
    {
        $entryLc = strtolower(ltrim($entry->name, '\\'));
        // Interfaces already satisfied by a parent — zend_inheritance.c does not re-check
        // inherited method bodies against them (only overrides declared on this class) (#25868).
        $inheritedIfaceSet = [];
        if (null !== $entry->parentLc && isset($this->context->classes[$entry->parentLc])) {
            foreach ($this->context->classes[$entry->parentLc]->interfaces as $parentIfaceLc) {
                $inheritedIfaceSet[$parentIfaceLc] = true;
            }
        }
        foreach ($entry->interfaces as $ifaceLc) {
            if (!isset($this->context->classes[$ifaceLc])) {
                continue;
            }
            $iface = $this->context->classes[$ifaceLc];
            $this->inheritInterfacePropertyRules($entry, $iface);
            $this->inheritInterfacePropertyHooks($entry, $iface);
            // Cross-file interface LSP (same-script covered by InheritanceVariance) (#25384).
            $ifaceInheritedFromParent = isset($inheritedIfaceSet[$ifaceLc]);
            foreach ($entry->methods as $methodLc => $_) {
                if ($ifaceInheritedFromParent) {
                    $declLc = $entry->methodDeclaringClassLc[$methodLc] ?? $entryLc;
                    if ($declLc !== $entryLc) {
                        continue;
                    }
                }
                if (isset($iface->methods[$methodLc]) || isset($iface->abstractMethods[$methodLc])) {
                    $this->rejectIncompatibleChildMethodSignature($entry, $iface, $methodLc);
                }
            }
            foreach ($iface->constants as $name => $value) {
                if (isset($entry->constants[$name])) {
                    $existingDeclLc = $entry->constDeclaringClassLc[$name] ?? $entryLc;
                    $incomingDeclLc = $iface->constDeclaringClassLc[$name]
                        ?? strtolower(ltrim($iface->name, '\\'));
                    // Two different declaring interfaces contributing the same constant name
                    // → Zend E_COMPILE_ERROR (do_inherit_iface_constant). Shared parent iface
                    // (same declaring lc) is fine; class/enum body own constants use the
                    // final-override path below (#26672 require/include + #24699).
                    if ($existingDeclLc !== $incomingDeclLc && $existingDeclLc !== $entryLc) {
                        $constDisplay = $entry->constNames[$name]
                            ?? $iface->constNames[$name]
                            ?? $name;
                        $subjectKind = $entry->isInterface ? 'Interface' : ($entry->isEnum ? 'Enum' : 'Class');
                        $subjectDisplay = $this->ambiguousIfaceConstSubjectDisplay($entry);
                        throw new \CompileError(sprintf(
                            '%s %s inherits both %s::%s and %s::%s, which is ambiguous',
                            $subjectKind,
                            $subjectDisplay,
                            $this->ambiguousIfaceConstOwnerDisplay($existingDeclLc),
                            $constDisplay,
                            $this->ambiguousIfaceConstOwnerDisplay($incomingDeclLc),
                            $constDisplay
                        ));
                    }
                    // Class/interface body redeclared a final interface constant (#22329).
                    $this->rejectChildOverrideOfFinalClassConst($entry, $iface, $name);
                    continue;
                }
                $entry->constants[$name] = $value;
                if (isset($iface->constNames[$name])) {
                    $entry->constNames[$name] = $iface->constNames[$name];
                }
                $entry->constDeclaringClassLc[$name] = $iface->constDeclaringClassLc[$name]
                    ?? strtolower(ltrim($iface->name, '\\'));
                if (isset($iface->constVisibility[$name])) {
                    $entry->constVisibility[$name] = $iface->constVisibility[$name];
                }
                // Propagate #[\Deprecated] so C::X (implements I) emits like Zend (#29380).
                if (isset($iface->constDeprecated[$name])) {
                    $entry->constDeprecated[$name] = $iface->constDeprecated[$name];
                }
                if (isset($iface->constFinal[$name])) {
                    $entry->constFinal[$name] = true;
                }
            }
        }
    }

    /** Short display name for ambiguous-iface-const fatals (Zend zend_inheritance.c, #26672). */
    private function ambiguousIfaceConstSubjectDisplay(ClassEntry $entry): string
    {
        $name = $entry->name;
        if (str_contains($name, '\\')) {
            $parts = explode('\\', ltrim($name, '\\'));

            return end($parts) ?: $name;
        }

        return $name;
    }

    /** Declaring interface display for ambiguous-iface-const fatals (#26672). */
    private function ambiguousIfaceConstOwnerDisplay(string $lc): string
    {
        if (isset($this->context->classes[$lc])) {
            return $this->ambiguousIfaceConstSubjectDisplay($this->context->classes[$lc]);
        }

        return $lc;
    }

    /**
     * When an interface is declared after its implementors, merge its constants (#9302, zend_enum.c).
     */
    protected function propagateInterfaceConstantsToImplementors(string $ifaceLc): void
    {
        foreach ($this->context->classes as $entry) {
            if (!in_array($ifaceLc, $entry->interfaces, true)) {
                continue;
            }
            $this->inheritFromInterfaces($entry);
        }
    }

    /**
     * Resolve class constants inherited from interfaces (forward-referenced implements, #9302).
     */
    protected function resolveInheritedClassConstant(ClassEntry $entry, string $memberLc): ?Variable
    {
        foreach ($entry->interfaces as $ifaceLc) {
            if (!isset($this->context->classes[$ifaceLc])) {
                continue;
            }
            $iface = $this->context->classes[$ifaceLc];
            if (isset($iface->constants[$memberLc])) {
                return $iface->constants[$memberLc];
            }
            $fromParentIface = $this->resolveInheritedClassConstant($iface, $memberLc);
            if (null !== $fromParentIface) {
                return $fromParentIface;
            }
        }
        if (null !== $entry->parentLc && isset($this->context->classes[$entry->parentLc])) {
            $parent = $this->context->classes[$entry->parentLc];
            if (isset($parent->constants[$memberLc])) {
                $vis = $parent->constVisibility[$memberLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
                // Skip private parent constants — same rule as inheritFromParent (#19615).
                if (($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
                    return $this->resolveInheritedClassConstant($parent, $memberLc);
                }

                return $parent->constants[$memberLc];
            }

            return $this->resolveInheritedClassConstant($parent, $memberLc);
        }

        return null;
    }

    /**
     * Merge asymmetric set visibility and parent-interface property declares (#4876).
     */
    protected function inheritInterfacePropertyRules(ClassEntry $entry, ClassEntry $iface): void
    {
        foreach ($iface->properties as $ifaceProp) {
            $propLc = strtolower($ifaceProp->name);
            $matched = false;
            foreach ($entry->properties as $classProp) {
                if (strtolower($classProp->name) !== $propLc) {
                    continue;
                }
                $matched = true;
                if (0 !== $ifaceProp->setVisibility) {
                    $classProp->setVisibility = $ifaceProp->setVisibility;
                }
                if (0 !== $ifaceProp->getVisibility) {
                    $classProp->getVisibility = $ifaceProp->getVisibility;
                }
                if ($ifaceProp->asymmetricExplicitRead) {
                    $classProp->asymmetricExplicitRead = true;
                }
                break;
            }
            if (!$matched && $entry->isInterface) {
                $entry->properties[] = $this->cloneClassPropertyForEntry($ifaceProp, $entry);
            }
        }
    }

    /**
     * Merge interface abstract property-hook metadata into implementing classes (#6620, zend_property_hooks.c).
     */
    protected function inheritInterfacePropertyHooks(ClassEntry $entry, ClassEntry $iface): void
    {
        $ifaceLc = strtolower($iface->name);
        if (!isset($this->context->propertyHookRegistry[$ifaceLc])) {
            return;
        }
        $childLc = strtolower($entry->name);
        foreach ($this->context->propertyHookRegistry[$ifaceLc] as $prop => $meta) {
            $propLc = strtolower($prop);
            $classProp = null;
            foreach ($entry->properties as $candidate) {
                if (strtolower($candidate->name) === $propLc) {
                    $classProp = $candidate;
                    break;
                }
            }
            if (null === $classProp) {
                if (!$entry->isInterface) {
                    continue;
                }
                if (!isset($this->context->propertyHookRegistry[$childLc][$prop])) {
                    $this->context->propertyHookRegistry[$childLc][$prop] = $meta;
                }

                continue;
            }
            $mergeMeta = $this->propertyHookMetaForInheritedBackingField($entry, $classProp, $meta, $childLc, $prop);
            if (!isset($this->context->propertyHookRegistry[$childLc][$prop])) {
                $this->context->propertyHookRegistry[$childLc][$prop] = $mergeMeta;
            }
            $this->linkPropertyHooks($entry, $classProp);
        }
    }

    /**
     * Merge abstract-class property-hook metadata into subclasses (#6634, zend_property_hooks.c).
     */
    protected function inheritParentPropertyHooks(ClassEntry $entry, ClassEntry $parent): void
    {
        $parentLc = strtolower($parent->name);
        if (!isset($this->context->propertyHookRegistry[$parentLc])) {
            return;
        }
        $childLc = strtolower($entry->name);
        foreach ($this->context->propertyHookRegistry[$parentLc] as $prop => $meta) {
            $propLc = strtolower($prop);
            $classProp = null;
            foreach ($entry->properties as $candidate) {
                if (strtolower($candidate->name) === $propLc) {
                    $classProp = $candidate;
                    break;
                }
            }
            if (null === $classProp) {
                continue;
            }
            $mergeMeta = $this->propertyHookMetaForInheritedBackingField($entry, $classProp, $meta, $childLc, $prop);
            if (!isset($this->context->propertyHookRegistry[$childLc][$prop])) {
                $this->context->propertyHookRegistry[$childLc][$prop] = $mergeMeta;
            }
            $this->linkPropertyHooks($entry, $classProp);
        }
    }

    /**
     * Implementing / subclass plain typed property satisfies interface or inherited hook stubs (#7311).
     *
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private function propertyHookMetaForInheritedBackingField(
        ClassEntry $entry,
        VM\ClassProperty $classProp,
        array $meta,
        string $childLc,
        string $prop
    ): array {
        if ($this->entryPropertyHasExplicitHookMethods($entry, $classProp->name)) {
            return $meta;
        }
        $childMeta = $this->context->propertyHookRegistry[$childLc][$prop]
            ?? $this->context->propertyHookRegistry[$childLc][strtolower($prop)]
            ?? null;
        if (is_array($childMeta) && !empty($childMeta['abstract']) && empty($childMeta['get']) && empty($childMeta['set'])) {
            return $meta;
        }

        return $this->sanitizePropertyHookMetaForBackingField($meta);
    }

    /**
     * @param array<string, mixed> $meta
     *
     * @return array<string, mixed>
     */
    private function sanitizePropertyHookMetaForBackingField(array $meta): array
    {
        unset($meta['requiresGet'], $meta['requiresSet'], $meta['requiresUnset'], $meta['abstract'], $meta['virtual']);

        return $meta;
    }

    private function entryPropertyHasExplicitHookMethods(ClassEntry $entry, string $propName): bool
    {
        $getLc = strtolower(SourcePreprocessor\PropertyHooks::getHookMethodName($propName));
        $setLc = strtolower(SourcePreprocessor\PropertyHooks::setHookMethodName($propName));
        $unsetLc = strtolower(SourcePreprocessor\PropertyHooks::unsetHookMethodName($propName));

        return isset($entry->methods[$getLc]) || isset($entry->methods[$setLc]) || isset($entry->methods[$unsetLc]);
    }

    /**
     * @param list<string> $rawPermits lowercase names from source (possibly unqualified)
     *
     * @return list<string>
     */
    protected function normalizeSealedPermits(string $sealedName, array $rawPermits): array
    {
        $sealedLc = strtolower(ltrim($sealedName, '\\'));
        $ns = '';
        if (false !== ($pos = strrpos($sealedLc, '\\'))) {
            $ns = substr($sealedLc, 0, $pos + 1);
        }
        $out = [];
        foreach ($rawPermits as $p) {
            $p = strtolower(ltrim($p, '\\'));
            if (str_contains($p, '\\')) {
                $out[] = $p;
            } else {
                $out[] = $ns.$p;
            }
        }

        return $out;
    }

    /**
     * @param list<string> $implements lowercase interface names
     */
    protected function assertAllowedBySealedParents(string $childName, ?string $parentLc, array $implements): void
    {
        $childLc = strtolower(ltrim($childName, '\\'));
        if (null !== $parentLc && isset($this->context->classes[$parentLc])) {
            $parent = $this->context->classes[$parentLc];
            if ($parent->sealed && !VM\ClassSealed::childMayInherit($childLc, $parent->sealedPermits)) {
                $msg = [] === $parent->sealedPermits
                    ? VM\ClassSealed::cannotExtendMessage($childName, $parent->name)
                    : VM\ClassSealed::notInPermitsListMessage($childName, $parent->name);
                throw new \LogicException($msg);
            }
        }
        foreach ($implements as $ifaceLc) {
            if (!isset($this->context->classes[$ifaceLc])) {
                continue;
            }
            $iface = $this->context->classes[$ifaceLc];
            if ($iface->sealed && !VM\ClassSealed::childMayInherit($childLc, $iface->sealedPermits)) {
                throw new \LogicException(VM\ClassSealed::cannotImplementMessage($childName, $iface->name));
            }
        }
    }

    private function cloneStaticPropertyStorage(Variable $source): Variable
    {
        $resolved = $source->resolveIndirect();
        $clone = new Variable();
        if (VM\TypedPropertyCheck::isUninitialized($resolved)) {
            $clone->copyUninitializedStaticPropertySlot($resolved);
        } else {
            $clone->copyFrom($resolved);
        }
        // Preserve declared casing for property_exists() (#23532).
        if (null !== $source->objectPropertyName) {
            $clone->objectPropertyName = $source->objectPropertyName;
        } elseif (null !== $resolved->objectPropertyName) {
            $clone->objectPropertyName = $resolved->objectPropertyName;
        }
        if (null !== $source->staticPropertyClassLc) {
            $clone->staticPropertyClassLc = $source->staticPropertyClassLc;
        } elseif (null !== $resolved->staticPropertyClassLc) {
            $clone->staticPropertyClassLc = $resolved->staticPropertyClassLc;
        }

        return $clone;
    }

    protected function inheritFromParent(ClassEntry $entry): void
    {
        if (null === $entry->parentLc || !isset($this->context->classes[$entry->parentLc])) {
            return;
        }
        $parent = $this->context->classes[$entry->parentLc];
        // php-src zend_inheritance.c — parent must not be ZEND_ACC_TRAIT (#26537).
        if ($parent->isTrait) {
            throw new \CompileError(
                "Class {$entry->name} cannot extend trait {$parent->name}"
            );
        }
        // php-src zend_inheritance.c — cannot extend ZEND_ACC_FINAL (#21669, #3406).
        // Enums are implicitly final (zend_enum.c ZEND_ACC_FINAL; #26531).
        if ($parent->isFinal || $parent->isEnum) {
            throw new \CompileError(
                "Class {$entry->name} cannot extend final class {$parent->name}"
            );
        }
        foreach ($parent->interfaces as $iface) {
            if (!in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }
        foreach ($parent->methods as $name => $method) {
            $vis = $parent->methodVisibility[$name] ?? \PHPCfg\Func::FLAG_PUBLIC;
            // Private methods are not inherited into subclass tables (Zend zend_inheritance).
            if (($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
                continue;
            }
            if (isset($entry->methods[$name])) {
                // Child (or trait) redeclared a non-private parent final method (#24884).
                // Same-script compile is covered by FinalMethodOverrideCheck; cross-eval needs
                // this runtime path (see final class const #22329 / final property #22988).
                $this->rejectChildOverrideOfFinalMethod($entry, $parent, $name);
                // Cross-file / eval LSP: same-script InheritanceVariance never sees the parent (#25384).
                $this->rejectIncompatibleChildMethodSignature($entry, $parent, $name);
                continue;
            }
            // PDO_*_Ext driver methods stay on PDO only (#21552).
            if (isset($parent->methodNotInherited[$name])) {
                continue;
            }
            $entry->methods[$name] = $method;
            $entry->methodVisibility[$name] = $vis;
            if (isset($parent->methodDeclaringClassLc[$name])) {
                $entry->methodDeclaringClassLc[$name] = $parent->methodDeclaringClassLc[$name];
            } else {
                // Builtin parents often omit declaring-class marks; still record the parent
                // so Reflection/LSP can find stub arginfo on the declarer (#25840).
                $entry->methodDeclaringClassLc[$name] = strtolower(ltrim($parent->name, '\\'));
            }
            if (isset($parent->methodParameterMetadata[$name])) {
                $entry->methodParameterMetadata[$name] = $parent->methodParameterMetadata[$name];
            }
            if (isset($parent->methodReturnDeclaredTypes[$name])) {
                $entry->methodReturnDeclaredTypes[$name] = $parent->methodReturnDeclaredTypes[$name];
            }
            if (isset($parent->methodDeprecated[$name])) {
                $entry->methodDeprecated[$name] = $parent->methodDeprecated[$name];
            }
            $entry->methodNames[$name] = $parent->methodNames[$name] ?? $name;
        }
        // Abstract parent methods are not in $parent->methods — still enforce LSP on overrides (#25384).
        foreach ($parent->abstractMethods as $name => $_) {
            if (!isset($entry->methods[$name])) {
                continue;
            }
            $vis = $parent->methodVisibility[$name] ?? \PHPCfg\Func::FLAG_PUBLIC;
            if (($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
                continue;
            }
            $this->rejectIncompatibleChildMethodSignature($entry, $parent, $name);
        }
        foreach ($parent->staticProperties as $name => $storage) {
            if (isset($entry->staticProperties[$name])) {
                // Child redeclared a parent final static — same-script compile is covered by
                // FinalPropertyOverrideCheck; cross-eval needs this runtime path (#24992, #22988).
                // php-src zend_inheritance.c — "Cannot override final property %s::$%s".
                $vis = $parent->staticPropertyVisibility[$name] ?? \PHPCfg\Func::FLAG_PUBLIC;
                if (($vis & \PHPCfg\Func::FLAG_PRIVATE) === 0
                    && !empty($parent->staticPropertyFinal[$name])
                ) {
                    $this->rejectChildOverrideOfFinalStaticProperty($entry, $parent, $name);
                }

                continue;
            }
            // Inherited statics share parent storage (class-declared #4668; trait-composed #4670).
            $entry->staticProperties[$name] = $storage;
            if (isset($parent->traitStaticPropertyNames[$name])) {
                $entry->traitStaticPropertyNames[$name] = true;
            }
            if (isset($parent->staticPropertyVisibility[$name])) {
                $entry->staticPropertyVisibility[$name] = $parent->staticPropertyVisibility[$name];
            }
            if (isset($parent->staticPropertySetVisibility[$name])) {
                $entry->staticPropertySetVisibility[$name] = $parent->staticPropertySetVisibility[$name];
            }
            if (isset($parent->staticPropertyGetVisibility[$name])) {
                $entry->staticPropertyGetVisibility[$name] = $parent->staticPropertyGetVisibility[$name];
            }
            if (isset($parent->staticPropertyAsymmetricExplicitRead[$name])) {
                $entry->staticPropertyAsymmetricExplicitRead[$name] = $parent->staticPropertyAsymmetricExplicitRead[$name];
            }
            if (isset($parent->staticPropertyDeclaringClassLc[$name])) {
                $entry->staticPropertyDeclaringClassLc[$name] = $parent->staticPropertyDeclaringClassLc[$name];
            }
            if (isset($parent->staticPropertyFinal[$name])) {
                $entry->staticPropertyFinal[$name] = true;
            }
        }
        foreach ($parent->staticPropertyHooks as $name => $hooks) {
            if (!isset($entry->staticPropertyHooks[$name])) {
                $entry->staticPropertyHooks[$name] = $hooks;
            }
        }
        $childLc = strtolower($entry->name);
        $this->inheritParentPropertyHooks($entry, $parent);
        foreach ($parent->constants as $name => $value) {
            // Private class constants are not inherited (Zend zend_constants.c / #19615).
            // Child self::PRIVATE must be Undefined constant Child::X, not a visibility leak.
            $vis = $parent->constVisibility[$name] ?? \PHPCfg\Func::FLAG_PUBLIC;
            if (($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
                continue;
            }
            if (isset($entry->constants[$name])) {
                // Child redeclared a parent (or grandparent) final constant — zend_inheritance.c (#22329).
                $this->rejectChildOverrideOfFinalClassConst($entry, $parent, $name);
                continue;
            }
            $entry->constants[$name] = $value;
            if (isset($parent->constNames[$name])) {
                $entry->constNames[$name] = $parent->constNames[$name];
            }
            $entry->constDeclaringClassLc[$name] = $parent->constDeclaringClassLc[$name]
                ?? strtolower(ltrim($parent->name, '\\'));
            if (isset($parent->constVisibility[$name])) {
                $entry->constVisibility[$name] = $parent->constVisibility[$name];
            }
            if (isset($parent->constDeprecated[$name])) {
                $entry->constDeprecated[$name] = $parent->constDeprecated[$name];
            }
            if (isset($parent->constFinal[$name])) {
                $entry->constFinal[$name] = true;
            }
            if (isset($parent->constDeclaredTypes[$name])) {
                $entry->constDeclaredTypes[$name] = $parent->constDeclaredTypes[$name];
            }
            if (isset($parent->constSourceLocations[$name])) {
                $entry->constSourceLocations[$name] = $parent->constSourceLocations[$name];
            }
        }
        foreach ($parent->propDeprecated as $name => $deprecated) {
            if (!isset($entry->propDeprecated[$name])) {
                $entry->propDeprecated[$name] = $deprecated;
            }
        }
        if (null === $entry->constructor && null !== $parent->constructor) {
            $entry->constructor = $parent->constructor;
        }
        if (null === $entry->destructor && null !== $parent->destructor) {
            $entry->destructor = $parent->destructor;
        }
        if ($parent->readonly) {
            $entry->readonly = true;
        }
        if ($parent->usesLazyGhostTrait) {
            $entry->usesLazyGhostTrait = true;
        }
        foreach ($parent->properties as $property) {
            $isPrivate = ($property->visibility & \PHPCfg\Func::FLAG_PRIVATE) !== 0;
            $exists = false;
            $childRedeclare = null;
            foreach ($entry->properties as $existing) {
                if ($existing->name !== $property->name) {
                    continue;
                }
                // Parent private slots coexist with same-name child privates (#22521).
                if ($isPrivate) {
                    if ($existing->declaringClassLc === $property->declaringClassLc) {
                        $exists = true;
                        $childRedeclare = $existing;
                        break;
                    }
                    continue;
                }
                $exists = true;
                $childRedeclare = $existing;
                break;
            }
            if ($exists) {
                // Child redeclared a non-private parent final property (#22988, Zend/zend_inheritance.c).
                // Same-script compile is covered by FinalPropertyOverrideCheck; cross-eval needs
                // this runtime path (see final class const #22329).
                if (!$isPrivate && $property->propertyFinal) {
                    $this->rejectChildOverrideOfFinalProperty($entry, $property);
                }
                // Typed property invariance across eval/include (#23505, zend_inheritance.c).
                if (!$isPrivate && null !== $childRedeclare) {
                    $this->rejectIncompatibleChildPropertyType($entry, $property, $childRedeclare);
                }
                continue;
            }
            $entry->properties[] = $property;
        }
    }

    /**
     * Walk the class hierarchy for __call (Zend zend_std_get_method; dual-it proxies #24287).
     */
    protected function findMagicCallClass(string $lcClass): ?ClassEntry
    {
        $visited = [];
        while (!isset($visited[$lcClass])) {
            $visited[$lcClass] = true;
            if (!isset($this->context->classes[$lcClass])) {
                break;
            }
            $class = $this->context->classes[$lcClass];
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

    /**
     * Walk the class hierarchy for __callStatic (Zend zend_std_get_static_method slow path, #3273).
     */
    protected function findMagicCallStaticClass(string $lcClass): ?ClassEntry
    {
        $visited = [];
        while (!isset($visited[$lcClass])) {
            $visited[$lcClass] = true;
            if (!isset($this->context->classes[$lcClass])) {
                break;
            }
            $class = $this->context->classes[$lcClass];
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
     * @return array{0: ClassEntry, 1: string}
     */
    protected function resolveStaticMethod(string $lcClass, string $methodLc, ?string $displayMethodName = null): array
    {
        $requestedLc = $lcClass;
        $visited = [];
        $abstractDecl = null;
        while (!isset($visited[$lcClass])) {
            $visited[$lcClass] = true;
            if (!isset($this->context->classes[$lcClass])) {
                break;
            }
            $class = $this->context->classes[$lcClass];
            if (isset($class->methods[$methodLc])) {
                return [$class, $methodLc];
            }
            if (isset($class->abstractMethods[$methodLc])) {
                $abstractDecl ??= $class;
            }
            if (null === $class->parentLc) {
                break;
            }
            $lcClass = $class->parentLc;
        }

        if (null !== $abstractDecl) {
            $declName = $abstractDecl->methodNames[$methodLc] ?? $methodLc;
            throw new \LogicException("Cannot call abstract method {$abstractDecl->name}::{$declName}()");
        }

        // Zend zend_execute_API.c — same wording for static and instance misses; keep source casing (#27921).
        $declClass = $this->context->classes[$requestedLc] ?? null;
        $classDisplay = null !== $declClass ? $declClass->name : $requestedLc;
        $methodDisplay = $displayMethodName ?? $methodLc;
        throw new \LogicException("Call to undefined method {$classDisplay}::{$methodDisplay}()");
    }

    protected function initArrayCallable(Frame $frame, Variable $callable): ?Frame
    {
        $table = $callable->toArray();
        $idx0 = new Variable(Variable::TYPE_INTEGER);
        $idx0->int(0);
        $idx1 = new Variable(Variable::TYPE_INTEGER);
        $idx1->int(1);
        if (!$table->keyExists($idx0) || !$table->keyExists($idx1)) {
            return $this->dispatchVmError(
                VM\CallableCheck::arrayCallbackTwoElementsMessage(),
                $frame
            );
        }
        $receiver = $table->findVariable($idx0, false)->resolveIndirect();
        $methodName = $table->findVariable($idx1, false)->resolveIndirect()->toString();
        if (Variable::TYPE_STRING === $receiver->type) {
            $class = $receiver->toString();
            if ('' === $class) {
                throw new \LogicException('Invalid array callable');
            }
            try {
                // Dynamic array callables do not resolve parent/self/static (#25625).
                $this->initStaticCallable($frame, $class.'::'.$methodName, false, false, false);
            } catch (\Error $e) {
                return $this->dispatchVmError($e->getMessage(), $frame);
            } catch (\LogicException $e) {
                return $this->dispatchVmError($e->getMessage(), $frame);
            }

            return null;
        }
        if (Variable::TYPE_OBJECT !== $receiver->type
            && Variable::TYPE_ENUM_CASE !== $receiver->type) {
            throw new \LogicException('Invalid array callable');
        }
        if (Variable::TYPE_ENUM_CASE === $receiver->type) {
            $receiver = VM\EnumCaseSupport::receiverForInstanceMethod($receiver);
        }

        return $this->initMethodCall($frame, $receiver, $methodName);
    }

    /**
     * Declare a user class for JIT/AOT class-constant materialization (#19046, Zend/zend_compile.c).
     *
     * Registers methods (including __construct) without re-running full defineClass(), which would
     * recursively materialize other class constants and hit incomplete VM opcode paths.
     */
    public function ensureClassDeclaredForConstMaterialization(string $name, Block $bodyBlock): void
    {
        $lcname = strtolower(ltrim($name, '\\'));
        if (isset($this->context->classes[$lcname])) {
            return;
        }
        $frame = $bodyBlock->getFrame($this->context);
        $entry = new ClassEntry(ltrim($name, '\\'));
        \PHPCompiler\ext\standard\VmReflection::markCompilerBootstrapClassInternal($entry);
        foreach ($bodyBlock->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_METHOD !== $op->type || null === $op->block1) {
                continue;
            }
            $methodName = strtolower($frame->scope[$op->arg1]->toString());
            $method = new Func\PHP($entry->name.'::'.$methodName, $op->block1);
            $entry->methods[$methodName] = $method;
            if ('__construct' === $methodName) {
                $entry->constructor = $method;
            }
        }
        $this->context->classes[$lcname] = $entry;
    }

    protected function defineClass(ClassEntry $entry, Block $block, ?Frame $warningFrame = null): void {
        $frame = $block->getFrame($this->context);
        $frame->vmContext = $this->context;
        $ownMethods = $this->classBodyOwnMethodNames($block, $frame);
        $pendingNewDefaultOps = [];
        /** @var list<string> */
        $pendingTraits = [];
        $classBodyOps = $block->opCodes;
        $classConstSegments = $this->collectClassConstSegments($classBodyOps, $frame);
        $deferredClassConstSegments = $this->deferredClassConstSegments($classConstSegments);
        $classConstSkipIndices = $this->classConstSegmentSkipIndices($deferredClassConstSegments);
        if ([] !== $deferredClassConstSegments) {
            $entry->forwardDeclaredConstNames = array_fill_keys(
                array_keys($classConstSegments),
                true
            );
        }
        $classBodyOpCount = \count($classBodyOps);
        for ($classBodyOpIndex = 0; $classBodyOpIndex < $classBodyOpCount; ++$classBodyOpIndex) {
            $op = $classBodyOps[$classBodyOpIndex];
            if (isset($classConstSkipIndices[$classBodyOpIndex])) {
                if (OpCode::TYPE_DECLARE_CLASS_CONST === $op->type && [] !== $pendingNewDefaultOps) {
                    $this->finalizePendingNewClassConst($frame, $block, $op, $pendingNewDefaultOps);
                    $pendingNewDefaultOps = [];
                }

                continue;
            }
            if ([] !== $pendingNewDefaultOps) {
                if (OpCode::TYPE_DECLARE_PROPERTY === $op->type || OpCode::TYPE_DECLARE_STATIC_PROPERTY === $op->type) {
                    $this->finalizePendingNewPropertyDefault($frame, $block, $entry, $op, $pendingNewDefaultOps);
                    $pendingNewDefaultOps = [];

                    continue;
                }
                if (OpCode::TYPE_DECLARE_CLASS_CONST === $op->type) {
                    $this->finalizePendingNewClassConst($frame, $block, $op, $pendingNewDefaultOps);
                    $pendingNewDefaultOps = [];
                } else {
                    $pendingNewDefaultOps[] = $op;

                    continue;
                }
            } elseif (OpCode::TYPE_NEW === $op->type) {
                $pendingNewDefaultOps = $this->collectPropertyDefaultNewPreludeOps($classBodyOps, $classBodyOpIndex);
                $pendingNewDefaultOps[] = $op;

                continue;
            } elseif ($this->isClassBodyConstInitOpcode($op->type)) {
                $this->executeClassBodyConstInitOpcode($frame, $op);

                continue;
            }
            if ($this->isClassBodyDefaultInitOpcode($op->type)) {
                if ($this->opcodePrecedesPropertyDefaultNew($classBodyOps, $classBodyOpIndex)) {
                    continue;
                }
                $this->flushPendingTraitUses($entry, $pendingTraits, $ownMethods, $warningFrame ?? $frame);
                $pendingTraits = [];
                $this->executeClassBodyDefaultInitOpcode($frame, $op);

                continue;
            }
            if (VM\ClassConstExpr::isSupportedOpcode($op->type)) {
                VM\ClassConstExpr::execute($this->context, $frame, $block, $op, $entry);

                continue;
            }
            switch ($op->type) {
                case OpCode::TYPE_USE_TRAIT:
                    $pendingTraits[] = $frame->scope[$op->arg1]->toString();
                    break;
                case OpCode::TYPE_TRAIT_USE_ADAPTATION:
                    $this->applyTraitUsesWithAdaptations($entry, $pendingTraits, $op->traitAdaptations, $ownMethods, $warningFrame ?? $frame);
                    $pendingTraits = [];
                    break;
                case OpCode::TYPE_DECLARE_PROPERTY:
                    VM\RedundantTrueFalseUnionCheck::assertPropertyOp($frame, $op);
                    VM\RedundantIterableUnionCheck::assertPropertyOp($frame, $op);
                    $this->flushPendingTraitUses($entry, $pendingTraits, $ownMethods, $warningFrame ?? $frame);
                    $pendingTraits = [];
                    $name = $frame->scope[$op->arg1];
                    $default = $this->resolveCompileTimePropertyDefaultSlot($frame, $block, $op->arg2);
                    $propLc = strtolower($name->toString());
                    $classLc = strtolower($entry->name);
                    $traitAbstractHookOverride = null;
                    $prototype = $frame->scope[$op->arg3];
                    $incoming = new VM\ClassProperty(
                        $name->toString(),
                        $default,
                        $prototype,
                        $op->propertyReadonly,
                        MethodVisibility::mask($op->propertyVisibility),
                        $classLc,
                        (int) ($op->propertySetVisibility ?? 0),
                        (int) ($op->propertyGetVisibility ?? 0),
                        (bool) ($op->propertyAsymmetricExplicitRead ?? false),
                        (bool) ($op->propertyLazy ?? false)
                    );
                    $incoming->fromConstructorPromotion = $op->propertyFromConstructorPromotion;
                    // php-src zend_API.c — private(set) ⇒ ZEND_ACC_FINAL (#23068).
                    $incoming->propertyFinal = (bool) ($op->propertyFinal ?? false)
                        || PropertyVisibility::isImplicitlyFinalFromPrivateSet(
                            (int) ($op->propertySetVisibility ?? 0)
                        );
                    if ($entry->readonly) {
                        $incoming->readonly = true;
                    }
                    $incoming->setVisibility = PropertyVisibility::withImplicitReadonlyProtectedSet(
                        $incoming->readonly,
                        MethodVisibility::mask($incoming->visibility),
                        (int) $incoming->setVisibility
                    );
                    if (PropertyVisibility::isImplicitlyFinalFromPrivateSet($incoming->setVisibility)) {
                        $incoming->propertyFinal = true;
                    }
                    foreach ($entry->properties as $idx => $existing) {
                        if (strtolower($existing->name) !== $propLc) {
                            continue;
                        }
                        $declaringLc = $existing->declaringClassLc;
                        $fromTrait = isset($entry->traitPropertySources[$propLc]);
                        // Trait imports remapped to composing class (#26593) still need class-body merge.
                        if ($declaringLc !== $classLc || $fromTrait) {
                            $traitOriginLc = $fromTrait
                                ? strtolower(ltrim($entry->traitPropertySources[$propLc], '\\'))
                                : $declaringLc;
                            $traitEntry = $this->context->classes[$traitOriginLc] ?? null;
                            if (null !== $traitEntry
                                && $traitEntry->isTrait
                                && VM\AbstractPropertyHookCheck::isAbstractHookProperty(
                                    $traitEntry,
                                    $existing,
                                    $this->context
                                )) {
                                $traitAbstractHookOverride = [$traitEntry, $existing];
                                unset($entry->properties[$idx]);
                                unset($entry->traitPropertySources[$propLc]);
                                $entry->properties = array_values($entry->properties);
                                break;
                            }
                            // Class redeclare of trait property: identical → replace with class (#22850).
                            if (VM\TraitPropertyCompatibility::instancePropertiesCompatible($existing, $incoming)) {
                                unset($entry->properties[$idx]);
                                unset($entry->traitPropertySources[$propLc]);
                                $entry->properties = array_values($entry->properties);
                                break;
                            }
                            $traitName = $entry->traitPropertySources[$propLc]
                                ?? (
                                    isset($this->context->classes[$declaringLc])
                                        ? $this->context->classes[$declaringLc]->name
                                        : $declaringLc
                                );
                            $this->throwTraitPropertyCompositionFatal(
                                TraitCompositionConflictMessage::incompatibleClassTraitProperty(
                                    $entry->name,
                                    $traitName,
                                    $name->toString()
                                ),
                                $entry,
                                null,
                                $frame
                            );
                        }
                    }
                    $prop = $incoming;
                    $entry->properties[] = $prop;
                    if ([] !== $op->attributeNames) {
                        $entry->propertyAttributeNames[$propLc] = $op->attributeNames;
                    }
                    if ([] !== $op->attributeEntries) {
                        $entry->propertyAttributeEntries[$propLc] = $op->attributeEntries;
                    }
                    if (null !== $op->deprecatedMetadata) {
                        $entry->propDeprecated[$propLc] = $op->deprecatedMetadata;
                    }
                    if (null !== $op->sourceLocation) {
                        $entry->propertySourceLocations[$propLc] = $op->sourceLocation;
                    }
                    if (null !== $traitAbstractHookOverride) {
                        $this->mergeTraitAbstractPropertyHookOverride(
                            $entry,
                            $traitAbstractHookOverride[0],
                            $traitAbstractHookOverride[1],
                            $prop
                        );
                    }
                    break;
                case OpCode::TYPE_DECLARE_STATIC_PROPERTY:
                    VM\RedundantTrueFalseUnionCheck::assertPropertyOp($frame, $op);
                    VM\RedundantIterableUnionCheck::assertPropertyOp($frame, $op);
                    $this->flushPendingTraitUses($entry, $pendingTraits, $ownMethods, $warningFrame ?? $frame);
                    $pendingTraits = [];
                    $name = strtolower($frame->scope[$op->arg1]->toString());
                    $classLc = strtolower($entry->name);
                    $storage = $this->cloneStaticPropertyStorage($frame->scope[$op->arg3]);
                    $default = $this->resolveCompileTimePropertyDefaultSlot($frame, $block, $op->arg2);
                    if (null !== $default) {
                        $storage->copyFrom($default);
                    }
                    $newVis = MethodVisibility::mask($op->propertyVisibility);
                    $newSetVis = (int) ($op->propertySetVisibility ?? 0);
                    $newGetVis = (int) ($op->propertyGetVisibility ?? 0);
                    $newAsym = (bool) ($op->propertyAsymmetricExplicitRead ?? false);
                    if (isset($entry->staticProperties[$name])) {
                        $declaringLc = $entry->staticPropertyDeclaringClassLc[$name] ?? $classLc;
                        if ($declaringLc !== $classLc) {
                            $existing = $entry->staticProperties[$name];
                            if ($this->traitStaticPropertySlotsCompatible(
                                $existing,
                                (int) ($entry->staticPropertyVisibility[$name] ?? \PHPCfg\Func::FLAG_PUBLIC),
                                (int) ($entry->staticPropertySetVisibility[$name] ?? 0),
                                (int) ($entry->staticPropertyGetVisibility[$name] ?? 0),
                                !empty($entry->staticPropertyAsymmetricExplicitRead[$name]),
                                $storage,
                                $newVis,
                                $newSetVis,
                                $newGetVis,
                                $newAsym
                            )) {
                                // Identical class+trait static — class wins declaring (#22850).
                            } else {
                                $traitName = isset($this->context->classes[$declaringLc])
                                    ? $this->context->classes[$declaringLc]->name
                                    : $declaringLc;
                                $this->throwTraitPropertyCompositionFatal(
                                    TraitCompositionConflictMessage::incompatibleClassTraitProperty(
                                        $entry->name,
                                        $traitName,
                                        $name
                                    ),
                                    $entry,
                                    null,
                                    $frame
                                );
                            }
                        }
                    }
                    $this->linkStaticTypedPropertySlot(
                        $storage,
                        $entry,
                        $frame->scope[$op->arg1]->toString()
                    );
                    $entry->staticProperties[$name] = $storage;
                    $entry->staticPropertyVisibility[$name] = $newVis;
                    $entry->staticPropertySetVisibility[$name] = $newSetVis;
                    $entry->staticPropertyGetVisibility[$name] = $newGetVis;
                    if ($newAsym) {
                        $entry->staticPropertyAsymmetricExplicitRead[$name] = true;
                    }
                    $entry->staticPropertyDeclaringClassLc[$name] = strtolower($entry->name);
                    // php-src ZEND_ACC_FINAL on static props — inheritance + Reflection only (#23683).
                    if (!empty($op->propertyFinal)) {
                        $entry->staticPropertyFinal[$name] = true;
                    } else {
                        unset($entry->staticPropertyFinal[$name]);
                    }
                    if (null !== $op->deprecatedMetadata) {
                        $entry->propDeprecated[$name] = $op->deprecatedMetadata;
                    }
                    if (null !== $op->sourceLocation) {
                        $entry->propertySourceLocations[$name] = $op->sourceLocation;
                    }
                    break;
                case OpCode::TYPE_DECLARE_METHOD:
                    $this->flushPendingTraitUses($entry, $pendingTraits, $ownMethods, $warningFrame ?? $frame);
                    $pendingTraits = [];
                    $declaredName = $frame->scope[$op->arg1]->toString();
                    $name = strtolower($declaredName);
                    $vis = \PHPCfg\Func::FLAG_PUBLIC;
                    if (null !== $op->arg3 && isset($block->constants[$op->arg3])) {
                        $storedFlags = $block->constants[$op->arg3]->toInt();
                        $vis = MethodVisibility::mask($storedFlags);
                        if (($storedFlags & \PHPCfg\Func::FLAG_STATIC) !== 0) {
                            $vis |= \PHPCfg\Func::FLAG_STATIC;
                        }
                        if (($storedFlags & \PHPCfg\Func::FLAG_FINAL) !== 0) {
                            $vis |= \PHPCfg\Func::FLAG_FINAL;
                        }
                    }
                    if (($vis & \PHPCfg\Func::FLAG_FINAL) !== 0 && ($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
                        $warnLine = null !== $op->arg2 && $op->arg2 > 0 ? $op->arg2 : 0;
                        $handlerFrame = $warningFrame ?? $frame;
                        $warnFile = '' !== $handlerFrame->scriptPath ? $handlerFrame->scriptPath : null;
                        if (null === $warnFile || '' === $warnFile) {
                            $current = $this->context->scriptStack->current();
                            if ('' !== $current) {
                                $warnFile = $current;
                            }
                        }
                        $this->context->errors->languageWarning(
                            'Private methods cannot be final as they are never overridden by other classes',
                            $warnFile,
                            $warnLine,
                            $this->context,
                            $handlerFrame
                        );
                    }
                    $entry->methodVisibility[$name] = $vis;
                    $entry->methodDeclaringClassLc[$name] = strtolower($entry->name);
                    unset($entry->traitMethodSources[$name]);
                    $entry->methodNames[$name] = $declaredName;
                    if ([] !== $op->attributeNames) {
                        $entry->methodAttributeNames[$name] = $op->attributeNames;
                        $hookReflection = \PHPCompiler\SourcePreprocessor\PropertyHooks::reflectionNameFromHookMethod($name);
                        if (null !== $hookReflection) {
                            $entry->methodAttributeNames[$hookReflection] = $op->attributeNames;
                        }
                    }
                    if (null !== $op->deprecatedMetadata) {
                        $entry->methodDeprecated[$name] = $op->deprecatedMetadata;
                    }
                    if ([] !== $op->attributeEntries) {
                        $entry->methodAttributeEntries[$name] = $op->attributeEntries;
                        $hookReflection = \PHPCompiler\SourcePreprocessor\PropertyHooks::reflectionNameFromHookMethod($name);
                        if (null !== $hookReflection) {
                            $entry->methodAttributeEntries[$hookReflection] = $op->attributeEntries;
                        }
                    }
                    if ([] !== $op->parameterMetadata) {
                        $entry->methodParameterMetadata[$name] = $op->parameterMetadata;
                    }
                    if (null !== $op->returnDeclaredType) {
                        $entry->methodReturnDeclaredTypes[$name] = $op->returnDeclaredType;
                    }
                    if (null !== $op->sourceLocation) {
                        $entry->methodSourceLocations[$name] = $op->sourceLocation;
                    }
                    if (null !== $op->block1) {
                        VM\RedundantTrueFalseUnionCheck::assertFunctionBlock(
                            $op->block1,
                            $frame,
                            $op->sourceLocation
                        );
                        VM\RedundantIterableUnionCheck::assertFunctionBlock(
                            $op->block1,
                            $frame,
                            $op->sourceLocation
                        );
                        $method = new Func\PHP($entry->name.'::'.$name, $op->block1);
                        $method->deprecated = $op->deprecatedMetadata;
                        $entry->methods[$name] = $method;
                        unset($entry->abstractMethods[$name]);
                        if ('__construct' === $name) {
                            $entry->constructor = $method;
                        }
                        if ('__destruct' === $name) {
                            $entry->destructor = $method;
                        }
                    } else {
                        $entry->abstractMethods[$name] = true;
                    }
                    break;
                case OpCode::TYPE_DECLARE_CLASS_CONST:
                    $this->flushPendingTraitUses($entry, $pendingTraits, $ownMethods, $warningFrame ?? $frame);
                    $pendingTraits = [];
                    $this->applyClassConstDeclaration($entry, $block, $frame, $op);
                    break;
                default:
                    $this->flushPendingTraitUses($entry, $pendingTraits, $ownMethods, $warningFrame ?? $frame);
                    $pendingTraits = [];
                    throw new \LogicException(
                        'Other class body types are not jittable for now: '.opcode_type_name($op->type)
                    );
            }
        }
        $this->flushPendingTraitUses($entry, $pendingTraits, $ownMethods, $warningFrame ?? $frame);
        if ([] !== $pendingNewDefaultOps) {
            throw new \LogicException('Unterminated property default `new` initializer in class body');
        }
        if ([] !== $deferredClassConstSegments) {
            $stillPending = $this->finalizeDeferredClassConstants(
                $entry,
                $block,
                $frame,
                $classBodyOps,
                $deferredClassConstSegments
            );
            if ([] !== $stillPending) {
                $this->context->deferredClassConstants[] = [
                    'entry' => $entry,
                    'block' => $block,
                    'frame' => $frame,
                    'classBodyOps' => $classBodyOps,
                    'segments' => $stillPending,
                ];
            }
        }
        foreach ($entry->properties as $prop) {
            $this->linkPropertyHooks($entry, $prop);
        }
        $this->linkStaticPropertyHooks($entry);
        if ($entry->isEnum) {
            VM\EnumSupport::ensureBuiltinCasesMethod($entry);
        }
        if ($entry->usesLazyGhostTrait) {
            VM\LazyGhostTraitSupport::ensureBuiltinLazyGhostMethods($entry);
        }
    }

    private function resolveClassConstDefineValue(Frame $frame, Block $block, OpCode $op): Variable
    {
        $value = $this->resolveClassConstInitializerValue($frame, $block, $op->arg2);

        return VM\EnumCaseSupport::materializeConstantValue($this->context, $value);
    }

    /**
     * Runtime `new` class-const inits land in frame scope; folded scalars in block constants (#9116).
     */
    private function resolveClassConstInitializerValue(Frame $frame, Block $block, int $slot): Variable
    {
        if (isset($frame->scope[$slot])) {
            $scoped = $frame->scope[$slot]->resolveIndirect();
            if (!$scoped->is(Variable::TYPE_NULL)) {
                $value = new Variable();
                $value->copyFrom($scoped);

                return $value;
            }
        }
        if (isset($block->constants[$slot])) {
            $value = new Variable();
            $value->copyFrom($block->constants[$slot]);

            return $value;
        }
        if (isset($frame->scope[$slot])) {
            $value = new Variable();
            $value->copyFrom($frame->scope[$slot]);

            return $value;
        }

        throw new \LogicException('Class constant value must be a compile-time constant');
    }

    /**
     * Folded parameter/property/static defaults live in block constants (#3803, #7399).
     */
    private function resolveCompileTimePropertyDefaultSlot(Frame $frame, Block $block, ?int $slot): ?Variable
    {
        if (null === $slot) {
            return null;
        }
        if (isset($block->constants[$slot])) {
            return VM\ClassConstMaterializer::detachConstantValue($block->constants[$slot]);
        }
        if (isset($frame->scope[$slot])) {
            return $frame->scope[$slot];
        }

        return null;
    }

    private function applyClassConstDeclaration(
        ClassEntry $entry,
        Block $block,
        Frame $frame,
        OpCode $op
    ): void {
        $canonical = $frame->scope[$op->arg1]->toString();
        // Case-sensitive key (Zend/zend_compile.c, #25910 fetch / #25929 declare).
        $name = ClassConstName::key($canonical);
        if ($entry->isEnum && $op->isEnumCaseDeclare) {
            $backingSource = VM\ClassConstExpr::resolveValue($frame, $block, $op->arg2);
            $caseBacking = new Variable(Variable::TYPE_NULL);
            $caseBacking->null();
            if (null !== $entry->backedType) {
                $caseBacking = clone VM\BackedEnum::caseBackingScalar(
                    $entry->backedType,
                    $backingSource
                );
            }
            $entry->constants[$name] = EnumCaseSupport::createCase(
                $entry,
                $canonical,
                $caseBacking
            );
            $entry->enumCaseCanonicalNames[$name] = $canonical;
            $entry->constNames[$name] = $canonical;
            $entry->enumCases[] = [
                'name' => $canonical,
                'value' => $caseBacking,
            ];
            if ([] !== $op->attributeEntries) {
                $entry->enumCaseAttributeEntries[$name] = $op->attributeEntries;
            }
            if (null !== $op->deprecatedMetadata) {
                $entry->constDeprecated[$name] = $op->deprecatedMetadata;
            }

            return;
        }
        $value = $this->resolveClassConstDefineValue($frame, $block, $op);
        if (null !== $op->arg3 && isset($block->constants[$op->arg3])) {
            $check = new Variable();
            $check->copyFrom($value);
            TypeCheck::assertClassConstantTypedValue(
                $check,
                $block->constants[$op->arg3],
                $canonical,
                $entry->name
            );
            $value->copyFrom($check);
        }
        $this->rejectIncompatibleTraitClassConstOverride($entry, $name, $canonical, $value);
        $entry->constants[$name] = $value;
        $entry->constNames[$name] = $canonical;
        $entry->constDeclaringClassLc[$name] = strtolower(ltrim($entry->name, '\\'));
        $entry->constVisibility[$name] = ClassConstVisibility::mask($op->classConstVisibilityFlags);
        unset($entry->traitConstSources[$name]);
        if ([] !== $op->attributeNames) {
            $entry->constAttributeNames[$name] = $op->attributeNames;
        }
        if ([] !== $op->attributeEntries) {
            $entry->constAttributeEntries[$name] = $op->attributeEntries;
        }
        if (null !== $op->deprecatedMetadata) {
            $entry->constDeprecated[$name] = $op->deprecatedMetadata;
        }
        if (null !== $op->sourceLocation) {
            $entry->constSourceLocations[$name] = $op->sourceLocation;
        }
        if (0 !== ($op->classConstVisibilityFlags & \PHPCfg\Func::FLAG_FINAL)) {
            $entry->constFinal[$name] = true;
        }
        if (isset($block->classConstDeclaredTypes[$name])) {
            $entry->constDeclaredTypes[$name] = $block->classConstDeclaredTypes[$name];
        }
    }

    /**
     * @param list<OpCode> $classBodyOps
     * @return array<string, array{initIndices: list<int>, declareIndex: int}>
     */
    private function collectClassConstSegments(array $classBodyOps, Frame $frame): array
    {
        $segments = [];
        /** @var list<int> $pendingInitIndices */
        $pendingInitIndices = [];
        $inNewFragment = false;
        foreach ($classBodyOps as $index => $op) {
            if (OpCode::TYPE_DECLARE_CLASS_CONST === $op->type) {
                $name = ClassConstName::key($frame->scope[$op->arg1]->toString());
                $segments[$name] = [
                    'initIndices' => $pendingInitIndices,
                    'declareIndex' => $index,
                ];
                $pendingInitIndices = [];
                $inNewFragment = false;

                continue;
            }
            if ($inNewFragment) {
                $pendingInitIndices[] = $index;

                continue;
            }
            if ($this->isClassConstSegmentInitOpcode($op->type)) {
                $pendingInitIndices[] = $index;
                if (OpCode::TYPE_NEW === $op->type) {
                    $inNewFragment = true;
                }
            } elseif ([] !== $pendingInitIndices) {
                $pendingInitIndices = [];
            }
        }

        return $segments;
    }

    /**
     * @param array<string, array{initIndices: list<int>, declareIndex: int}> $segments
     * @return array<int, true>
     */
    private function classConstSegmentSkipIndices(array $segments): array
    {
        $skip = [];
        foreach ($segments as $segment) {
            foreach ($segment['initIndices'] as $index) {
                $skip[$index] = true;
            }
            $skip[$segment['declareIndex']] = true;
        }

        return $skip;
    }

    private function isClassConstSegmentInitOpcode(int $type): bool
    {
        return VM\ClassConstExpr::isSupportedOpcode($type)
            || $this->isClassBodyConstInitOpcode($type);
    }

    /**
     * @param array<string, array{initIndices: list<int>, declareIndex: int}> $segments
     * @return array<string, array{initIndices: list<int>, declareIndex: int}>
     */
    private function deferredClassConstSegments(array $segments): array
    {
        $deferred = [];
        foreach ($segments as $lcName => $segment) {
            if ([] !== $segment['initIndices']) {
                $deferred[$lcName] = $segment;
            }
        }

        return $deferred;
    }

    /**
     * @param list<OpCode> $classBodyOps
     * @param array<string, array{initIndices: list<int>, declareIndex: int}> $segments
     * @return array<string, array{initIndices: list<int>, declareIndex: int}>
     */
    private function finalizeDeferredClassConstants(
        ClassEntry $entry,
        Block $block,
        Frame $frame,
        array $classBodyOps,
        array $segments
    ): array {
        /** @var list<string> $pending */
        $pending = array_keys($segments);
        $maxPasses = \count($pending) + 1;
        for ($pass = 0; $pass < $maxPasses && [] !== $pending; ++$pass) {
            /** @var list<string> $stillPending */
            $stillPending = [];
            $madeProgress = false;
            foreach ($pending as $lcName) {
                if (isset($entry->constants[$lcName])) {
                    continue;
                }
                try {
                    $this->evaluateDeferredClassConstSegment(
                        $entry,
                        $block,
                        $frame,
                        $classBodyOps,
                        $segments[$lcName]
                    );
                    $madeProgress = true;
                } catch (VM\ClassConstForwardReferenceException) {
                    $stillPending[] = $lcName;
                }
            }
            if (!$madeProgress) {
                break;
            }
            $pending = $stillPending;
        }
        if ([] !== $pending) {
            $entry->forwardDeclaredConstNames = array_fill_keys($pending, true);
            $stillPending = [];
            foreach ($pending as $lcName) {
                $stillPending[$lcName] = $segments[$lcName];
            }

            return $stillPending;
        }
        $entry->forwardDeclaredConstNames = null;

        return [];
    }

    /**
     * @param list<OpCode> $classBodyOps
     * @param array{initIndices: list<int>, declareIndex: int} $segment
     */
    private function evaluateDeferredClassConstSegment(
        ClassEntry $entry,
        Block $block,
        Frame $frame,
        array $classBodyOps,
        array $segment
    ): void {
        $declareOp = $classBodyOps[$segment['declareIndex']];
        $initOps = [];
        foreach ($segment['initIndices'] as $index) {
            $initOps[] = $classBodyOps[$index];
        }
        $newResultSlot = $this->classConstNewFragmentResultSlot($initOps);
        if (null !== $newResultSlot) {
            $value = $this->executePropertyDefaultInitBlock(
                $block->fragmentForOpcodes($initOps),
                $newResultSlot
            );
            if (!isset($frame->scope[$declareOp->arg2])) {
                $frame->scope[$declareOp->arg2] = new Variable();
            }
            $frame->scope[$declareOp->arg2]->copyFrom($value);
        } else {
            foreach ($initOps as $op) {
                if (VM\ClassConstExpr::isSupportedOpcode($op->type)) {
                    VM\ClassConstExpr::execute($this->context, $frame, $block, $op, $entry);
                } elseif ($this->isClassBodyConstInitOpcode($op->type)) {
                    $this->executeClassBodyConstInitOpcode($frame, $op);
                } else {
                    throw new \LogicException(
                        'Unexpected class constant init opcode: '.opcode_type_name($op->type)
                    );
                }
            }
        }
        $this->applyClassConstDeclaration(
            $entry,
            $block,
            $frame,
            $declareOp
        );
    }

    /**
     * @param list<OpCode> $pendingNewDefaultOps
     */
    private function finalizePendingNewClassConst(
        Frame $frame,
        Block $block,
        OpCode $declareOp,
        array $pendingNewDefaultOps
    ): void {
        $resultSlot = $this->classConstNewFragmentResultSlot($pendingNewDefaultOps);
        if (null === $resultSlot) {
            foreach ($pendingNewDefaultOps as $pendingOp) {
                $this->executeClassBodyConstInitOpcode($frame, $pendingOp);
            }

            return;
        }
        $value = $this->executePropertyDefaultInitBlock(
            $block->fragmentForOpcodes($pendingNewDefaultOps),
            $resultSlot
        );
        if (!isset($frame->scope[$declareOp->arg2])) {
            $frame->scope[$declareOp->arg2] = new Variable();
        }
        $frame->scope[$declareOp->arg2]->copyFrom($value);
    }

    /**
     * @param list<OpCode> $initOps
     */
    private function classConstNewFragmentResultSlot(array $initOps): ?int
    {
        foreach ($initOps as $initOp) {
            if (OpCode::TYPE_NEW === $initOp->type) {
                return $initOp->arg1;
            }
        }

        return null;
    }

    /**
     * @param list<OpCode> $pendingNewDefaultOps
     */
    private function finalizePendingNewPropertyDefault(
        Frame $frame,
        Block $block,
        ClassEntry $entry,
        OpCode $declareOp,
        array $pendingNewDefaultOps
    ): void {
        $resultSlot = null;
        foreach ($pendingNewDefaultOps as $initOp) {
            if (OpCode::TYPE_NEW === $initOp->type) {
                $resultSlot = $initOp->arg1;
                break;
            }
        }
        if (null === $resultSlot) {
            throw new \LogicException('Property default `new` initializer missing TYPE_NEW');
        }

        if (OpCode::TYPE_DECLARE_STATIC_PROPERTY === $declareOp->type) {
            $value = $this->executePropertyDefaultInitBlock(
                $block->fragmentForOpcodes($pendingNewDefaultOps),
                $resultSlot
            );
            $name = strtolower($frame->scope[$declareOp->arg1]->toString());
            $storage = $this->cloneStaticPropertyStorage($frame->scope[$declareOp->arg3]);
            $storage->copyFrom($value);
            $this->linkStaticTypedPropertySlot(
                $storage,
                $entry,
                $frame->scope[$declareOp->arg1]->toString()
            );
            $entry->staticProperties[$name] = $storage;
            $entry->staticPropertyVisibility[$name] = MethodVisibility::mask($declareOp->propertyVisibility);
            $entry->staticPropertySetVisibility[$name] = (int) ($declareOp->propertySetVisibility ?? 0);
            $entry->staticPropertyGetVisibility[$name] = (int) ($declareOp->propertyGetVisibility ?? 0);
            if ($declareOp->propertyAsymmetricExplicitRead ?? false) {
                $entry->staticPropertyAsymmetricExplicitRead[$name] = true;
            }
            $entry->staticPropertyDeclaringClassLc[$name] = strtolower($entry->name);
            if (!empty($declareOp->propertyFinal)) {
                $entry->staticPropertyFinal[$name] = true;
            } else {
                unset($entry->staticPropertyFinal[$name]);
            }

            return;
        }

        $property = new VM\ClassProperty(
            $frame->scope[$declareOp->arg1]->toString(),
            null,
            $frame->scope[$declareOp->arg3],
            $declareOp->propertyReadonly,
            MethodVisibility::mask($declareOp->propertyVisibility),
            strtolower($entry->name),
            (int) ($declareOp->propertySetVisibility ?? 0),
            (int) ($declareOp->propertyGetVisibility ?? 0),
            (bool) ($declareOp->propertyAsymmetricExplicitRead ?? false),
            (bool) ($declareOp->propertyLazy ?? false)
        );
        $property->defaultInitBlock = $block->fragmentForOpcodes($pendingNewDefaultOps);
        $property->defaultInitResultSlot = $resultSlot;
        if ($entry->readonly) {
            $property->readonly = true;
        }
        $property->setVisibility = PropertyVisibility::withImplicitReadonlyProtectedSet(
            $property->readonly,
            MethodVisibility::mask($property->visibility),
            (int) $property->setVisibility
        );
        // php-src zend_API.c — private(set) ⇒ ZEND_ACC_FINAL (#23068).
        $property->propertyFinal = (bool) ($declareOp->propertyFinal ?? false)
            || PropertyVisibility::isImplicitlyFinalFromPrivateSet(
                (int) $property->setVisibility
            );
        $entry->properties[] = $property;
    }

    public function initInstancePropertyDefaults(ObjectEntry $object): void
    {
        foreach ($object->class->properties as $property) {
            if ($property->lazy) {
                continue;
            }
            if (!$property->hasRuntimeDefaultInit()) {
                continue;
            }
            assert(null !== $property->defaultInitBlock);
            assert(null !== $property->defaultInitResultSlot);
            $value = $this->executePropertyDefaultInitBlock(
                $property->defaultInitBlock,
                $property->defaultInitResultSlot
            );
            $slot = $object->getProperty($property->name);
            $slot->copyFrom($value);
            $strict = false;
            TypeCheck::coercePropertyWrite($slot, $strict);
        }
    }

    /**
     * Reapply a declared property default during clone-with property list (#10310, Zend/zend_clones.c).
     */
    public function reinitCloneWithProperty(ObjectEntry $object, string $propName): void
    {
        $meta = $this->classPropertyMeta($object, $propName);
        if (null === $meta) {
            throw new \Error(sprintf(
                'Cannot reinitialize property %s::$%s',
                $object->class->name,
                $propName
            ));
        }
        $slot = $object->getProperty($propName);
        if ($meta->hasRuntimeDefaultInit()) {
            assert(null !== $meta->defaultInitBlock);
            assert(null !== $meta->defaultInitResultSlot);
            $value = $this->executePropertyDefaultInitBlock(
                $meta->defaultInitBlock,
                $meta->defaultInitResultSlot
            );
            $slot->copyFrom($value);
        } elseif (null !== $meta->default) {
            $slot->copyFrom($meta->default);
        } else {
            $slot->copyFrom($meta->getVariable());
        }
        TypeCheck::coercePropertyWrite($slot, false);
    }

    /**
     * `new Class(...)` first-class callable invoke (#9767, zend_compile.c).
     *
     * @param list<Variable> $ctorArgs
     */
    /**
     * ReflectionClass::newInstanceWithoutConstructor() object allocation (#5443, zend_objects.c).
     */
    public function allocateObjectWithoutConstructor(ClassEntry $class): ObjectEntry
    {
        VM\ReservedBuiltinClass::assertUserInstantiable($class);
        if ($class->isEnum) {
            throw new \Error("Cannot instantiate enum {$class->name}");
        }
        if ($class->isInterface) {
            throw new \Error("Cannot instantiate interface {$class->name}");
        }
        if ($class->isTrait) {
            throw new \Error("Cannot instantiate trait {$class->name}");
        }
        if ($class->isAbstract) {
            throw new \Error("Cannot instantiate abstract class {$class->name}");
        }
        $object = new ObjectEntry($class);
        $this->initInstancePropertyDefaults($object);
        if (null === $class->constructor && !$this->hasInstanceMethod($class, '__construct')) {
            $object->constructed = true;
        }

        return $object;
    }

    public function instantiateFromNewCallable(ClassEntry $class, Frame $frame, Variable ...$ctorArgs): ObjectEntry
    {
        VM\ReservedBuiltinClass::assertUserInstantiable($class);
        if ($class->isEnum) {
            throw new \Error("Cannot instantiate enum {$class->name}");
        }
        if ($class->isAbstract) {
            throw new \Error("Cannot instantiate abstract class {$class->name}");
        }
        if ($class->isInterface) {
            throw new \Error("Cannot instantiate interface {$class->name}");
        }
        if ($class->isTrait) {
            throw new \Error("Cannot instantiate trait {$class->name}");
        }
        VM\ClassValidator::assertInstantiable($class);
        if (null !== $class->constructor || $this->hasInstanceMethod($class, '__construct')) {
            // Unadvertised internal constructors skip visibility resolve (#22789).
            if ($this->hasInstanceMethod($class, '__construct')) {
                try {
                    [$declaringClass, $methodLc] = $this->resolveInstanceMethod($class, '__construct');
                    $vis = $declaringClass->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
                    $callerClassLc = $this->callerClassLc($frame);
                    $callerDisplay = $this->callerScopeDisplay($frame, $callerClassLc);
                    MethodVisibility::assertConstructorCallable(
                        $vis,
                        $callerClassLc,
                        strtolower($declaringClass->name),
                        $declaringClass->name,
                        false,
                        fn (string $classLc, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLc, $ancestorLc),
                        $callerDisplay
                    );
                } catch (\LogicException $e) {
                    throw new \Error($e->getMessage());
                }
            }
        }
        $this->emitClassInstantiationDeprecation($class, $frame);
        $object = new ObjectEntry($class);
        $this->initInstancePropertyDefaults($object);
        $thisVar = new Variable(Variable::TYPE_OBJECT);
        $thisVar->object($object);
        if (null !== $object->constructor) {
            $this->invokePhpFunction($object->constructor, $thisVar, ...$ctorArgs);
        }
        $object->constructed = true;

        return $object;
    }

    /** Evaluate declared default for ReflectionProperty::getDefaultValue() (#11239). */
    public function evaluatePropertyDefaultForReflection(VM\ClassProperty $property): ?Variable
    {
        // Promoted ctor props: param default is not a property default (#22046).
        if ($property->fromConstructorPromotion) {
            return null;
        }
        if (null !== $property->default && !$property->hasRuntimeDefaultInit()) {
            $copy = new Variable();
            $copy->copyFrom($property->default);

            return $copy;
        }
        if ($property->hasRuntimeDefaultInit()) {
            return $this->executePropertyDefaultInitBlock(
                $property->defaultInitBlock,
                $property->defaultInitResultSlot
            );
        }
        // Untyped property without initializer: Zend implicit null (#22047).
        if (!$property->fromConstructorPromotion && !$property->hasDeclaredType()) {
            $copy = new Variable();
            $copy->null();

            return $copy;
        }

        return null;
    }

    /** Evaluate declared default for ReflectionParameter::getDefaultValue() (#4385, ext/reflection/php_reflection.c). */
    public function evaluateParameterDefaultForReflection(Block $block, int $paramIndex): ?Variable
    {
        if (VM\ReflectionSupport::parameterIsVariadic($block, $paramIndex)) {
            return null;
        }
        if (isset($block->paramRuntimeDefaultInitBlocks[$paramIndex])) {
            $initBlock = $block->paramRuntimeDefaultInitBlocks[$paramIndex];
            $resultSlot = $block->paramRuntimeDefaultResultSlots[$paramIndex] ?? null;
            if (null === $resultSlot) {
                return null;
            }
            $copy = new Variable();
            $copy->copyFrom($this->executePropertyDefaultInitBlock($initBlock, $resultSlot));

            return $copy;
        }
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_ARG_RECV !== $op->type || (int) $op->arg2 !== $paramIndex) {
                continue;
            }
            if (null === $op->arg3 || !isset($block->constants[$op->arg3])) {
                return null;
            }
            $default = $block->constants[$op->arg3];
            $copy = new Variable();
            if (VM\EnumCaseSupport::isEnumCaseVariable($default)) {
                $copy->copyFrom(
                    VM\EnumCaseSupport::materializeConstantValue($this->context, $default)
                );
            } else {
                $copy->copyFrom($default);
            }

            return $copy;
        }

        return null;
    }

    public function materializeClassConstInitFragment(Block $fragmentBlock, int $resultSlot): Variable
    {
        return $this->executePropertyDefaultInitBlock($fragmentBlock, $resultSlot);
    }

    private function executePropertyDefaultInitBlock(Block $initBlock, int $resultSlot): Variable
    {
        $initFrame = $initBlock->getFrame($this->context);
        // Nested runFrames must not jump into an outer user try/catch — that resumes the
        // caller after catch and re-runs trailing opcodes (#24138; same shape as #14104).
        $prevDefer = $this->context->deferBuiltinCallbackCatchToOuterRunFrames;
        $this->context->deferBuiltinCallbackCatchToOuterRunFrames = true;
        try {
            $this->context->push($initFrame);
            $status = $this->runFrames();
        } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
            throw $redirect;
        } finally {
            $this->context->deferBuiltinCallbackCatchToOuterRunFrames = $prevDefer;
        }
        if (self::SUCCESS !== $status) {
            throw new \LogicException('Property default `new` initializer failed');
        }
        if (!isset($initFrame->scope[$resultSlot])) {
            throw new \LogicException('Property default `new` initializer missing result slot');
        }

        return $initFrame->scope[$resultSlot]->resolveIndirect();
    }

    public function isClassBodyConstInitOpcode(int $type): bool
    {
        return $this->isClassBodyDefaultInitOpcode($type)
            || OpCode::TYPE_NEW === $type
            || OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $type
            || OpCode::TYPE_FUNCCALL_EXEC_RETURN === $type
            || OpCode::TYPE_CLOSURE === $type
            || OpCode::TYPE_FROM_CALLABLE === $type;
    }

    private function isClassBodyDefaultInitOpcode(int $type): bool
    {
        return OpCode::TYPE_INIT_ARRAY === $type
            || OpCode::TYPE_ADD_ARRAY_ELEMENT === $type
            || OpCode::TYPE_ARRAY_SPREAD === $type;
    }

    /**
     * INIT_ARRAY (etc.) emitted before property/class-const `new` defaults — defer to the pending fragment (#5362).
     *
     * @param list<OpCode> $classBodyOps
     */
    private function opcodePrecedesPropertyDefaultNew(array $classBodyOps, int $index): bool
    {
        $count = \count($classBodyOps);
        for ($i = $index + 1; $i < $count; ++$i) {
            $type = $classBodyOps[$i]->type;
            if (OpCode::TYPE_NEW === $type) {
                return true;
            }
            if (
                OpCode::TYPE_DECLARE_PROPERTY === $type
                || OpCode::TYPE_DECLARE_STATIC_PROPERTY === $type
                || OpCode::TYPE_DECLARE_CLASS_CONST === $type
            ) {
                return false;
            }
            if (!$this->isClassBodyDefaultInitOpcode($type)) {
                return false;
            }
        }

        return false;
    }

    /**
     * @param list<OpCode> $classBodyOps
     * @return list<OpCode>
     */
    private function collectPropertyDefaultNewPreludeOps(array $classBodyOps, int $newIndex): array
    {
        $prelude = [];
        for ($i = $newIndex - 1; $i >= 0; --$i) {
            if (!$this->isClassBodyDefaultInitOpcode($classBodyOps[$i]->type)) {
                break;
            }
            array_unshift($prelude, $classBodyOps[$i]);
        }

        return $prelude;
    }

    /**
     * @return array<string, true>
     */
    private function classBodyOwnMethodNames(Block $block, Frame $frame): array
    {
        $methods = [];
        foreach ($block->opCodes as $op) {
            if (OpCode::TYPE_DECLARE_METHOD !== $op->type) {
                continue;
            }
            if (null === $op->block1) {
                continue;
            }
            $methods[strtolower($frame->scope[$op->arg1]->toString())] = true;
        }

        return $methods;
    }

    public function executeClassBodyConstInitOpcode(Frame $frame, OpCode $op): void
    {
        if ($this->isClassBodyDefaultInitOpcode($op->type)) {
            $this->executeClassBodyDefaultInitOpcode($frame, $op);

            return;
        }
        switch ($op->type) {
            case OpCode::TYPE_NEW:
                $result = $frame->scope[$op->arg1];
                $name = $frame->scope[$op->arg2]->toString();
                $lcname = strtolower($name);
                if (!isset($this->context->classes[$lcname])) {
                    $this->context->autoloadClass($name);
                }
                if (!isset($this->context->classes[$lcname])) {
                    throw new \Error($this->classNotFoundMessage($name));
                }
                $class = $this->context->classes[$lcname];
                VM\ReservedBuiltinClass::assertUserInstantiable($class);
                if ($class->isEnum) {
                    throw new \Error("Cannot instantiate enum {$class->name}");
                }
                if ($class->isInterface) {
                    throw new \Error("Cannot instantiate interface {$class->name}");
                }
                if ($class->isTrait) {
                    throw new \Error("Cannot instantiate trait {$class->name}");
                }
                if ($class->isAbstract) {
                    throw new \Error("Cannot instantiate abstract class {$class->name}");
                }
                VM\ClassValidator::assertInstantiable($class);
                $this->enforceNewConstructorVisibility($class, $frame);
                $this->emitClassInstantiationDeprecation($class, $frame);
                $object = new VM\ObjectEntry($class);
                $result->object($object);
                $frame->call = $object->constructor;
                $frame->callArgs = [$result];
                $frame->callArgEntries = [];
                if (null === $frame->call) {
                    $object->constructed = true;
                }
                break;
            case OpCode::TYPE_FUNCCALL_EXEC_RETURN:
            case OpCode::TYPE_FUNCCALL_EXEC_NORETURN:
                if (is_null($frame->call)) {
                    $this->markPendingNewObjectConstructed($frame);
                    break;
                }
                if ($frame->call instanceof Func\PHP && $frame->call->block->isGenerator) {
                    throw new \LogicException('Generator constructors are not allowed in class constants');
                }
                $new = $frame->call->getFrame($this->context, $frame);
                $new->calledClass = $this->inferCalledClass($frame);
                $new->returnVar = null;
                try {
                    $new->calledArgs = $this->resolveOutgoingCallArgs($frame);
                } catch (\Error $e) {
                    throw $e;
                } catch (\LogicException $e) {
                    throw new \LogicException($e->getMessage(), 0, $e);
                }
                $frame->call = null;
                $this->clearOutgoingCallState($frame);
                $new->parent = $frame;
                $new->vmContext = $this->context;
                $new->ephemeral = true;
                $this->context->push($frame);
                $this->context->push($new);
                $result = $this->runFrames();
                if (self::SUCCESS !== $result) {
                    throw new \LogicException('Class constant constructor failed');
                }
                break;
            case OpCode::TYPE_FROM_CALLABLE:
                // Closures / FCC in class const exprs (#26240, fcc_in_const_expr).
                if (isset($frame->scope[$op->arg2])) {
                    $callable = $frame->scope[$op->arg2]->resolveIndirect();
                } elseif (isset($frame->block->constants[$op->arg2])) {
                    $callable = $frame->block->constants[$op->arg2];
                } else {
                    throw new \LogicException('TYPE_FROM_CALLABLE missing callable slot');
                }
                $entry = VM\ClosureSupport::fromCallable(
                    $this->context,
                    $frame,
                    $callable,
                    $op->fromCallableScope,
                    $op->fromCallableApi
                );
                $frame->scope[$op->arg1]->object($entry);
                break;
            case OpCode::TYPE_CLOSURE:
                // Static Closures in class const exprs (#26240, closures_in_const_expr).
                if (null === $op->block1) {
                    $frame->scope[$op->arg1]->null();
                    break;
                }
                $funcName = null !== $op->block1->func
                    ? $op->block1->func->name
                    : '{closure}';
                $closureFunc = new Func\PHP($funcName, $op->block1);
                $closureFunc->sourceLocation = $op->sourceLocation;
                if ([] !== $op->parameterMetadata) {
                    $closureFunc->parameterMetadata = $op->parameterMetadata;
                }
                if ([] !== $op->attributeNames) {
                    $closureFunc->attributeNames = $op->attributeNames;
                }
                if ([] !== $op->attributeEntries) {
                    $closureFunc->attributeEntries = $op->attributeEntries;
                }
                $captures = $this->bindClosureCaptures($frame, $op->closureCaptures);
                $state = new ClosureState($closureFunc, $captures);
                $state->applyDefinitionSite($op->sourceLocation, $op->block1);
                if (
                    null !== $frame->block->func
                    && null !== $frame->block->func->class
                    && null !== $frame->block->func->class->value
                    && '' !== $frame->block->func->class->value
                ) {
                    $declaring = $frame->block->func->class->value;
                    if (null !== $op->block1->func) {
                        $op->block1->func->class = $frame->block->func->class;
                    }
                    $state->boundScopeClass = $declaring;
                    $called = $this->inferCalledClass($frame);
                    if (null !== $called && '' !== $called) {
                        $state->boundCalledScopeClass = $called;
                    }
                }
                $frame->scope[$op->arg1]->object($state->wrapObject($this->context));
                break;
            default:
                throw new \LogicException(
                    'Unexpected class constant init opcode: '.opcode_type_name($op->type)
                );
        }
    }

    private function executeClassBodyDefaultInitOpcode(Frame $frame, OpCode $op): void
    {
        switch ($op->type) {
            case OpCode::TYPE_INIT_ARRAY:
                $result = $frame->scope[$op->arg1];
                $result->newArray();
                if (is_null($op->arg2)) {
                    break;
                }
                // Fall through intentional
            case OpCode::TYPE_ADD_ARRAY_ELEMENT:
                $result = $frame->scope[$op->arg1];
                $ht = $result->toArray();
                if (is_null($op->arg3)) {
                    $ht->append($this->resolveOutgoingCallArgValue($frame, $op->arg2));

                    break;
                }
                $key = $this->resolveOutgoingCallArgValue($frame, $op->arg3)->resolveIndirect();
                $value = $this->resolveOutgoingCallArgValue($frame, $op->arg2);
                // Class-body array defaults: same typed offset TypeError as runtime literals (#28628).
                // Resource keys warn+cast (#29550).
                $key = VM\HashTable::normalizeIndexKeyForWrite($key, $this->context, $frame);
                $storeIndirect = $value->isIndirect();
                if ($key->is(Variable::TYPE_INTEGER) || $key->is(Variable::TYPE_FLOAT)) {
                    $intKey = $key->is(Variable::TYPE_FLOAT)
                        ? \PHPCompiler\ext\standard\VmMath::floatToZendLong($key->toFloat())
                        : $key->toInt();
                    $storeIndirect
                        ? $ht->updateIndirectIndex($intKey, $value)
                        : $ht->updateIndex($intKey, $value);
                } elseif ($key->is(Variable::TYPE_STRING)) {
                    $storeIndirect
                        ? $ht->updateIndirect($key->toString(), $value)
                        : $ht->update($key->toString(), $value);
                } else {
                    throw new \TypeError(VM\EnumCaseSupport::illegalArrayOffsetMessage($key));
                }
                break;
            case OpCode::TYPE_ARRAY_SPREAD:
                $result = $frame->scope[$op->arg1];
                $source = $frame->scope[$op->arg2];
                VM\ArraySpread::spreadInto(
                    $this,
                    $frame,
                    $result->toArray(),
                    $source,
                    (int) ($op->arg3 ?? 0)
                );
                break;
            default:
                throw new \LogicException(
                    'Unexpected class body init opcode: '.opcode_type_name($op->type)
                );
        }
    }

    private function functionReturnsByRef(Frame $frame): bool
    {
        $func = $frame->block->func ?? null;

        return null !== $func
            && (($func->flags ?? 0) & \PHPCfg\Func::FLAG_RETURNS_REF) !== 0;
    }

    protected function instanceMethodReturnsByRef(ObjectEntry $object, string $methodName): bool
    {
        $methodLc = strtolower($methodName);
        if (!$this->hasInstanceMethod($object->class, $methodLc)) {
            return false;
        }
        [$declaring] = $this->resolveInstanceMethod($object->class, $methodLc);
        $func = $declaring->methods[$methodLc];
        if (!$func instanceof Func\PHP) {
            return false;
        }
        $decl = $func->block->func;

        return null !== $decl
            && (($decl->flags ?? 0) & \PHPCfg\Func::FLAG_RETURNS_REF) !== 0;
    }

    private function resolveVmReturnValue(Frame $frame, OpCode $op): Variable
    {
        $slot = $op->arg1;
        if (null === $slot) {
            return new Variable(Variable::TYPE_NULL);
        }
        if (isset($frame->scope[$slot])) {
            $scopeVar = $frame->scope[$slot];
            VM\TypedPropertyCheck::assertReadable($scopeVar);
            $resolved = $scopeVar->resolveIndirect();
            if (!$resolved->isUndefined()) {
                return $resolved;
            }
        }
        $operand = $frame->block->getOperand($slot);
        if ($operand instanceof \PHPCfg\Operand\Literal && isset($frame->block->constants[$slot])) {
            return $frame->block->constants[$slot];
        }
        if (isset($frame->block->constants[$slot])) {
            return $frame->block->constants[$slot];
        }

        return new Variable(Variable::TYPE_NULL);
    }

    private function enforceReturnType(Frame $frame, ?Variable $value): void
    {
        if ($this->context->suppressReturnTypeCheckDepth > 0) {
            return;
        }
        $block = $frame->block;
        if (null === $block) {
            return;
        }
        if ($block->returnTypeNever) {
            $funcName = null;
            if (null !== $block->func) {
                $funcName = $block->func->name;
            }
            TypeCheck::assertNeverReturn($funcName);

            return;
        }
        if ($block->returnTypeVoid) {
            TypeCheck::assertVoidReturn($value);

            return;
        }
        if (null === $value && $this->declaredReturnTypeRequiresValue($block)) {
            $expected = TypeCheck::expectedReturnTypeLabelForNoneReturned($block);
            // Zend resolves `: static` to the late-bound class in the TypeError (#26486).
            if ($block->returnTypeStatic) {
                $lc = $this->lateStaticClassLc($frame);
                if (isset($this->context->classes[$lc])) {
                    $expected = $this->context->classes[$lc]->name;
                }
            }
            TypeCheck::assertNoneReturned(
                $this->returnTypeCallableName($block->func),
                $expected
            );
        }
        if ($block->returnTypeStatic) {
            TypeCheck::assertStaticReturn(
                $value,
                $this->lateStaticClassLc($frame),
                $this->context
            );

            return;
        }
        if (null !== $block->returnDnfConstraints && null !== $value) {
            DnfCheck::assertMatches(
                $value,
                $block->returnDnfConstraints,
                $this->context,
                'Return value',
                null,
                $block->strictTypes,
                $this->returnTypeCallableName($block->func)
            );

            return;
        }
        if (null !== $block->returnClassConstraint && null !== $value) {
            // Wrapper return types apply at invoke time, not getReturn() (#16141, #26468).
            if ($this->generatorHasTraversableReturnTypeLabel($block)) {
                return;
            }
            TypeCheck::assertObjectReturn(
                $value,
                $block->returnClassConstraint,
                $block->returnDeclaredTypeLabel ?? $block->returnClassConstraint,
                $this->returnTypeCallableName($block->func)
            );

            return;
        }
        // `: Generator`/`: Iterator`/`: Traversable`/`: iterable`/`: object` apply at call time
        // (wrap object), not on generator body completion / getReturn() (#16141, #26468).
        if ($this->generatorHasTraversableReturnTypeLabel($block)) {
            return;
        }
        if (null === $block->returnTypeConstraint) {
            return;
        }
        // Return type checks use the declaring function's strict_types (zend_verify_return_type).
        TypeCheck::coerceReturn(
            $value,
            $block->strictTypes,
            $block->returnTypeConstraint,
            $block->returnLiteralBoolType,
            $this->returnTypeCallableName($block->func)
        );
    }

    private function generatorHasTraversableReturnTypeLabel(Block $block): bool
    {
        if (!$block->isGenerator || null === $block->returnClassConstraint) {
            return false;
        }
        $returnLabel = ltrim($block->returnDeclaredTypeLabel ?? $block->returnClassConstraint, '\\');

        // Zend: these declare the Generator wrapper at invoke, not getReturn() (#16141, #26468).
        return in_array($returnLabel, ['Generator', 'Iterator', 'Traversable', 'iterable', 'object'], true);
    }

    private function declaredReturnTypeRequiresValue(Block $block): bool
    {
        if ($block->returnTypeMixed) {
            return true;
        }
        if ($block->returnTypeStatic) {
            return true;
        }
        if (null !== $block->returnDnfConstraints) {
            return true;
        }
        if (null !== $block->returnClassConstraint) {
            if ($this->generatorHasTraversableReturnTypeLabel($block)) {
                return false;
            }

            return true;
        }
        if (null !== $block->returnTypeConstraint) {
            return true;
        }

        return false;
    }

    private function returnTypeCallableName(?\PHPCfg\Func $func): ?string
    {
        if (null === $func) {
            return null;
        }
        if (null !== $func->class) {
            $className = $func->class->value ?? null;
            if (is_string($className) && '' !== $className) {
                return SourcePreprocessor\PropertyHooks::zendTypeErrorCallableName(
                    $className.'::'.$func->name
                );
            }
        }

        // Zend TypeError prefixes use `{closure}` for anonymous funcs (#26486).
        if (is_string($func->name) && preg_match('/^\{anonymous\}#\d+$/', $func->name)) {
            return '{closure}';
        }

        return is_string($func->name)
            ? SourcePreprocessor\PropertyHooks::zendTypeErrorCallableName($func->name)
            : $func->name;
    }

    private function emitCallDeprecationNotice(Frame $frame): void
    {
        if (null === $frame->call || !($frame->call instanceof Func\PHP)) {
            return;
        }
        $meta = $frame->call->deprecated;
        if (null === $meta) {
            return;
        }
        $name = $frame->call->getName();
        // Property-hook methods: Zend message is Class::$prop::get/set (#26370).
        $hookMessage = $this->formatPropertyHookDeprecationMessage($meta, $name, null);
        if (null !== $hookMessage) {
            $this->emitDeprecatedNotice($hookMessage, $frame);

            return;
        }
        // Bare #[\Deprecated] emits too (rfc:deprecated_attribute / #27825).
        if (!$meta->emitsRuntimeNotice()) {
            return;
        }
        if (str_contains($name, '::')) {
            [$class, $method] = explode('::', $name, 2);
            $message = $meta->formatMethod($class, $method);
        } else {
            $message = $meta->formatFunction($name);
        }
        $this->emitDeprecatedNotice($message, $frame);
    }

    /**
     * #[\Deprecated] on property get/set hooks — Zend Method Class::$prop::get/set() (#26370).
     *
     * Hook dispatch bypasses FUNCCALL_EXEC ({@see invokePhpFunctionWithPropertyHookRaw}).
     */
    private function emitPropertyHookDeprecationNotice(
        Func\PHP $func,
        string $rawProperty,
        Frame $frame
    ): void {
        $meta = $func->deprecated;
        if (null === $meta) {
            return;
        }
        $message = $this->formatPropertyHookDeprecationMessage($meta, $func->getName(), $rawProperty);
        if (null === $message) {
            return;
        }
        $this->emitDeprecatedNotice($message, $frame);
    }

    /**
     * @return ?string Zend-shaped deprecation, or null when $name is not a property-hook method
     */
    private function formatPropertyHookDeprecationMessage(
        \PHPCompiler\Compiler\DeprecatedMetadata $meta,
        string $qualifiedName,
        ?string $rawProperty
    ): ?string {
        $methodPart = $qualifiedName;
        $class = '';
        if (str_contains($qualifiedName, '::')) {
            [$class, $methodPart] = explode('::', $qualifiedName, 2);
        }
        $methodLc = strtolower($methodPart);
        $prop = SourcePreprocessor\PropertyHooks::propertyNameFromGetHookMethod($methodLc);
        $hook = 'get';
        if (null === $prop) {
            $prop = SourcePreprocessor\PropertyHooks::propertyNameFromSetHookMethod($methodLc);
            $hook = 'set';
        }
        if (null === $prop) {
            return null;
        }
        if (is_string($rawProperty) && '' !== $rawProperty) {
            $prop = $rawProperty;
        }
        if ('' === $class) {
            $class = 'unknown';
        }

        return $meta->formatPropertyHook($class, $prop, $hook);
    }

    private function emitCallNoDiscardNotice(Frame $frame, OpCode $op): void
    {
        if (!CompilerVersion::supportsNoDiscardAttribute()) {
            return;
        }
        if (OpCode::TYPE_FUNCCALL_EXEC_NORETURN !== $op->type) {
            return;
        }
        if (null === $frame->call || !($frame->call instanceof Func\PHP)) {
            return;
        }
        if (!$frame->call->block->noDiscard) {
            return;
        }
        $meta = new NoDiscardMetadata($frame->call->block->noDiscardMessage);
        $name = $frame->call->getName();
        if (str_contains($name, '::')) {
            [$class, $method] = explode('::', $name, 2);
            $message = $meta->formatMethod($class, $method);
        } else {
            $message = $meta->formatFunction($name);
        }
        $line = (int) ($op->arg1 ?? 0);
        $this->context->errors->triggerError(
            $message,
            VM\ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $this->context,
            $frame,
            $line > 0 ? $line : 0
        );
    }

    private function emitDeprecatedNotice(string $message, Frame $frame): void
    {
        if (!CompilerVersion::supportsDeprecatedAttributeRuntimeNotices()) {
            return;
        }
        $this->context->errors->triggerError(
            $message,
            ErrorReporter::E_USER_DEPRECATED,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $this->context,
            $frame
        );
    }

    private function emitClassInstantiationDeprecation(ClassEntry $class, Frame $frame): void
    {
        if (null === $class->classDeprecated || !$class->classDeprecated->emitsRuntimeNotice()) {
            return;
        }
        $this->emitDeprecatedNotice($class->classDeprecated->formatClass($class->name), $frame);
    }

    /**
     * PHP 8.5+ #[\Deprecated] on traits — notice when the trait is directly `use`d (#22989).
     *
     * Bare `#[\Deprecated]` (no message/since) still emits (rfc:deprecated_traits); children that
     * inherit a class using the trait do not re-emit unless they `use` it again.
     */
    private function emitTraitUseDeprecation(ClassEntry $trait, ClassEntry $user, ?Frame $frame = null): void
    {
        if (!CompilerVersion::supportsDeprecatedTraitAttribute()) {
            return;
        }
        $meta = $trait->classDeprecated;
        if (null === $meta) {
            return;
        }
        $message = $meta->formatTraitUse($trait->name, $user->name);
        $file = $user->sourceLocation?->filename;
        $line = $user->sourceLocation?->startLine ?? 0;
        if ((null === $file || '' === $file) && null !== $frame && '' !== $frame->scriptPath) {
            $file = $frame->scriptPath;
        }
        $this->context->errors->triggerError(
            $message,
            ErrorReporter::E_USER_DEPRECATED,
            (null !== $file && '' !== $file) ? $file : null,
            $this->context,
            $frame,
            $line > 0 ? $line : 0
        );
    }

    private function emitGlobalConstFetchDeprecation(string $constName, Frame $frame): void
    {
        $meta = $this->context->globalConstDeprecated[strtolower($constName)] ?? null;
        if (null === $meta || !$meta->emitsRuntimeNotice()) {
            return;
        }
        $this->emitDeprecatedNotice($meta->formatGlobalConstant($constName), $frame);
    }

    private function emitClassConstFetchDeprecation(
        ClassEntry $classEntry,
        string $memberNameRaw,
        string $memberLc,
        Frame $frame
    ): void {
        if ($classEntry->isEnum) {
            if (null !== $classEntry->classDeprecated && $classEntry->classDeprecated->emitsRuntimeNotice()) {
                $this->emitDeprecatedNotice(
                    $classEntry->classDeprecated->formatEnum($classEntry->name),
                    $frame
                );
            }
            if (isset($classEntry->constDeprecated[$memberLc])) {
                $meta = $classEntry->constDeprecated[$memberLc];
                if ($meta->emitsRuntimeNotice()) {
                    $this->emitDeprecatedNotice(
                        $meta->formatEnumCase($classEntry->name, $memberNameRaw),
                        $frame
                    );
                }
            }

            return;
        }
        if (isset($classEntry->constDeprecated[$memberLc])) {
            $meta = $classEntry->constDeprecated[$memberLc];
            if ($meta->emitsRuntimeNotice()) {
                // Zend cites the declaring class/interface (A::X / I::X), not the fetch class (#29380).
                $this->emitDeprecatedNotice(
                    $meta->formatConstant(
                        $this->classConstDeprecatedOwnerDisplay($classEntry, $memberLc),
                        $memberNameRaw
                    ),
                    $frame
                );
            }
        }
    }

    /** Declaring class/interface display name for class-const #[\Deprecated] notices (#29380). */
    private function classConstDeprecatedOwnerDisplay(ClassEntry $classEntry, string $memberLc): string
    {
        $declLc = $classEntry->constDeclaringClassLc[$memberLc] ?? null;
        if (null !== $declLc && isset($this->context->classes[$declLc])) {
            return $this->context->classes[$declLc]->name;
        }

        return $classEntry->name;
    }

    private function emitInstancePropertyAccessDeprecation(
        ObjectEntry $object,
        string $propName,
        Frame $frame
    ): void {
        $meta = $this->classPropertyMeta($object, $propName);
        if (null === $meta) {
            return;
        }
        $declLc = '' !== $meta->declaringClassLc
            ? $meta->declaringClassLc
            : strtolower($object->class->name);
        if (!isset($this->context->classes[$declLc])) {
            return;
        }
        $declEntry = $this->context->classes[$declLc];
        $propLc = strtolower($propName);
        if (!isset($declEntry->propDeprecated[$propLc])) {
            return;
        }
        // Property targets: attribute presence is enough (bare #[\Deprecated] still emits —
        // Zend zend_object_handlers.c / #23536; same rule as call sites #27825).
        $meta = $declEntry->propDeprecated[$propLc];
        $this->emitDeprecatedNotice(
            $meta->formatProperty($declEntry->name, $propName),
            $frame
        );
    }

    private function emitStaticPropertyAccessDeprecation(
        string $classLc,
        string $propNameRaw,
        Frame $frame
    ): void {
        $meta = $this->resolveStaticPropertyVisibilityMeta($classLc, strtolower($propNameRaw));
        if (null === $meta) {
            return;
        }
        $declLc = $meta['declaringClassLc'];
        if (!isset($this->context->classes[$declLc])) {
            return;
        }
        $declEntry = $this->context->classes[$declLc];
        $propLc = strtolower($propNameRaw);
        if (!isset($declEntry->propDeprecated[$propLc])) {
            return;
        }
        $depMeta = $declEntry->propDeprecated[$propLc];
        $this->emitDeprecatedNotice(
            $depMeta->formatProperty($meta['declaringClassDisplay'], $propNameRaw),
            $frame
        );
    }

    private function emitPropertyWriteDeprecation(Variable $lvalue, Frame $frame): void
    {
        $target = $lvalue->resolveIndirect();
        if (null !== $target->objectPropertyOwner && null !== $target->objectPropertyName) {
            $this->emitInstancePropertyAccessDeprecation(
                $target->objectPropertyOwner,
                $target->objectPropertyName,
                $frame
            );

            return;
        }
        $classLc = $target->staticPropertyClassLc ?? $lvalue->staticPropertyClassLc;
        $propName = $target->objectPropertyName ?? $lvalue->objectPropertyName;
        if (is_string($classLc) && is_string($propName) && '' !== $propName) {
            $this->emitStaticPropertyAccessDeprecation($classLc, $propName, $frame);
        }
    }

    /**
     * ClassConstFetch with a runtime member name (php-parser: Class::{$var}).
     * Zend resolves constants first; when no constant exists, fall back to static property (#3788).
     */
    private function classConstValuesIdentical(Variable $left, Variable $right): bool
    {
        $a = new Variable();
        $a->copyFrom($left);
        $b = new Variable();
        $b->copyFrom($right);

        return $a->identicalTo($b);
    }

    /**
     * Child class/interface body must not redeclare a final ancestor constant
     * (Zend/zend_inheritance.c; #4455 compile-time, #22329 eval/runtime).
     */
    private function rejectChildOverrideOfFinalClassConst(
        ClassEntry $entry,
        ClassEntry $ancestor,
        string $nameLc
    ): void {
        if (!isset($ancestor->constFinal[$nameLc])) {
            return;
        }
        $childDisplay = $entry->constNames[$nameLc]
            ?? $ancestor->constNames[$nameLc]
            ?? $nameLc;
        $constDisplay = $ancestor->constNames[$nameLc] ?? $childDisplay;
        $declaringLc = $ancestor->constDeclaringClassLc[$nameLc]
            ?? strtolower(ltrim($ancestor->name, '\\'));
        $ownerDisplay = $ancestor->name;
        if (isset($this->context->classes[$declaringLc])) {
            $ownerDisplay = $this->context->classes[$declaringLc]->name;
        }
        throw new \CompileError(sprintf(
            '%s::%s cannot override final constant %s::%s',
            $entry->name,
            $childDisplay,
            $ownerDisplay,
            $constDisplay
        ));
    }

    /**
     * php-src zend_inheritance.c — "Cannot override final method %s::%s()" (#24884, #4263).
     * Same-script compile is FinalMethodOverrideCheck; cross-eval/include hits this path.
     */
    private function rejectChildOverrideOfFinalMethod(
        ClassEntry $entry,
        ClassEntry $parent,
        string $methodLc
    ): void {
        $vis = $parent->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
        if (($vis & \PHPCfg\Func::FLAG_FINAL) === 0) {
            return;
        }
        $methodDisplay = $entry->methodNames[$methodLc]
            ?? $parent->methodNames[$methodLc]
            ?? $methodLc;
        $declaringLc = $parent->methodDeclaringClassLc[$methodLc]
            ?? strtolower(ltrim($parent->name, '\\'));
        $ownerDisplay = $parent->name;
        if (isset($this->context->classes[$declaringLc])) {
            $ownerDisplay = $this->context->classes[$declaringLc]->name;
        }
        throw new \CompileError(sprintf(
            'Cannot override final method %s::%s()',
            $ownerDisplay,
            $methodDisplay
        ));
    }

    /**
     * php-src zend_inheritance.c method compatibility — cross-file / eval / include (#25384).
     * Same-script compile is {@see Compiler\InheritanceVariance}; this path sees the live ClassEntry.
     */
    private function rejectIncompatibleChildMethodSignature(
        ClassEntry $entry,
        ClassEntry $parent,
        string $methodLc
    ): void {
        $childSig = Compiler\MethodSig::fromClassEntry($entry, $methodLc);
        $parentSig = $this->resolveAncestorMethodSig($parent, $methodLc);
        if (null === $childSig || null === $parentSig) {
            return;
        }
        $msg = Compiler\InheritanceVariance::methodCompatibilityError(
            $entry->name,
            $methodLc,
            $childSig,
            $parent->name,
            $parentSig,
            fn (string $subtype, string $supertype): bool => $this->isClassSubtypeOfDuringDeclare(
                $subtype,
                $supertype,
                $entry
            ),
            fn (string $classLc, string $interfaceLc): bool => $this->classEntryImplementsInterfaceDuringDeclare(
                $classLc,
                $interfaceLc,
                $entry
            )
        );
        if (null !== $msg) {
            throw new \CompileError($msg);
        }
    }

    /**
     * Walk parent/interface chain for a MethodSig (abstract methods keep types on the declarer).
     */
    private function resolveAncestorMethodSig(ClassEntry $from, string $methodLc): ?Compiler\MethodSig
    {
        $current = $from;
        $guard = 0;
        while ($guard++ < 256) {
            $sig = Compiler\MethodSig::fromClassEntry($current, $methodLc);
            if (null !== $sig) {
                return $sig;
            }
            $declLc = $current->methodDeclaringClassLc[$methodLc] ?? null;
            if (null !== $declLc && isset($this->context->classes[$declLc])) {
                $decl = $this->context->classes[$declLc];
                if ($decl !== $current) {
                    $sig = Compiler\MethodSig::fromClassEntry($decl, $methodLc);
                    if (null !== $sig) {
                        return $sig;
                    }
                }
            }
            if (null === $current->parentLc || !isset($this->context->classes[$current->parentLc])) {
                break;
            }
            $current = $this->context->classes[$current->parentLc];
        }

        return null;
    }

    private function classEntryImplementsInterface(string $classLc, string $interfaceLc): bool
    {
        if ($classLc === $interfaceLc) {
            return true;
        }
        if (!isset($this->context->classes[$classLc])) {
            return false;
        }
        $entry = $this->context->classes[$classLc];
        foreach ($entry->interfaces as $ifaceLc) {
            if ($this->interfaceExtendsOrEquals($ifaceLc, $interfaceLc)) {
                return true;
            }
        }
        if (null !== $entry->parentLc) {
            return $this->classEntryImplementsInterface($entry->parentLc, $interfaceLc);
        }

        return false;
    }

    /**
     * Like {@see isSubclassOf()} but the class under TYPE_DECLARE_CLASS is not in
     * context.classes until after inheritFromParent (#25384 self/static covariance).
     */
    private function isClassSubtypeOfDuringDeclare(
        string $subtypeLc,
        string $supertypeLc,
        ClassEntry $defining
    ): bool {
        if ($subtypeLc === $supertypeLc) {
            return true;
        }
        $definingLc = strtolower(ltrim($defining->name, '\\'));
        if ($subtypeLc === $definingLc) {
            if ($defining->parentLc === $supertypeLc) {
                return true;
            }
            if (null !== $defining->parentLc) {
                return $this->isSubclassOf($defining->parentLc, $supertypeLc)
                    || $defining->parentLc === $supertypeLc;
            }

            return false;
        }

        return $this->isSubclassOf($subtypeLc, $supertypeLc);
    }

    private function classEntryImplementsInterfaceDuringDeclare(
        string $classLc,
        string $interfaceLc,
        ClassEntry $defining
    ): bool {
        if ($classLc === $interfaceLc) {
            return true;
        }
        $definingLc = strtolower(ltrim($defining->name, '\\'));
        if ($classLc === $definingLc) {
            foreach ($defining->interfaces as $ifaceLc) {
                if ($this->interfaceExtendsOrEquals($ifaceLc, $interfaceLc)) {
                    return true;
                }
            }
            if (null !== $defining->parentLc) {
                return $this->classEntryImplementsInterface($defining->parentLc, $interfaceLc);
            }

            return false;
        }

        return $this->classEntryImplementsInterface($classLc, $interfaceLc);
    }

    private function interfaceExtendsOrEquals(string $ifaceLc, string $targetLc): bool
    {
        if ($ifaceLc === $targetLc) {
            return true;
        }
        if (!isset($this->context->classes[$ifaceLc])) {
            return false;
        }
        $iface = $this->context->classes[$ifaceLc];
        foreach ($iface->interfaces as $parentIface) {
            if ($this->interfaceExtendsOrEquals($parentIface, $targetLc)) {
                return true;
            }
        }

        return false;
    }

    /**
     * php-src zend_inheritance.c — "Cannot override final property %s::$%s" (#22988, #22339).
     */
    private function rejectChildOverrideOfFinalProperty(
        ClassEntry $entry,
        VM\ClassProperty $parentProperty
    ): void {
        if (!$parentProperty->propertyFinal) {
            return;
        }
        $declaringLc = $parentProperty->declaringClassLc;
        $ownerDisplay = $declaringLc !== '' ? $declaringLc : $entry->parentLc;
        if (is_string($ownerDisplay) && isset($this->context->classes[$ownerDisplay])) {
            $ownerDisplay = $this->context->classes[$ownerDisplay]->name;
        }
        if (!is_string($ownerDisplay) || '' === $ownerDisplay) {
            $ownerDisplay = 'parent';
        }
        throw new \CompileError(sprintf(
            'Cannot override final property %s::$%s',
            $ownerDisplay,
            $parentProperty->name
        ));
    }

    /**
     * php-src zend_inheritance.c — final static property override (#24992, #23403).
     * Mirror of {@see rejectChildOverrideOfFinalProperty()} for ClassEntry::$staticPropertyFinal.
     */
    private function rejectChildOverrideOfFinalStaticProperty(
        ClassEntry $entry,
        ClassEntry $parent,
        string $propLc
    ): void {
        $declaringLc = $parent->staticPropertyDeclaringClassLc[$propLc]
            ?? strtolower(ltrim($parent->name, '\\'));
        $ownerDisplay = $declaringLc;
        if (isset($this->context->classes[$ownerDisplay])) {
            $ownerDisplay = $this->context->classes[$ownerDisplay]->name;
        }
        if ('' === $ownerDisplay) {
            $ownerDisplay = $parent->name !== '' ? $parent->name : 'parent';
        }
        $storage = $parent->staticProperties[$propLc] ?? null;
        $propDisplay = ($storage instanceof Variable && null !== $storage->objectPropertyName)
            ? $storage->objectPropertyName
            : $propLc;
        throw new \CompileError(sprintf(
            'Cannot override final property %s::$%s',
            $ownerDisplay,
            $propDisplay
        ));
    }

    /**
     * php-src zend_inheritance.c — property type invariance (#23505).
     * Same-script compile is covered by TypedPropertyInheritCheck; cross-eval needs this path.
     */
    private function rejectIncompatibleChildPropertyType(
        ClassEntry $entry,
        VM\ClassProperty $parentProperty,
        VM\ClassProperty $childProperty
    ): void {
        $parentOwnerLc = $parentProperty->declaringClassLc !== ''
            ? $parentProperty->declaringClassLc
            : (string) ($entry->parentLc ?? '');
        $childOwnerLc = $childProperty->declaringClassLc !== ''
            ? $childProperty->declaringClassLc
            : strtolower(ltrim($entry->name, '\\'));
        if ($this->propertyTypesAreInvariant($parentProperty, $childProperty, $parentOwnerLc, $childOwnerLc)) {
            return;
        }
        $ownerDisplay = $parentOwnerLc;
        if (isset($this->context->classes[$ownerDisplay])) {
            $ownerDisplay = $this->context->classes[$ownerDisplay]->name;
        }
        if ('' === $ownerDisplay) {
            $ownerDisplay = 'parent';
        }
        if (!$parentProperty->hasDeclaredType() && $childProperty->hasDeclaredType()) {
            throw new \CompileError(sprintf(
                'Type of %s::$%s must not be defined (as in class %s)',
                $entry->name,
                $childProperty->name,
                $ownerDisplay
            ));
        }
        throw new \CompileError(sprintf(
            'Type of %s::$%s must be %s (as in class %s)',
            $entry->name,
            $childProperty->name,
            $this->formatPropertyTypeForInheritError($parentProperty),
            $ownerDisplay
        ));
    }

    private function propertyTypesAreInvariant(
        VM\ClassProperty $parent,
        VM\ClassProperty $child,
        string $parentOwnerLc,
        string $childOwnerLc
    ): bool {
        $parentTyped = $parent->hasDeclaredType();
        $childTyped = $child->hasDeclaredType();
        if (!$parentTyped && !$childTyped) {
            return true;
        }
        if (!$parentTyped || !$childTyped) {
            return false;
        }
        $parentKey = $this->propertyTypeInvariantKey($parent, $parentOwnerLc);
        $childKey = $this->propertyTypeInvariantKey($child, $childOwnerLc);
        if ($parentKey === $childKey) {
            return true;
        }

        return $this->propertyTypeResolvedKey($parent, $parentOwnerLc)
            === $this->propertyTypeResolvedKey($child, $childOwnerLc);
    }

    private function propertyTypeInvariantKey(VM\ClassProperty $property, string $ownerLc): string
    {
        $proto = $property->prototype;
        $label = strtolower((string) ($proto->declaredTypeLabel ?? ''));
        if ('' !== $label) {
            return $label;
        }
        $class = strtolower((string) ($proto->classConstraint ?? ''));
        if ('' !== $class) {
            return $class;
        }
        if (null !== $proto->typeConstraint) {
            return 'tc:'.(string) $proto->typeConstraint;
        }
        // Explicit mixed: typed UNDEFINED without label/constraint.
        if (Variable::TYPE_UNDEFINED === $proto->type) {
            return 'mixed';
        }

        return 'typed';
    }

    private function propertyTypeResolvedKey(VM\ClassProperty $property, string $ownerLc): string
    {
        $key = $this->propertyTypeInvariantKey($property, $ownerLc);
        if ('self' === $key || 'static' === $key) {
            return strtolower($ownerLc);
        }

        return $key;
    }

    private function formatPropertyTypeForInheritError(VM\ClassProperty $property): string
    {
        $proto = $property->prototype;
        $label = (string) ($proto->declaredTypeLabel ?? '');
        if ('' !== $label) {
            return $label;
        }
        $class = (string) ($proto->classConstraint ?? '');
        if ('' !== $class) {
            return $class;
        }
        if (Variable::TYPE_UNDEFINED === $proto->type) {
            return 'mixed';
        }
        if (null !== $proto->typeConstraint) {
            return TypeCheck::typeNameForConstraint((int) $proto->typeConstraint);
        }

        return '';
    }

    /**
     * Class body constant after trait use must not redefine an inherited trait constant
     * with an incompatible value (Zend/zend_traits.c zend_traits_compile_role_constants, #7012).
     */
    private function rejectIncompatibleTraitClassConstOverride(
        ClassEntry $entry,
        string $nameLc,
        string $constDisplay,
        Variable $value
    ): void {
        if (!isset($entry->traitConstSources[$nameLc], $entry->constants[$nameLc])) {
            return;
        }
        if ($this->classConstValuesIdentical($entry->constants[$nameLc], $value)) {
            return;
        }
        throw new \LogicException(sprintf(
            '%s and %s define the same constant (%s) in the composition of %s. '
            .'However, the definition differs and is considered incompatible. Class was composed',
            $entry->name,
            $entry->traitConstSources[$nameLc],
            $constDisplay,
            $entry->name
        ));
    }

    private function copyClassConstOrStaticPropertyByName(
        ClassEntry $classEntry,
        string $memberNameRaw,
        Variable $dest,
        Frame $frame
    ): bool {
        $memberLc = strtolower($memberNameRaw);
        if ('class' === $memberLc) {
            $dest->string($classEntry->name);

            return true;
        }
        // Case-sensitive constant / enum-case key (#25910, #25929).
        $memberKey = ClassConstName::key($memberNameRaw);
        if (isset($classEntry->constants[$memberKey])) {
            if (!ClassConstName::matchesDeclared(
                $memberNameRaw,
                $this->declaredClassConstName($classEntry, $memberKey)
            )) {
                return false;
            }
            $this->emitClassConstFetchDeprecation($classEntry, $memberNameRaw, $memberKey, $frame);
            if ($classEntry->isEnum && null !== $classEntry->backedType) {
                VM\EnumSupport::ensureBackedEnumValuesUnique($classEntry);
            }
            if (EnumCaseSupport::fetchCaseByMemberName($classEntry, $memberKey, $dest, $this->context)) {
                return true;
            }
            $dest->copyFrom(
                EnumCaseSupport::materializeConstantValue($this->context, $classEntry->constants[$memberKey])
            );

            return true;
        }
        $holding = $this->resolveInheritedClassConstantHolding($classEntry, $memberKey);
        if (null !== $holding) {
            if (!ClassConstName::matchesDeclared(
                $memberNameRaw,
                $this->declaredClassConstName($holding, $memberKey)
            )) {
                return false;
            }
            $inheritedConst = $holding->constants[$memberKey];
            $this->emitClassConstFetchDeprecation($classEntry, $memberNameRaw, $memberKey, $frame);
            if ($classEntry->isEnum && null !== $classEntry->backedType) {
                VM\EnumSupport::ensureBackedEnumValuesUnique($classEntry);
            }
            $dest->copyFrom(EnumCaseSupport::materializeConstantValue($this->context, $inheritedConst));

            return true;
        }
        if (isset($classEntry->staticProperties[$memberLc])) {
            $dest->indirect($classEntry->staticProperties[$memberLc]);

            return true;
        }

        return false;
    }

    /** Declared casing for a class constant / enum case (#25910, #5385, #25929). */
    private function declaredClassConstName(ClassEntry $entry, string $memberKey): ?string
    {
        return $entry->constNames[$memberKey]
            ?? $entry->enumCaseCanonicalNames[$memberKey]
            ?? null;
    }

    /**
     * Class entry that holds an inherited (parent/interface) constant value (#25910, #25929).
     */
    private function resolveInheritedClassConstantHolding(ClassEntry $entry, string $memberKey): ?ClassEntry
    {
        foreach ($entry->interfaces as $ifaceLc) {
            if (!isset($this->context->classes[$ifaceLc])) {
                continue;
            }
            $iface = $this->context->classes[$ifaceLc];
            if (isset($iface->constants[$memberKey])) {
                return $iface;
            }
            $fromParentIface = $this->resolveInheritedClassConstantHolding($iface, $memberKey);
            if (null !== $fromParentIface) {
                return $fromParentIface;
            }
        }
        if (null !== $entry->parentLc && isset($this->context->classes[$entry->parentLc])) {
            $parent = $this->context->classes[$entry->parentLc];
            if (isset($parent->constants[$memberKey])) {
                $vis = $parent->constVisibility[$memberKey] ?? \PHPCfg\Func::FLAG_PUBLIC;
                if (($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
                    return $this->resolveInheritedClassConstantHolding($parent, $memberKey);
                }

                return $parent;
            }

            return $this->resolveInheritedClassConstantHolding($parent, $memberKey);
        }

        return null;
    }

    /**
     * Invoke user __destruct() once (Zend zend_objects_destroy_object; #3144).
     *
     * Generators run pending finally via {@see closeGenerator()} (zend_generator_dtor_storage, #19905).
     */
    public function invokeUserDestructor(ObjectEntry $object): void
    {
        if ($object->destructorInvoked) {
            return;
        }
        if (null !== $object->generatorState) {
            $object->destructorInvoked = true;
            $this->closeGenerator($object->generatorState);

            return;
        }
        $destructor = $object->class->destructor;
        if (null === $destructor) {
            $object->destructorInvoked = true;

            return;
        }
        $object->destructorInvoked = true;
        $thisVar = new Variable();
        $thisVar->object($object);
        ObjectLifetime::addRef($object);

        $savedStack = $this->context->swapRunStack(null);
        $destructorCatch = null;
        $this->context->isolatedDestructorInvoke = true;
        try {
            $child = $destructor->getFrame($this->context, null);
            $thisIdx = $destructor->block->slotIndexForVariableName('this');
            if (null !== $thisIdx) {
                $child->scope[$thisIdx] = $thisVar;
            }
            $child->calledArgs = [$thisVar];
            $this->context->push($child);
            $result = $this->runFrames();
            if (self::SUCCESS !== $result) {
                throw new \LogicException('__destruct() failed in this compiler build');
            }
        } catch (VM\DestructorThrowCatchSignal $signal) {
            $destructorCatch = $signal;
        } finally {
            $this->context->isolatedDestructorInvoke = false;
            $this->context->swapRunStack($savedStack);
            ObjectLifetime::releaseRef($object);
        }
        if (null !== $destructorCatch) {
            throw $destructorCatch;
        }
    }

    private function releaseFrameObjectRefs(Frame $frame): void
    {
        $preserveIds = $this->exceptionObjectIdsToPreserve();
        foreach ($frame->scope as $slotIndex => $slot) {
            if ($this->frameScopeSlotIsClosureByRefCapture($frame, (int) $slotIndex)) {
                continue;
            }
            // PROPERTY_FETCH_WRITE leaves INDIRECT aliases into instance property cells.
            // Releasing through those aliases drops the property's object refcount (Closures
            // stored via $this->prop) even though the cell still holds the ObjectEntry (#22656, #6041).
            if ($this->variableAliasesObjectPropertyCell($slot)) {
                continue;
            }
            // DECLARE_FUNCTION_STATIC / global / class-static CVs are INDIRECT into context-owned
            // storage (#28039 Closures, #28040 object property persistence).
            if ($this->variableAliasesFunctionStaticCell($slot)) {
                continue;
            }
            // Bridged throwable delivered to catch must survive callee CV release (#22541).
            if ($this->variableHoldsPreservedExceptionObject($slot, $preserveIds)) {
                continue;
            }
            ObjectLifetime::releaseDirectObject($slot);
        }
        foreach ($frame->iterators as $iter) {
            if ($this->variableHoldsPreservedExceptionObject($iter, $preserveIds)) {
                continue;
            }
            ObjectLifetime::releaseDirectObject($iter);
        }
    }

    /**
     * Object ids of the exception currently being delivered to catch/finally.
     *
     * @return array<int, true>
     */
    private function exceptionObjectIdsToPreserve(): array
    {
        $ids = [];
        $candidates = [];
        if (null !== $this->context->pendingException) {
            $candidates[] = $this->context->pendingException;
        }
        if (null !== $this->context->activeCatchHandlerFrame
            && null !== $this->context->activeCatchHandlerFrame->activeCatchException
        ) {
            $candidates[] = $this->context->activeCatchHandlerFrame->activeCatchException;
        }
        foreach ($this->context->activeTryHandlerFrames as $handler) {
            if (null !== $handler->activeCatchException) {
                $candidates[] = $handler->activeCatchException;
            }
        }
        foreach ($candidates as $var) {
            $resolved = $var->resolveIndirect();
            if (Variable::TYPE_OBJECT !== $resolved->type) {
                continue;
            }
            try {
                $ids[$resolved->toObject()->id] = true;
            } catch (\LogicException) {
            }
        }

        return $ids;
    }

    /** @param array<int, true> $preserveIds */
    private function variableHoldsPreservedExceptionObject(Variable $var, array $preserveIds): bool
    {
        if ([] === $preserveIds) {
            return false;
        }
        $resolved = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            return false;
        }
        try {
            return isset($preserveIds[$resolved->toObject()->id]);
        } catch (\LogicException) {
            return false;
        }
    }

    /**
     * Release object CVs for abandoned callee activations before catch/finally (Zend leave, #22541).
     *
     * Same-function locals as the handler stay alive (catch may still read them). Nested call
     * frames are released innermost-first; within a frame, scope slot order matches normal return.
     *
     * CFG merge / sequential try frames may have {@see Block::$func} null; resolve via ancestors
     * so `{main}` throw sites are not mistaken for callees when the TYPE_TRY handler block lacks
     * func (#26203 DatePeriod typed-uninit catch destroyForGc).
     */
    private function releaseCalleeObjectRefsBeforeExceptionHandler(Frame $throwFrame, Frame $handler): void
    {
        $handlerFunc = $this->resolveFrameFunc($handler) ?? $this->resolveFrameFunc($throwFrame);
        $pendingOuter = null;
        $pendingFunc = null;
        $toRelease = [];
        for ($f = $throwFrame; null !== $f && $f !== $handler; $f = $f->parent) {
            $frameFunc = $this->resolveFrameFunc($f);
            if ($frameFunc === $handlerFunc) {
                break;
            }
            if ($pendingFunc !== $frameFunc) {
                if (null !== $pendingOuter) {
                    $toRelease[] = $pendingOuter;
                }
                $pendingFunc = $frameFunc;
                $pendingOuter = $f;
            } else {
                $pendingOuter = $f;
            }
        }
        if (null !== $pendingOuter) {
            $toRelease[] = $pendingOuter;
        }
        foreach ($toRelease as $frame) {
            $this->releaseFrameObjectRefs($frame);
        }
    }

    /**
     * Owning PHPCfg Func for a VM frame — walk parents when the CFG block omitted func (#26203).
     */
    private function resolveFrameFunc(Frame $frame): ?\PHPCfg\Func
    {
        for ($f = $frame; null !== $f; $f = $f->parent) {
            $func = $f->block->func ?? null;
            if (null !== $func) {
                return $func;
            }
        }

        return null;
    }

    /**
     * After throw-path finally completes with no local catch, undef CVs of that function (#22541).
     *
     * Try-body locals may live only on the throw-site CFG frame (not the TYPE_TRY handler frame),
     * so walk from $throwFrame through $handler and release each same-function scope once.
     */
    private function releaseHandlerScopeObjectRefsOnExceptionLeave(Frame $handler, ?Frame $throwFrame = null): void
    {
        $func = $handler->block->func ?? null;
        $seenVars = [];
        for ($f = $throwFrame ?? $handler; null !== $f; $f = $f->parent) {
            if (($f->block->func ?? null) === $func) {
                $this->releaseFrameObjectRefsOnce($f, $seenVars);
            }
            if ($f === $handler) {
                for ($p = $handler->parent; null !== $p; $p = $p->parent) {
                    if (($p->block->func ?? null) !== $func) {
                        break;
                    }
                    $this->releaseFrameObjectRefsOnce($p, $seenVars);
                }
                break;
            }
        }
    }

    /**
     * @param array<int, true> $seenVars
     */
    private function releaseFrameObjectRefsOnce(Frame $frame, array &$seenVars): void
    {
        foreach ($frame->scope as $slotIndex => $slot) {
            if ($this->frameScopeSlotIsClosureByRefCapture($frame, (int) $slotIndex)) {
                continue;
            }
            if ($this->variableAliasesObjectPropertyCell($slot)) {
                continue;
            }
            if ($this->variableAliasesFunctionStaticCell($slot)) {
                continue;
            }
            if ($this->variableHoldsPreservedExceptionObject($slot, $this->exceptionObjectIdsToPreserve())) {
                continue;
            }
            $id = spl_object_id($slot);
            if (isset($seenVars[$id])) {
                continue;
            }
            $seenVars[$id] = true;
            ObjectLifetime::releaseDirectObject($slot);
        }
        foreach ($frame->iterators as $iter) {
            $id = spl_object_id($iter);
            if (isset($seenVars[$id])) {
                continue;
            }
            $seenVars[$id] = true;
            ObjectLifetime::releaseDirectObject($iter);
        }
    }

    /**
     * True when a compiler temp slot is still read/written by opcodes after the current PC (#6467).
     */
    private function isVmScopeSlotUsedByFollowingOps(Frame $frame, int $slot): bool
    {
        $block = $frame->block;
        if (null === $block) {
            return false;
        }
        for ($i = $frame->pos; $i < $block->nOpCodes; ++$i) {
            $next = $block->opCodes[$i];
            // Null-constructor stub does not consume the NEW result temp (#6467, #6620).
            if (OpCode::TYPE_FUNCCALL_EXEC_NORETURN === $next->type) {
                continue;
            }
            // Skip startLine / call-site line immediates — same rule as Block::opCodeReadsScopeSlot (#23484).
            foreach ($block->opCodeValueScopeArgs($next) as $arg) {
                if (is_int($arg) && $arg === $slot) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Release php-cfg dead compiler temps at statement boundary (Zend end-of-statement, #6456).
     *
     * @param int ...$keepSlots scope slots still needed by the current opcode
     */
    private function shouldDeferVmDeadTempRelease(Frame $frame): bool
    {
        return null !== $frame->listUnpackAssignMergeBlock;
    }

    /**
     * {main} temps may share the same Variable object as a named local CV (#16040, #15183).
     *
     * JUMPIF dead-temp release must not null that shared storage — e.g. ternary merge assign
     * result slot aliased with `$t`, then nested `$t[1][0]` in a later ternary sees null (#24017).
     * Use {@see Block::isNamedAssignDestSlot()} — the private index array is not visible here.
     */
    private function vmDeadTempReleaseWouldClobberNamedLocal(Frame $frame, int $slot): bool
    {
        if (!$frame->block->isMainScript() || !isset($frame->scope[$slot])) {
            return false;
        }
        $slotVar = $frame->scope[$slot];
        try {
            $target = $slotVar->resolveIndirect();
        } catch (\LogicException) {
            return false;
        }
        foreach ($frame->block->eachNamedScopeSlot() as [, $namedSlot]) {
            if ($namedSlot === $slot || !isset($frame->scope[$namedSlot])) {
                continue;
            }
            if (!$frame->block->isNamedAssignDestSlot($namedSlot)) {
                continue;
            }
            $namedVar = $frame->scope[$namedSlot];
            if ($namedVar === $slotVar) {
                return true;
            }
            try {
                if ($namedVar->resolveIndirect() === $target) {
                    return true;
                }
            } catch (\LogicException) {
            }
        }

        return false;
    }

    private function releaseVmStatementDeadTemps(Frame $frame, int ...$keepSlots): void
    {
        if ($this->shouldDeferVmDeadTempRelease($frame)) {
            return;
        }
        $keep = array_fill_keys($keepSlots, true);
        $cfg = $frame->block->orig;
        if (null === $cfg) {
            return;
        }
        foreach ($cfg->deadOperands as $op) {
            $slot = $frame->block->slotForOperand($op);
            if (null === $slot || isset($keep[$slot]) || !isset($frame->scope[$slot])) {
                continue;
            }
            if (isset($frame->block->constants[$slot])) {
                continue;
            }
            if ($frame->block->isNamedVariableSlot($slot)) {
                continue;
            }
            if (isset($frame->block->deferredArrayLiteralKeepSlots[$slot])) {
                continue;
            }
            if ($this->isVmScopeSlotUsedByFollowingOps($frame, $slot)) {
                continue;
            }
            if ($frame->block->scopeSlotReadInJumpTargets($slot)) {
                continue;
            }
            $this->releaseVmDeadScopeSlot($frame, $slot);
        }
    }

    /**
     * Drop compiler temps after a conditional branch — e.g. WeakReference::get() in `if ($wr->get() !== $o)` (#14103).
     */
    private function releaseVmJumpIfCondTemps(Frame $frame, int $keepSlot): void
    {
        foreach ($frame->scope as $slot => $_var) {
            if ($slot === $keepSlot || $frame->block->isNamedVariableSlot($slot)) {
                continue;
            }
            if (isset($frame->block->constants[$slot])) {
                continue;
            }
            if (isset($frame->block->deferredArrayLiteralKeepSlots[$slot])) {
                continue;
            }
            if (
                $frame->block->scopeSlotReadInJumpTargets($slot)
                || $frame->block->scopeSlotReadInDirectJumpTargets($slot)
            ) {
                continue;
            }
            // Large inline array literals materialize after ternary JUMPIFs in the same block (#14134).
            if ($this->isVmScopeSlotUsedByFollowingOps($frame, $slot)) {
                continue;
            }
            $this->releaseVmDeadScopeSlot($frame, $slot);
        }
    }

    /** @param int ...$keepSlots result + other operand slots to preserve */
    private function releaseVmBinaryOpOperandTemp(Frame $frame, int $operandSlot, int ...$keepSlots): void
    {
        $keep = array_fill_keys($keepSlots, true);
        if (isset($keep[$operandSlot]) || $frame->block->isNamedVariableSlot($operandSlot)) {
            return;
        }
        if (isset($frame->block->constants[$operandSlot])) {
            return;
        }
        $this->releaseVmDeadScopeSlot($frame, $operandSlot);
    }

    private function releaseVmDeadScopeSlot(Frame $frame, int $slot): void
    {
        if (!isset($frame->scope[$slot]) || $frame->block->isNamedVariableSlot($slot)) {
            return;
        }
        if ($this->vmDeadTempReleaseWouldClobberNamedLocal($frame, $slot)) {
            return;
        }
        if (isset($frame->block->deferredArrayLiteralKeepSlots[$slot])) {
            return;
        }
        if ($this->variableAliasesObjectPropertyCell($frame->scope[$slot])) {
            return;
        }
        if ($this->variableAliasesFunctionStaticCell($frame->scope[$slot])) {
            return;
        }
        if ($this->variableIsGeneratorYieldStorage($frame->scope[$slot])) {
            return;
        }
        $var = $frame->scope[$slot]->resolveIndirect();
        if ($var->generatorYieldStorage) {
            return;
        }
        if (Variable::TYPE_OBJECT === $var->type) {
            try {
                $objectId = $var->toObject()->id;
            } catch (\LogicException) {
                $objectId = null;
            }
            if (null !== $objectId) {
                foreach ($frame->scope as $otherSlot => $otherVar) {
                    if ($otherSlot === $slot) {
                        continue;
                    }
                    $other = $otherVar->resolveIndirect();
                    if (Variable::TYPE_OBJECT !== $other->type) {
                        continue;
                    }
                    try {
                        if ($other->toObject()->id === $objectId) {
                            $frame->scope[$slot]->null();

                            return;
                        }
                    } catch (\LogicException) {
                    }
                }
                if ($this->scopeArraysReferenceObjectId($frame, $objectId)) {
                    $frame->scope[$slot]->null();

                    return;
                }
                if ($this->context->userConstantReferencesObjectId($objectId)) {
                    $frame->scope[$slot]->null();

                    return;
                }
            }
        }
        // Direct TYPE_OBJECT holders: Variable::null()/reset() already releaseRef once.
        // INDIRECT aliases do not own a ref in reset(), so release the target once first
        // (#22868 — releaseDirectObject+null double-freed temps still bound on closures).
        $slotVar = $frame->scope[$slot];
        if ($slotVar->isIndirect()) {
            ObjectLifetime::releaseDirectObject($slotVar);
        }
        $slotVar->null();
    }

    /** Keep array-literal element objects alive when expr temps are released (#14120, #5593). */
    private function scopeArraysReferenceObjectId(Frame $frame, int $objectId): bool
    {
        foreach ($frame->scope as $scopeVar) {
            $resolved = $scopeVar->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $resolved->type) {
                continue;
            }
            foreach ($resolved->toArray()->iterateKeyed(true) as [, $element]) {
                $cell = $element->resolveIndirect();
                if (Variable::TYPE_OBJECT !== $cell->type) {
                    continue;
                }
                try {
                    if ($cell->toObject()->id === $objectId) {
                        return true;
                    }
                } catch (\LogicException) {
                }
            }
        }

        return false;
    }

    /**
     * True when a scope/call-arg cell resolves to a live object property backing store (#6041, #22656).
     *
     * Used by dead-temp cleanup and frame object-ref release so INDIRECT write aliases do not
     * releaseRef() objects still owned by the instance property Variable.
     */
    private function variableAliasesObjectPropertyCell(Variable $var): bool
    {
        if (null !== $var->objectPropertyOwner) {
            return true;
        }
        $resolved = $var->resolveIndirect();

        return null !== $resolved->objectPropertyOwner;
    }

    /**
     * True when a scope cell is (or aliases) context-owned long-lived storage (#28039, #28040).
     *
     * DECLARE_FUNCTION_STATIC / global / class-static install an INDIRECT into a persistent cell;
     * releasing through that alias on frame exit destroys Closures and wipes object properties
     * the static still holds (destroyForGc while the cell pointer survives).
     */
    private function variableAliasesFunctionStaticCell(Variable $var): bool
    {
        $candidates = [$var];
        if ($var->isIndirect()) {
            $candidates[] = $var->resolveIndirect();
        }
        foreach ($candidates as $cell) {
            if ($cell->functionStaticStorage) {
                return true;
            }
            if (null !== $this->context->functionStaticKeyForStorage($cell)) {
                return true;
            }
            if ($this->context->isGlobalStorage($cell)) {
                return true;
            }
            if ($this->isStaticPropertyStorageCell($cell)) {
                return true;
            }
        }

        return false;
    }

    /** Generator yield key/value cells must survive fcall temp release (#18184). */
    private function variableIsGeneratorYieldStorage(Variable $var): bool
    {
        if ($var->generatorYieldStorage) {
            return true;
        }

        return $var->resolveIndirect()->generatorYieldStorage;
    }

    /**
     * Zend fcall end — drop by-value send snapshots and dead inline call-arg temps (#11602).
     */
    private function clearOutgoingCallState(Frame $frame, ?int $keepReturnSlot = null): void
    {
        $this->releaseOutgoingCallArgTemps($frame, $keepReturnSlot);
        $frame->callArgs = [];
        $frame->callArgEntries = [];
        $frame->builtinCalleeQualifiedMethod = null;
        // Drop stale call-site line so later opcodes (e.g. dynamic property E_DEPRECATED)
        // resolve via the current opcode source line, not the prior call (#21953).
        $frame->callSiteLine = 0;
    }

    private function releaseOutgoingCallArgTemps(Frame $frame, ?int $keepReturnSlot = null): void
    {
        foreach ($frame->callArgEntries as $entry) {
            if ('u' === $entry[0]) {
                $slot = $entry[2] ?? null;
                // By-ref sends store the CV (slot null) — must not releaseRef the live object (#25097).
                if (null !== $slot) {
                    ObjectLifetime::releaseDirectObject($entry[1]);
                }
            } elseif ('n' === $entry[0]) {
                $slot = $entry[3] ?? null;
                if (null !== $slot) {
                    ObjectLifetime::releaseDirectObject($entry[2]);
                }
            } else {
                $slot = $entry[2] ?? null;
                if (null !== $slot) {
                    ObjectLifetime::releaseDirectObject($entry[1]);
                }
            }
            if (!is_int($slot) || $slot === $keepReturnSlot || $frame->block->isNamedVariableSlot($slot)) {
                continue;
            }
            if (isset($frame->scope[$slot]) && $this->variableAliasesObjectPropertyCell($frame->scope[$slot])) {
                continue;
            }
            if (isset($frame->scope[$slot]) && $this->variableAliasesFunctionStaticCell($frame->scope[$slot])) {
                continue;
            }
            if (isset($frame->scope[$slot]) && $this->variableIsGeneratorYieldStorage($frame->scope[$slot])) {
                continue;
            }
            // Unhandled match arms re-read the scrutinee on JUMPIF targets after the probe call (#13955).
            if ($frame->block->scopeSlotReadInJumpTargets($slot)) {
                continue;
            }
            $this->releaseVmDeadScopeSlot($frame, $slot);
        }
    }

}
