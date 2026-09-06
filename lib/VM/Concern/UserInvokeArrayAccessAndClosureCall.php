<?php

declare(strict_types=1);

namespace PHPCompiler;

use PHPCompiler\Func;
use PHPCompiler\ext\standard\VmForwardStaticCall;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\GeneratorState;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\NamedArgs;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\Variable;

/**
 * User-call, instance/static invoke, ArrayAccess, and closure invocation for the VM (#36403).
 *
 * Extracted from {@see \PHPCompiler\VM}: {@code invokePhpFunction} through
 * {@code invokeClosureFromWithCalledArgs} (php-src Zend/zend_execute_API.c /
 * Zend/zend_execute.c call helpers; ArrayAccess via zend_object_handlers;
 * closures via zend_closures.c). Concern trait — same namespace as parent so
 * relative Frame / OpCode / Block helpers resolve. Move-only; no new C ABI.
 */
trait UserInvokeArrayAccessAndClosureCall
{
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
        if (!VM\SplArraySupport::hasState($object)) {
            return null;
        }
        [$declaring, $methodLc] = $this->resolveInstanceMethod($object->class, 'offsetExists');
        $func = $declaring->methods[$methodLc] ?? null;
        if (!$func instanceof Func\Internal) {
            return null;
        }

        return VM\SplArraySupport::dimensionIsSet($object, $key);
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
}
