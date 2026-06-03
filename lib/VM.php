<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler;

require_once __DIR__.'/OpCodeNames.php';

use PHPCompiler\Compiler\AttributeNames;
use PHPCompiler\Func;
use PHPCompiler\ext\standard\VmEval;
use PHPCompiler\ext\standard\VmForwardStaticCall;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\CastSupport;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\DnfCheck;
use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\FiberState;
use PHPCompiler\VM\GeneratorState;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\IterableCheck;
use PHPCompiler\VM\NamedArgs;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ObjectLifetime;
use PHPCompiler\VM\ObjectPropertyIterator;
use PHPCompiler\VM\ScriptExit;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\TypedPropertyReadSignal;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

class VM {
    const SUCCESS = 1;
    const FAILURE = 2;

    private static ?self $running = null;

    /** @internal Active VM during runFrames (#3429 typed property errors). */
    public static function running(): ?self
    {
        return self::$running;
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
        try {
            if (!is_null($block->handler)) {
                $frame = $block->getFrame($this->context);
                $this->seedScriptPath($frame);
                $block->handler->execute($frame);

                return self::SUCCESS;
            }

            $frame = $block->getFrame($this->context);
            $this->seedScriptPath($frame);
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
     * @param Variable ...$args
     */
    private function invokePhpFunctionOnStack(Func\PHP $func, ...$args): Variable
    {
        if ($func->block->isGenerator) {
            $state = new GeneratorState($this, $func, [...$args]);
            $out = new Variable();
            $out->object($state->wrapObject());

            return $out;
        }

        $child = $func->getFrame($this->context, null);
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
     * @param Variable ...$args
     */
    private function invokePhpFunctionForCoercion(Func\PHP $func, ...$args): Variable
    {
        $savedStack = $this->context->swapRunStack(null);
        try {
            $result = $this->invokePhpFunctionOnStack($func, ...$args);
            $this->context->swapRunStack($savedStack);

            return $result;
        } catch (\Throwable $native) {
            $this->context->swapRunStack($savedStack);
            if (null !== $savedStack) {
                $thrown = $native instanceof \Error
                    ? VM\BuiltinExceptionSupport::materializeError($this->context, $native->getMessage())
                    : $this->makeEngineError($native->getMessage(), 'Exception');
                $catchFrame = $this->findCatchFrameForThrow($savedStack->frame, $thrown);
                if (null !== $catchFrame) {
                    $this->context->swapRunStack($savedStack);
                    $catchStack = $this->context->swapRunStack(null);
                    $this->context->push($catchFrame);
                    $catchResult = $this->runFrames();
                    $this->context->swapRunStack($catchStack);
                    $this->clearTryCatchUnwindState();
                    if (self::SUCCESS !== $catchResult) {
                        throw new \LogicException('Coercion catch handler failed in this compiler build');
                    }
                    throw new VM\MagicMethodInvocationAborted();
                }
            }
            throw $native;
        } catch (VM\MagicMethodInvocationAborted $aborted) {
            if (!$this->context->hasRunStack()) {
                $this->context->swapRunStack($savedStack);
            }
            throw $aborted;
        } catch (\Throwable $e) {
            if (!$this->context->hasRunStack()) {
                $this->context->swapRunStack($savedStack);
            }
            throw $e;
        }
    }

    /**
     * Invoke a static method in the caller's late-static scope (forward_static_call, #3197).
     */
    public function invokeStaticWithCalledScope(
        string $calledScopeClass,
        string $methodName,
        Variable ...$args
    ): Variable {
        $func = VmForwardStaticCall::resolveStaticMethod($this->context, $calledScopeClass, $methodName);
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
        return $this->resolveStaticMethod(strtolower($class->name), strtolower($methodLc));
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
                return true;
            }
            if (null === $entry->parentLc) {
                return false;
            }
            $lcClass = $entry->parentLc;
        }

        return false;
    }

    /** Coerce a VM value to string, invoking __toString on objects when defined (issue #3296). */
    public function coerceVariableToString(Variable $var): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            return $var->toString();
        }
        $object = $var->toObject();
        if (EnumCaseSupport::isEnumCase($object)) {
            throw new \Error("Object of class {$object->class->name} could not be converted to string");
        }
        if (!$this->hasInstanceMethod($object->class, '__tostring')) {
            return 'Object';
        }
        $this->context->coercingObjectToString = true;
        try {
            $result = $this->invokeInstanceMethod($object, '__toString')->resolveIndirect();
        } finally {
            $this->context->coercingObjectToString = false;
        }

        return $result->toString();
    }

    /** Invoke a user instance method from VM internals (e.g. __debugInfo, #3259). */
    public function invokeInstanceMethod(ObjectEntry $object, string $methodName, Variable ...$extraArgs): Variable
    {
        $methodLc = strtolower($methodName);
        [$declaring] = $this->resolveInstanceMethod($object->class, $methodLc);
        $func = $declaring->methods[$methodLc];
        if (!$func instanceof Func\PHP) {
            throw new \LogicException("{$declaring->name}::{$methodName}() is not a user method in this compiler build");
        }
        $thisVar = new Variable();
        $thisVar->object($object);
        return $this->invokePhpFunction($func, $thisVar, ...$extraArgs);
    }

    public function objectImplementsArrayAccess(ObjectEntry $object): bool
    {
        return VM\InterfaceCheck::entryImplements($object->class, 'arrayaccess', $this->context);
    }

    public function invokeArrayAccessOffsetGet(ObjectEntry $object, Variable $key): Variable
    {
        return $this->invokeInstanceMethod($object, 'offsetGet', $key);
    }

    public function invokeArrayAccessOffsetSet(ObjectEntry $object, Variable $key, Variable $value): void
    {
        $this->invokeInstanceMethod($object, 'offsetSet', $key, $value);
    }

    public function invokeArrayAccessOffsetExists(ObjectEntry $object, Variable $key): bool
    {
        return $this->invokeInstanceMethod($object, 'offsetExists', $key)->toBool();
    }

    public function invokeArrayAccessOffsetUnset(ObjectEntry $object, Variable $key): void
    {
        $this->invokeInstanceMethod($object, 'offsetUnset', $key);
    }

    /**
     * isset($obj->prop) — Zend zend_std_has_property / __isset parity (#3298, #4586).
     */
    public function objectPropertyIsSet(ObjectEntry $object, string $propName, ?Frame $frame = null): bool
    {
        if (null !== $frame) {
            $meta = $this->classPropertyMeta($object, $propName);
            $getLc = $meta?->getHookMethodLc
                ?? strtolower(SourcePreprocessor\PropertyHooks::getHookMethodName($propName));
            if (isset($object->class->methods[$getLc])) {
                $hookValue = $this->fetchPropertyWithHooks($object, $propName, $frame);
                if (null !== $hookValue) {
                    $value = $hookValue->resolveIndirect();

                    return !$value->isUndefined() && Variable::TYPE_NULL !== $value->type;
                }
            }
        }
        $props = $object->getRawProperties();
        if (isset($props[$propName])) {
            $value = $props[$propName]->resolveIndirect();
            if (!$value->isUndefined() && Variable::TYPE_NULL !== $value->type) {
                return true;
            }

            return false;
        }
        if ($this->hasInstanceMethod($object->class, '__isset')) {
            $key = new Variable();
            $key->string($propName);
            $result = $this->invokeInstanceMethod($object, '__isset', $key)->resolveIndirect();

            return $result->toBool();
        }

        return false;
    }

    /**
     * unset($obj->prop) — Zend zend_std_unset_property / __unset parity (#3298).
     */
    public function unsetObjectProperty(ObjectEntry $object, string $propName): void
    {
        $props = $object->getRawProperties();
        if (isset($props[$propName])) {
            $object->unsetProperty($propName);

            return;
        }
        if ($this->hasInstanceMethod($object->class, '__unset')) {
            $key = new Variable();
            $key->string($propName);
            $this->invokeInstanceMethod($object, '__unset', $key);
        }
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
            $result = $this->invokeInstanceMethod($object, '__toString')->resolveIndirect();
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
    public function valueToPrintString(Variable $var): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            return $var->toString();
        }
        $object = $var->toObject();
        if (EnumCaseSupport::isEnumCase($object)) {
            throw new \Error("Object of class {$object->class->name} could not be converted to string");
        }
        if (!$this->hasInstanceMethod($object->class, '__tostring')) {
            throw new \Error("Object of class {$object->class->name} could not be converted to string");
        }
        $this->context->coercingObjectToString = true;
        try {
            $result = $this->invokeInstanceMethod($object, '__toString')->resolveIndirect();
        } finally {
            $this->context->coercingObjectToString = false;
        }

        return $result->toString();
    }

    /**
     * Invoke Iterator protocol methods during foreach (Zend zend_iterators.c parity, #3234).
     */
    public function invokeForeachInstanceMethod(Frame $_parentFrame, Variable $receiver, string $methodName): Variable
    {
        $methodLc = strtolower($methodName);
        $object = $receiver->toObject();
        $class = $object->class;
        if (!isset($class->methods[$methodLc])) {
            throw new \LogicException("Call to undefined method {$class->name}::{$methodLc}()");
        }

        $recv = new Variable();
        $recv->copyFrom($receiver);

        return $this->invokePhpFunction($class->methods[$methodLc], $recv);
    }

    /**
     * Properties for var_dump / print_r when __debugInfo is defined (Zend parity, #3259).
     *
     * @return array<string, Variable>
     */
    public function getObjectDebugProperties(ObjectEntry $object): array
    {
        if ($this->hasInstanceMethod($object->class, '__debuginfo')) {
            $result = $this->invokeInstanceMethod($object, '__debugInfo')->resolveIndirect();
            if (Variable::TYPE_ARRAY !== $result->type) {
                $given = Variable::TYPE_OBJECT === $result->type
                    ? $result->toObject()->class->name
                    : TypeCheck::typeNameForConstraint($result->type);
                throw new \TypeError(
                    "{$object->class->name}::__debugInfo(): Return value must be of type array, {$given} returned"
                );
            }
            $props = [];
            foreach ($result->toArray()->iterateKeyed(true) as [$key, $value]) {
                $name = Variable::TYPE_STRING === $key->type
                    ? $key->toString()
                    : (string) $key->toInt();
                $copy = new Variable();
                $copy->copyFrom($value->resolveIndirect());
                $props[$name] = $copy;
            }

            return $props;
        }

        return $object->class->getProperties($object->getRawProperties(), ClassEntry::PROP_PURPOSE_DEBUG);
    }

    /**
     * Zend zend_std_clone_object: shallow copy then user __clone() when defined (#3170).
     */
    protected function invokeCloneMagicMethod(ObjectEntry $object): void
    {
        $class = $object->class;
        if (!isset($class->methods['__clone'])) {
            return;
        }
        $thisVar = new Variable(Variable::TYPE_OBJECT);
        $thisVar->object($object);
        $this->invokePhpFunction($class->methods['__clone'], $thisVar);
    }

    /**
     * Zend zend_std_read_property / __get slow path (#146).
     */
    protected function invokeMagicGet(ObjectEntry $object, string $name): Variable
    {
        if (!$this->hasInstanceMethod($object->class, '__get')) {
            throw new \LogicException('Undefined property access');
        }
        $nameVar = new Variable(Variable::TYPE_STRING);
        $nameVar->string($name);

        return $this->invokeInstanceMethod($object, '__get', $nameVar);
    }

    /**
     * Zend zend_std_write_property / __set slow path (#146).
     */
    protected function invokeMagicSet(ObjectEntry $object, string $name, Variable $value): void
    {
        if (!$this->hasInstanceMethod($object->class, '__set')) {
            throw new \LogicException('Undefined property access');
        }
        $nameVar = new Variable(Variable::TYPE_STRING);
        $nameVar->string($name);
        $valueCopy = new Variable();
        $valueCopy->copyFrom($value);
        $this->invokeInstanceMethod($object, '__set', $nameVar, $valueCopy);
    }

    /**
     * True when zend_std_read_property must invoke __get (undeclared slot or inaccessible declared prop).
     */
    protected function propertyReadUsesMagicGet(ObjectEntry $object, string $name, Frame $frame): bool
    {
        if (!$this->hasInstanceMethod($object->class, '__get')) {
            return false;
        }
        $meta = $this->classPropertyMeta($object, $name);
        if (null === $meta) {
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
                fn (string $classLc, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLc, $ancestorLc)
            );

            return false;
        } catch (\LogicException $e) {
            return true;
        }
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
     * Reject []= / dim-write on a value produced by __get (#4673).
     */
    protected function rejectMagicGetIndirectModify(Variable $containerSlot, bool $forWrite, Frame $frame): ?Frame
    {
        if (!$forWrite) {
            return null;
        }
        if (null === $containerSlot->magicGetOverloadedTarget || null === $containerSlot->magicGetOverloadedName) {
            return null;
        }
        $class = $containerSlot->magicGetOverloadedTarget->class->name;
        $prop = $containerSlot->magicGetOverloadedName;

        return $this->dispatchVmError(sprintf(
            'Indirect modification of overloaded property %s::$%s has no effect',
            $class,
            $prop
        ), $frame);
    }

    /**
     * Resolve an instance property write lvalue, including __set / dynamic properties (#146).
     */
    protected function fetchObjectPropertyWriteLvalue(ObjectEntry $object, string $name, Frame $frame): Variable
    {
        if ($object->hasProperty($name)) {
            return $object->getProperty($name);
        }
        if ($object->class->readonly && !$this->hasInstanceMethod($object->class, '__set')) {
            $thrown = VM\BuiltinExceptionSupport::materializeError(
                $this->context,
                sprintf('Cannot create dynamic property %s::$%s', $object->class->name, $name)
            );
            $this->raiseUncaughtException($thrown);
        }
        if ($this->hasInstanceMethod($object->class, '__set')) {
            $proxy = new Variable();
            $proxy->magicSetTarget = $object;
            $proxy->magicSetName = $name;

            return $proxy;
        }
        if (!$object->class->allowsDynamicProperties) {
            $scriptPath = $frame->scriptPath;
            $this->context->errors->deprecatedDynamicProperty(
                $object->class->name,
                $name,
                '' !== $scriptPath && '-' !== $scriptPath ? $scriptPath : null,
                $this->context,
                $frame
            );
        }

        return $object->allocateProperty($name);
    }

    /**
     * Invoke a closure from a VM builtin (isolated run stack; issue #72).
     */
    public function invokeClosure(ClosureState $closureState, Variable ...$args): Variable
    {
        $savedStack = $this->context->swapRunStack(null);
        try {
            $init = new Frame(null, $closureState->func->block, null);
            $init->vmContext = $this->context;
            $this->initClosureCall($init, $closureState);
            if (null === $init->call) {
                throw new \LogicException('Closure invocation failed in this compiler build');
            }
            $child = $init->call->getFrame($this->context, !empty($init->callArgs) ? $init : null);
            $this->applyClosureBinding($child, $closureState);
            $child->calledArgs = $args;
            $out = new Variable();
            $child->returnVar = $out;
            if ($child->hasHandler()) {
                $child->vmContext = $this->context;
                $child->handler->execute($child);

                return $out->resolveIndirect();
            }
            $this->context->push($child);
            $result = $this->runFrames();
            if (self::SUCCESS !== $result) {
                throw new \LogicException('Closure invocation failed in this compiler build');
            }

            return $out->resolveIndirect();
        } finally {
            $this->context->swapRunStack($savedStack);
        }
    }

    /**
     * Execute dynamically compiled eval() code in the caller variable scope (#3358).
     */
    public function executeEvalBlock(Block $block, Frame $caller): Variable
    {
        $out = new Variable();
        $child = $block->getFrame($this->context, $caller);
        $child->ephemeral = true;
        // Scope comes from getFrame($caller); parent must stay null so nested runFrames exits.
        $child->parent = null;
        $child->returnVar = $out;
        $child->scriptPath = VmEval::EVAL_FILENAME;
        $this->context->scriptStack->push($child->scriptPath);
        try {
            $this->context->push($child);
            $result = $this->runFrames();
            if (self::SUCCESS !== $result) {
                throw new \LogicException('eval() execution failed in this compiler build');
            }
        } finally {
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
        $this->bindClosureCallCaptures($child, $fiber->callback);
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
            throw new VM\NativeFiberError('Cannot resume a fiber that is terminated');
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
            throw new VM\NativeFiberError('Cannot throw into a fiber that is terminated');
        }
        if (FiberState::STATUS_SUSPENDED !== $fiber->status) {
            throw new VM\NativeFiberError('Cannot throw into a fiber that is not suspended');
        }
        $fiber->pendingThrow->copyFrom($exception->resolveIndirect());
        $fiber->hasPendingThrow = true;
        $fiber->resumeArgument->null();

        $returnSlot = new Variable();

        return $this->runFiberExecution($fiber, $returnSlot);
    }

    private function runFiberExecution(FiberState $fiber, Variable $returnSlot): Variable
    {
        $child = $fiber->frame;
        if (null === $child) {
            throw new \LogicException('Fiber execution missing frame');
        }
        $savedFiber = $this->context->currentFiber;
        $this->context->currentFiber = $fiber;
        $savedStack = $this->context->swapRunStack(null);
        try {
            $this->applyFiberPendingThrow($fiber);
            $child = $fiber->frame;
            if (null === $child) {
                throw new \LogicException('Fiber execution missing frame after throw dispatch');
            }
            $this->context->push($child);
            try {
                $result = $this->runFrames();
            } catch (\Throwable $e) {
                $fiber->status = FiberState::STATUS_TERMINATED;
                $fiber->frame = null;
                $fiber->pendingSuspendReturnVar = null;
                throw $e;
            }
        } finally {
            $this->context->swapRunStack($savedStack);
            $this->context->currentFiber = $savedFiber;
        }
        if (self::FIBER_SUSPEND === $result) {
            $fiber->status = FiberState::STATUS_SUSPENDED;
            $out = new Variable();
            $out->copyFrom($fiber->suspendReturn);

            return $out;
        }
        if (self::SUCCESS === $result) {
            $fiber->status = FiberState::STATUS_TERMINATED;
            $fiber->frame = null;
            $out = new Variable();
            $out->copyFrom($returnSlot->resolveIndirect());

            return $out;
        }

        throw new \LogicException('Fiber execution failed in this compiler build');
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
            $this->raiseUncaughtException($thrown);
        }
        $this->context->pendingException = $thrown;
        for ($handler = $frame; null !== $handler; $handler = $handler->parent) {
            if ($handler->fiberState !== $fiber && $this->findFiberState($handler) !== $fiber) {
                break;
            }
            $catchFrame = $this->dispatchCatchForHandlerFrame($handler);
            if (null !== $catchFrame) {
                $catchFrame->fiberState = $fiber;
                $fiber->frame = $catchFrame;

                return;
            }
        }
        $this->clearTryCatchUnwindState();
        $fiber->status = FiberState::STATUS_TERMINATED;
        $fiber->frame = null;
        $this->raiseUncaughtException($thrown);
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
     * Materialize a Traversable (array or Generator) into a new array (ext/spl iterator_to_array parity, #3100).
     */
    public function iteratorToArray(Variable $iterator, bool $preserveKeys = false): HashTable
    {
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
            $gen->rewind();
            $index = 0;
            while ($this->advanceGeneratorIteration($gen)) {
                if ($preserveKeys) {
                    self::appendHashTableEntry($out, $gen->currentKey, $gen->currentValue);
                } else {
                    $packedKey = new Variable();
                    $packedKey->int($index++);
                    self::appendHashTableEntry($out, $packedKey, $gen->currentValue);
                }
            }

            return $out;
        }

        throw new \LogicException(
            'iterator_to_array() argument must be an array or Generator in this compiler build'
        );
    }

    private static function appendHashTableEntry(HashTable $out, Variable $key, Variable $value): void
    {
        $copy = new Variable();
        $copy->copyFrom($value);
        if (Variable::TYPE_INTEGER === $key->type) {
            $out->append($copy);

            return;
        }
        $out->add($key->toString(), $copy);
    }

    private function seedScriptPath(Frame $frame): void
    {
        if ('' !== $frame->scriptPath) {
            $this->context->scriptStack->push($frame->scriptPath);
        }
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

    private function dispatchEngineThrow(Frame $frame, Variable $thrown): ?Frame
    {
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

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
            $op = $frame->block->opCodes[$frame->pos++];
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
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    $arg3 = $frame->scope[$op->arg3];
                    if ($this->dispatchPropertySetHookAssign($arg2, $arg3, $frame)) {
                        $arg1->copyFrom($arg3);
                        break;
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
                    $catchFrame = $this->enforcePropertyVisibilityWrite($arg2, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $catchFrame = $this->enforceReadonlyPropertyWrite($arg2, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    if (null !== ($msg = $this->asymmetricPropertyWriteMessage($arg2, $frame))) {
                        $catchFrame = $this->dispatchVmError($msg, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    }
                    $arg2->copyFrom($arg3);
                    $arg1->copyFrom($arg3);
                    if ($op->arg2 !== $op->arg3) {
                        $arg3->null();
                    }
                    if ($op->arg1 !== $op->arg2 && $op->arg1 !== $op->arg3) {
                        $arg1->null();
                    }
                    $strict = null !== $frame->parent
                        ? $frame->parent->block->strictTypes
                        : $frame->block->strictTypes;
                    try {
                        TypeCheck::coercePropertyWrite($arg2, $strict);
                        if (null !== $writeTarget->dnfArms) {
                            DnfCheck::assertMatches(
                                $arg3,
                                $writeTarget->dnfArms,
                                $this->context,
                                'Property',
                                $writeTarget
                            );
                        }
                    } catch (\TypeError $e) {
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    }
                    break;
                case OpCode::TYPE_ASSIGN_REF:
                    $catchFrame = $this->dispatchThisReassignFatalIfNeeded($frame, $op->arg1);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    if (null !== $op->arg3 && 0 !== (int) $op->arg3) {
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
                    $catchFrame = $this->enforceReadonlyPropertyWrite($lhs, $frame);
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
                    $rhs = $frame->scope[$op->arg2]->resolveIndirect();
                    if (Variable::TYPE_INDIRECT !== $rhs->type) {
                        $ref = new Variable();
                        $ref->copyFrom($rhs);
                        $rhs->indirect($ref);
                    }
                    $lhs->indirect($rhs->resolveIndirect());
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
                    $name = $nameHolder->toString();
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
                    if (Superglobals::isSuperglobalName($name)) {
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
                    break;
                case OpCode::TYPE_DECLARE_FUNCTION_STATIC:
                    if (!isset($frame->block->constants[$op->arg2])) {
                        throw new \LogicException('Function static key must be a compile-time constant');
                    }
                    $storageKey = $frame->block->constants[$op->arg2]->toString();
                    $storage = $this->context->ensureFunctionStatic($storageKey);
                    if (!$this->context->isFunctionStaticInitialized($storageKey)) {
                        if (null !== $op->arg3 && isset($frame->block->constants[$op->arg3])) {
                            $storage->copyFrom($frame->block->constants[$op->arg3]);
                            $this->context->markFunctionStaticInitialized($storageKey);
                        }
                    }
                    $frame->scope[$op->arg1]->indirect($storage);
                    break;
                case OpCode::TYPE_JUMPIF_FUNCTION_STATIC_INITIALIZED:
                    if (!isset($frame->block->constants[$op->arg2])) {
                        throw new \LogicException('Function static key must be a compile-time constant');
                    }
                    $jumpKey = $frame->block->constants[$op->arg2]->toString();
                    if ($this->context->isFunctionStaticInitialized($jumpKey)) {
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
                    $store = $this->context->ensureFunctionStatic($storeKey);
                    $store->copyFrom($frame->scope[$op->arg3]->resolveIndirect());
                    $this->context->markFunctionStaticInitialized($storeKey);
                    break;
                case OpCode::TYPE_LIST_UNPACK_CHECK:
                    $unpack = $frame->scope[$op->arg2]->resolveIndirect();
                    if (null !== $op->block1) {
                        if (Variable::TYPE_ARRAY !== $unpack->type) {
                            $frame = $this->frameForBranch($frame, $op->block1);
                            goto restart;
                        }
                        break;
                    }
                    break;
                case OpCode::TYPE_LIST_SPREAD_ASSIGN:
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
                    $catchFrame = $this->rejectMagicGetIndirectModify($containerSlot, $forWrite, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    if ($container->isArrayAccessOffset()) {
                        if ($forWrite || is_null($op->arg3)) {
                            throw new \Error('Cannot indirectly modify an element of ArrayAccess');
                        }
                        $container = $container->readArrayAccessOffsetValue();
                    }
                    $isGlobals = Variable::TYPE_ARRAY === $container->type
                        && $this->context->isGlobalsTable($container);
                    if ($forWrite && Variable::TYPE_ARRAY === $container->type && !$isGlobals) {
                        $container->separateArrayForWrite();
                        $container = $containerSlot->resolveIndirect();
                    }
                    if (is_null($op->arg3)) {
                        $bracketMsg = TypeCheck::cannotUseBracketOn($container);
                        if (null !== $bracketMsg) {
                            $catchFrame = $this->dispatchVmTypeError(new \TypeError($bracketMsg), $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                        }
                        if ($container->type !== Variable::TYPE_ARRAY) {
                            throw new \LogicException('[] is only supported for arrays');
                        }
                        $arg1->indirect($container->toArray()->append(new Variable));
                        break;
                    }
                    $arg3 = $frame->scope[$op->arg3];
                    if ($container->type === Variable::TYPE_STRING) {
                        $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
                        $byteIndex = Variable::stringOffsetIndexFromDim(
                            $arg3,
                            $this->context->errors,
                            $this->context,
                            $frame,
                            $scriptFile
                        );
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
                            if (!$forWrite && Variable::TYPE_STRING === $arg3->type
                                && null === $container->toArray()->find($arg3->toString())) {
                                $this->context->errors->undefinedArrayKey(
                                    $arg3,
                                    $this->context,
                                    $frame,
                                    '' !== $frame->scriptPath ? $frame->scriptPath : null
                                );
                            }
                            $arg1->indirect($this->context->globalsTableOffsetFetch($arg3, $forWrite));
                            break;
                        }
                        $table = $container->toArray();
                        if (!$forWrite && !$table->keyExists($arg3)) {
                            $this->context->errors->undefinedArrayKey(
                                $arg3,
                                $this->context,
                                $frame,
                                '' !== $frame->scriptPath ? $frame->scriptPath : null
                            );
                        }
                        $arg1->indirect($table->findVariable($arg3, $forWrite));
                    } elseif (
                        Variable::TYPE_OBJECT === $container->type
                        && $this->objectImplementsArrayAccess($container->toObject())
                    ) {
                        $object = $container->toObject();
                        if ($forWrite) {
                            $dim = new Variable();
                            $dim->arrayAccessDimension(new VM\ArrayAccessDimension($this, $object, $arg3));
                            $arg1->indirect($dim);
                        } else {
                            $arg1->copyFrom($this->invokeArrayAccessOffsetGet($object, $arg3));
                        }
                    } else {
                        $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
                        if (!$forWrite && TypeCheck::isScalarNonContainerDimRead($container)) {
                            $resolved = $container->resolveIndirect();
                            $this->context->errors->arrayOffsetOnNonContainer(
                                TypeCheck::typeNameForConstraint($resolved->type),
                                $this->context,
                                $frame,
                                $scriptFile
                            );
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
                        $bracketMsg = TypeCheck::cannotUseBracketOn($container);
                        if (null !== $bracketMsg) {
                            $catchFrame = $this->dispatchVmTypeError(new \TypeError($bracketMsg), $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                        }
                        throw new \LogicException('Illegal offset');
                    }
                    break;
                case OpCode::TYPE_CAST_BOOL:
                    try {
                        $frame->scope[$op->arg1]->castFrom(Variable::TYPE_BOOLEAN, $frame->scope[$op->arg2], $this);
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
                        $frame->scope[$op->arg1]->castFrom(Variable::TYPE_INTEGER, $frame->scope[$op->arg2], $this);
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
                        $frame->scope[$op->arg1]->castFrom(Variable::TYPE_FLOAT, $frame->scope[$op->arg2], $this);
                    } catch (\TypeError $e) {
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    }
                    break;
                case OpCode::TYPE_CAST_STRING:
                    try {
                        $frame->scope[$op->arg1]->castFrom(Variable::TYPE_STRING, $frame->scope[$op->arg2], $this);
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
                    } catch (VM\MagicMethodInvocationAborted) {
                        $this->clearTryCatchUnwindState();
                        ++$frame->pos;
                        break;
                    }
                    break;
                case OpCode::TYPE_CAST_ARRAY:
                    $frame->scope[$op->arg1]->copyFrom(
                        CastSupport::toArray($frame->scope[$op->arg2])
                    );
                    break;
                case OpCode::TYPE_CAST_OBJECT:
                    $dst = $frame->scope[$op->arg1];
                    $src = $frame->scope[$op->arg2]->resolveIndirect();
                    if (Variable::TYPE_OBJECT === $src->type) {
                        $dst->copyFrom($src);
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
                            $object->allocateProperty($propName)->copyFrom($valueVar);
                        }
                    }
                    break;
                case OpCode::TYPE_CAST_UNSET:
                    $src = $frame->scope[$op->arg2];
                    if ($this->slotIsReferenceBinding($src, $frame->scope)) {
                        $src->reset();
                        $src->type = Variable::TYPE_UNDEFINED;
                    }
                    $frame->scope[$op->arg1]->null();
                    break;
                case OpCode::TYPE_IDENTICAL:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    $arg3 = $frame->scope[$op->arg3];
                    $arg1->bool($arg2->identicalTo($arg3));
                    break;
                case OpCode::TYPE_NOT_IDENTICAL:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    $arg3 = $frame->scope[$op->arg3];
                    $arg1->bool(!$arg2->identicalTo($arg3));
                    break;
                case OpCode::TYPE_EQUAL:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    $arg3 = $frame->scope[$op->arg3];
                    $arg1->bool($arg2->equals($arg3));
                    break;
                case OpCode::TYPE_NOT_EQUAL:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    $arg3 = $frame->scope[$op->arg3];
                    $arg1->bool(!$arg2->equals($arg3));
                    break;
                case OpCode::TYPE_LOGICAL_XOR:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    $arg3 = $frame->scope[$op->arg3];
                    $arg1->bool($arg2->toBool($this) !== $arg3->toBool($this));
                    break;
                case OpCode::TYPE_SMALLER:
                case OpCode::TYPE_GREATER:
                case OpCode::TYPE_SMALLER_OR_EQUAL:
                case OpCode::TYPE_GREATER_OR_EQUAL:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    $arg3 = $frame->scope[$op->arg3];
                    try {
                        $arg1->compareOp($op->type, $arg2, $arg3);
                    } catch (\TypeError $e) {
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    break;
                case OpCode::TYPE_SPACESHIP:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    $arg3 = $frame->scope[$op->arg3];
                    try {
                        $arg1->spaceshipOp($arg2, $arg3);
                    } catch (\TypeError $e) {
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
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
                    $catchFrame = $this->enforceReadonlyForCompoundAssign($frame, $op, $arg2);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    try {
                        if (
                            $op->isIncDec
                            && (OpCode::TYPE_PLUS === $op->type || OpCode::TYPE_MINUS === $op->type)
                        ) {
                            $arg1->incDecOp($op->type, $arg2, $arg3);
                        } else {
                            $arg1->numericOp($op->type, $arg2, $arg3);
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
                    }
                    break;
                case OpCode::TYPE_BITWISE_AND:
                case OpCode::TYPE_BITWISE_OR:
                case OpCode::TYPE_BITWISE_XOR:
                case OpCode::TYPE_SHIFT_LEFT:
                case OpCode::TYPE_SHIFT_RIGHT:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    $arg3 = $frame->scope[$op->arg3];
                    $catchFrame = $this->enforceReadonlyForCompoundAssign($frame, $op, $arg2);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $arg1->bitwiseOp($op->type, $arg2, $arg3);
                    break;

                case OpCode::TYPE_UNARY_MINUS:
                case OpCode::TYPE_UNARY_PLUS:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    $arg1->unaryOp($op->type, $arg2, $this, $frame);
                    break;
                case OpCode::TYPE_CONCAT:
                    $arg1 = $frame->scope[$op->arg1];
                    $catchFrame = $this->enforceReadonlyForCompoundAssign($frame, $op, $arg1);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    try {
                        $arg2 = $this->coerceVariableToString($frame->scope[$op->arg2]);
                        $arg3 = $this->coerceVariableToString($frame->scope[$op->arg3]);
                        $arg1->string($arg2 . $arg3);
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
                    } catch (VM\MagicMethodInvocationAborted) {
                        $this->clearTryCatchUnwindState();
                        ++$frame->pos;
                        $frame->suppressNextEcho = true;
                        break;
                    }
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
                        VM\OutputBuffer::append($this->valueToPrintString($frame->scope[$op->arg1]));
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
                    } catch (VM\MagicMethodInvocationAborted) {
                        break;
                    }
                    break;
                case OpCode::TYPE_PRINT:
                    try {
                        if (!VM\SapiOutput::headersSent()) {
                            VM\HeaderCallbackQueue::runBeforeOutput($this->context);
                        }
                        VM\OutputBuffer::append($this->valueToPrintString($frame->scope[$op->arg2]));
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
                    $evalResult = VmEval::evalCodeInFrame(
                        $this,
                        $frame,
                        $codeVar->toString()
                    );
                    if (false === $evalResult) {
                        $dest->bool(false);
                        break;
                    }
                    $dest->copyFrom($evalResult);
                    break;
                case OpCode::TYPE_COALESCE:
                    $takeLeft = $frame->scope[$op->arg2]->toBool();
                    $frame = ($takeLeft ? $op->block1 : $op->block2)->getFrame(
                        $this->context,
                        $frame
                    );
                    goto restart;
                case OpCode::TYPE_NULLSAFE:
                    $receiver = $frame->scope[$op->arg2]->resolveIndirect();
                    $frame = (
                        Variable::TYPE_NULL === $receiver->type
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
                    try {
                        ext\standard\VmExit::terminate($exitArg);
                    } catch (\TypeError $e) {
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    }
                    break;
                case OpCode::TYPE_JUMP:
                    $this->markFinallyCompletedWhenLeavingFinallyBody($frame);
                    $finallyFrame = $this->continueReturnFinallyChain();
                    if (null !== $finallyFrame) {
                        $frame = $finallyFrame;
                        goto restart;
                    }
                    if ($this->schedulePendingReturnDispatch()) {
                        goto restart;
                    }
                    $resumeFrame = $this->resumeCatchAfterFinally($frame);
                    if (null !== $resumeFrame) {
                        $frame = $resumeFrame;
                        goto restart;
                    }
                    $mergeFrame = $this->resumeMergeAfterFinally($frame);
                    if (null !== $mergeFrame) {
                        $frame = $mergeFrame;
                        goto restart;
                    }
                    $gotoFrame = $this->resumeGotoAfterFinally($frame);
                    if (null !== $gotoFrame) {
                        $frame = $gotoFrame;
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
                    $frame = $this->frameForBranch($frame, $op->block1);
                    goto restart;
                case OpCode::TYPE_JUMPIF:
                    $arg1 = $frame->scope[$op->arg1]->toBool();
                    $branchTarget = $arg1 ? $op->block1 : $op->block2;
                    $frame = $this->frameForBranch($frame, $branchTarget);
                    goto restart;
                case OpCode::TYPE_CASE:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
                    if ($arg1->equals($arg2)) {
                        $frame = $op->block1->getFrame($this->context, $frame);
                        goto restart;
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
                        return $this->raise('Unknown constant fetch', $frame);
                    }
                    $frame->scope[$op->arg1]->copyFrom($value);
                    break;
                case OpCode::TYPE_STATICCALL_INIT:
                    try {
                        $classOperand = $frame->scope[$op->arg1]->resolveIndirect();
                        $methodName = $frame->scope[$op->arg2]->toString();
                        $parentKeywordScope = false;
                        if (Variable::TYPE_OBJECT === $classOperand->type) {
                            $classEntry = $classOperand->toObject()->class;
                            $callableName = $classEntry->name.'::'.$methodName;
                        } else {
                            $className = $classOperand->toString();
                            $parentKeywordScope = 'parent' === strtolower($className);
                            $lcClass = $this->resolveClassScopeName($className, $frame);
                            $callableName = $this->context->classes[$lcClass]->name.'::'.$methodName;
                        }
                        $this->initStaticCallable($frame, $callableName, $parentKeywordScope);
                    } catch (\LogicException $e) {
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
                        if (Variable::TYPE_OBJECT !== $classOperand->type) {
                            $catchFrame = $this->dispatchVmTypeError(
                                new \TypeError(
                                    'Cannot use "::class" on value of type '
                                    .$this->valueDebugTypeLabel($classOperand)
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
                        $constLc = strtolower($memberNameRaw);
                        if (isset($classEntry->constants[$constLc])) {
                            $visFrame = $this->enforceClassConstVisibility($classEntry, $memberNameRaw, $frame);
                            if (null !== $visFrame) {
                                $frame = $visFrame;
                                goto restart;
                            }
                        }
                        if (!$this->copyClassConstOrStaticPropertyByName(
                            $classEntry,
                            $memberNameRaw,
                            $frame->scope[$op->arg1],
                            $frame
                        )) {
                            return $this->raise(
                                "Undefined class constant {$classEntry->name}::{$memberNameRaw}",
                                $frame
                            );
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
                        return $this->raise($e->getMessage(), $frame);
                    }
                    $className = $frame->scope[$op->arg2]->resolveIndirect()->toString();
                    if (!isset($this->context->classes[$lcClass])) {
                        if ('self' !== strtolower($className) && 'static' !== strtolower($className)) {
                            $this->context->autoloadClass($className);
                        }
                    }
                    if (!isset($this->context->classes[$lcClass])) {
                        return $this->raise("Unknown class for constant fetch: {$className}", $frame);
                    }
                    $classEntry = $this->context->classes[$lcClass];
                    $constLc = strtolower($memberNameRaw);
                    if (isset($classEntry->constants[$constLc])) {
                        $visFrame = $this->enforceClassConstVisibility($classEntry, $memberNameRaw, $frame);
                        if (null !== $visFrame) {
                            $frame = $visFrame;
                            goto restart;
                        }
                    }
                    if (!$this->copyClassConstOrStaticPropertyByName(
                        $classEntry,
                        $memberNameRaw,
                        $frame->scope[$op->arg1],
                        $frame
                    )) {
                        return $this->raise("Undefined class constant {$className}::{$memberNameRaw}", $frame);
                    }
                    break;
                case OpCode::TYPE_INSTANCEOF:
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
                        $className = strtolower($frame->scope[$op->arg3]->toString());
                        $matches = $this->valueInstanceOfClassName($value, $className);
                    }
                    $frame->scope[$op->arg1]->bool($matches);
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
                    $rawClass = $frame->scope[$op->arg2]->toString();
                    $lcClass = $this->resolveStaticClassName(
                        $rawClass,
                        $frame
                    );
                    if (!isset($this->context->classes[$lcClass])) {
                        if ('self' !== strtolower($rawClass) && 'static' !== strtolower($rawClass)) {
                            $this->context->autoloadClass($rawClass);
                        }
                    }
                    if (!isset($this->context->classes[$lcClass])) {
                        return $this->raise("Unknown class for static property fetch: {$rawClass}", $frame);
                    }
                    $propNameRaw = $frame->scope[$op->arg3]->toString();
                    $propName = strtolower($propNameRaw);
                    $storage = $this->resolveStaticPropertyStorage($lcClass, $propName);
                    if (null === $storage) {
                        $classLabel = $this->context->classes[$lcClass]->name;

                        return $this->raise(
                            "Access to undeclared static property {$classLabel}::\${$propNameRaw}",
                            $frame
                        );
                    }
                    $forWrite = $frame->pos < $frame->block->nOpCodes
                        && OpCode::TYPE_ASSIGN === $frame->block->opCodes[$frame->pos]->type
                        && (int) $frame->block->opCodes[$frame->pos]->arg2 === (int) $op->arg1;
                    $hooks = $this->resolveStaticPropertyHooks($lcClass, $propName);
                    if (
                        !$forWrite
                        && null !== $hooks
                        && isset($hooks['get'])
                        && !$this->isPropertyHookRawWrite($frame, $propNameRaw)
                    ) {
                        $hookValue = $this->fetchStaticPropertyWithHooks($lcClass, $propNameRaw, $hooks['get'], $frame);
                        $frame->scope[$op->arg1]->copyFrom($hookValue);
                        break;
                    }
                    if (
                        $forWrite
                        && null !== $hooks
                        && isset($hooks['set'])
                        && !$this->isPropertyHookRawWrite($frame, $propNameRaw)
                    ) {
                        $frame->scope[$op->arg1]->indirect($storage);
                        $storage->staticPropertyClassLc = $lcClass;
                        $storage->objectPropertyName = $propNameRaw;
                        break;
                    }
                    $frame->scope[$op->arg1]->indirect($storage);
                    break;
                case OpCode::TYPE_STATIC_PROPERTY_UNSET:
                    $rawClass = $frame->scope[$op->arg2]->toString();
                    $lcClass = $this->resolveStaticClassName($rawClass, $frame);
                    if (!isset($this->context->classes[$lcClass])) {
                        if ('self' !== strtolower($rawClass) && 'static' !== strtolower($rawClass)) {
                            $this->context->autoloadClass($rawClass);
                        }
                    }
                    if (!isset($this->context->classes[$lcClass])) {
                        return $this->raise("Unknown class for static property unset: {$rawClass}", $frame);
                    }
                    $propNameRaw = $frame->scope[$op->arg3]->toString();
                    $propName = strtolower($propNameRaw);
                    $storage = $this->resolveStaticPropertyStorage($lcClass, $propName);
                    if (null === $storage) {
                        $classLabel = $this->context->classes[$lcClass]->name;

                        return $this->raise(
                            "Access to undeclared static property {$classLabel}::\${$propNameRaw}",
                            $frame
                        );
                    }
                    $storage->reset();
                    $storage->type = Variable::TYPE_UNDEFINED;
                    break;
                case OpCode::TYPE_UNSET:
                    if (null === $op->arg3) {
                        if (null !== $op->arg2 && isset($frame->scope[$op->arg2])) {
                            $slot = $frame->scope[$op->arg2];
                            if (Variable::TYPE_INDIRECT === $slot->type) {
                                $target = $slot->resolveIndirect();
                                $target->reset();
                                $target->type = Variable::TYPE_UNDEFINED;
                            } else {
                                $slot->resolveIndirect()->null();
                            }
                        }
                        break;
                    }
                    $containerSlot = $frame->scope[$op->arg2];
                    $container = $containerSlot->resolveIndirect();
                    $key = $frame->scope[$op->arg3];
                    if (Variable::TYPE_OBJECT === $container->type) {
                        $object = $container->toObject();
                        if ($this->objectImplementsArrayAccess($object)) {
                            $this->invokeArrayAccessOffsetUnset($object, $key);
                            break;
                        }
                        $propName = $key->toString();
                        $catchFrame = $this->enforceReadonlyPropertyUnset($object, $propName, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        $this->unsetObjectProperty($object, $propName);
                        break;
                    }
                    if (Variable::TYPE_ARRAY === $container->type) {
                        $container->separateArrayForWrite();
                        $container = $containerSlot->resolveIndirect();
                        $container->toArray()->offsetUnset($key);
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
                case OpCode::TYPE_CLOSURE:
                    if (null === $op->block1) {
                        $frame->scope[$op->arg1]->null();
                        break;
                    }
                    $funcName = null !== $op->block1->func
                        ? $op->block1->func->name
                        : '{closure}';
                    $closureFunc = new Func\PHP($funcName, $op->block1);
                    $captures = $this->bindClosureCaptures($frame, $op->closureCaptures);
                    $state = new ClosureState($closureFunc, $captures);
                    if (
                        null !== $frame->block->func
                        && null !== $frame->block->func->class
                        && null !== $frame->block->func->class->value
                        && '' !== $frame->block->func->class->value
                    ) {
                        // Preserve declaring scope on the closure function so self:: resolves like Zend.
                        if (null !== $op->block1->func) {
                            $op->block1->func->class = $frame->block->func->class;
                        }

                        // Preserve late-static binding (static::) from the creation scope (called class).
                        $called = $this->inferCalledClass($frame);
                        $state->boundScopeClass = null !== $called && '' !== $called
                            ? $called
                            : $frame->block->func->class->value;
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
                    $finallyFrame = $this->beginReturnFinallyUnwind($frame, null, true);
                    if (null !== $finallyFrame) {
                        $frame = $finallyFrame;
                        goto restart;
                    }
                    goto return_void_complete;
                case OpCode::TYPE_RETURN:
                    if (isset($frame->scope[$op->arg1])) {
                        $returnValue = $frame->scope[$op->arg1]->resolveIndirect();
                    } elseif (isset($frame->block->constants[$op->arg1])) {
                        $returnValue = $frame->block->constants[$op->arg1];
                    } else {
                        $returnValue = new Variable(Variable::TYPE_NULL);
                    }
                    $finallyFrame = $this->beginReturnFinallyUnwind($frame, $returnValue, false);
                    if (null !== $finallyFrame) {
                        $frame = $finallyFrame;
                        goto restart;
                    }
                    goto return_value_complete;
                case OpCode::TYPE_FUNCDEF:
                    $name = $frame->scope[$op->arg1]->toString();
                    $lcname = strtolower($name);
                    if (isset($this->context->functions[$lcname])) {
                        throw new \LogicException("Duplicate function definition for $lcname()");
                    }
                    $func = new Func\PHP($name, $op->block1);
                    $func->deprecated = $op->deprecatedMetadata;
                    $this->context->declareFunction($func);
                    break;
                case OpCode::TYPE_FUNCCALL_INIT:
                    $callee = $frame->scope[$op->arg1]->resolveIndirect();
                    if (Variable::TYPE_OBJECT === $callee->type) {
                        $closureState = $callee->toObject()->closureState;
                        if (null !== $closureState) {
                            $this->initClosureCall($frame, $closureState);
                            break;
                        }
                        $catchFrame = $this->initMethodCall($frame, $callee, '__invoke');
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    if (Variable::TYPE_ARRAY === $callee->type) {
                        $this->initArrayCallable($frame, $callee);
                        break;
                    }
                    $name = $callee->toString();
                    if (str_contains($name, '::')) {
                        $this->initStaticCallable($frame, $name);
                        break;
                    }
                    $lcname = strtolower($name);
                    if (!isset($this->context->functions[$lcname])) {
                        throw new \LogicException("Call to undefined function $lcname()");
                    }
                    $frame->call = $this->context->functions[$lcname];
                    $frame->callArgs = [];
                    $frame->callArgEntries = [];
                    break;
                case OpCode::TYPE_METHODCALL_INIT:
                    $receiver = $frame->scope[$op->arg1]->resolveIndirect();
                    if ($receiver->type !== Variable::TYPE_OBJECT) {
                        throw new \LogicException('Method call on non-object');
                    }
                    $catchFrame = $this->initMethodCall(
                        $frame,
                        $receiver,
                        $frame->scope[$op->arg2]->toString()
                    );
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    break;
                case OpCode::TYPE_ARG_SEND:
                    $value = $frame->scope[$op->arg1];
                    if (null !== $op->arg3) {
                        $frame->callArgEntries[] = ['u', $value];
                        break;
                    }
                    if (null !== $op->arg2 && isset($frame->block->constants[$op->arg2])) {
                        $frame->callArgEntries[] = [
                            'n',
                            $frame->block->constants[$op->arg2]->toString(),
                            $value,
                        ];
                    } else {
                        $frame->callArgEntries[] = ['p', $value];
                    }
                    break;
                case OpCode::TYPE_FUNCCALL_EXEC_RETURN:
                case OpCode::TYPE_FUNCCALL_EXEC_NORETURN:
                    if (is_null($frame->call)) {
                        // Used for null constructors, etc
                        $this->markPendingNewObjectConstructed($frame);
                        break;
                    }
                    $frame->callSiteLine = OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type
                        ? (int) ($op->arg2 ?? 0)
                        : (int) ($op->arg1 ?? 0);
                    $this->emitCallDeprecationNotice($frame);
                    if ($frame->call instanceof Func\PHP && $frame->call->block->isGenerator) {
                        try {
                            $calledArgs = $this->resolveOutgoingCallArgs($frame);
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
                        $state = new GeneratorState($this, $frame->call, $calledArgs);
                        if (OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type) {
                            $this->scopeSlot($frame, (int) $op->arg1)->object($state->wrapObject());
                        }
                        $frame->call = null;
                        $frame->callArgs = [];
                        $frame->callArgEntries = [];
                        break;
                    }
                    try {
                        $calledArgs = $this->resolveOutgoingCallArgs($frame);
                        // Zend strict_types is a *caller* (call-site) rule; enforce scalar param checks
                        // before entering the callee so exceptions abort argument evaluation correctly.
                        if (
                            $frame->call instanceof Func\PHP
                            && $frame->block->strictTypes
                            && [] !== $calledArgs
                        ) {
                            $callSiteLine = OpCode::TYPE_FUNCCALL_EXEC_RETURN === $op->type
                                ? (int) ($op->arg2 ?? 0)
                                : (int) ($op->arg1 ?? 0);
                            $calleeBlock = $frame->call->block;
                            foreach ($calleeBlock->opCodes as $recv) {
                                if (OpCode::TYPE_ARG_RECV !== $recv->type) {
                                    continue;
                                }
                                $paramIdx = (int) $recv->arg2;
                                if (!array_key_exists($paramIdx, $calledArgs)) {
                                    continue;
                                }
                                $slot = (int) $recv->arg1;
                                $arg = $calledArgs[$paramIdx];
                                if (
                                    TypeCheck::skipParameterTypeCheckForImplicitNullable(
                                        $calleeBlock,
                                        $slot,
                                        $arg
                                    )
                                ) {
                                    continue;
                                }
                                if (isset($calleeBlock->paramIterableSlots[$slot])) {
                                    if (!IterableCheck::isIterable($arg, $this->context)) {
                                        $paramName = $calleeBlock->paramNames[$paramIdx] ?? 'param'.$paramIdx;
                                        throw VM\ParamTypeError::forUserCallWithExpectedType(
                                            $frame->call->getName(),
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
                                $constraint = $calleeBlock->paramTypeConstraints[$slot] ?? null;
                                if (null === $constraint) {
                                    continue;
                                }
                                $literalBool = $calleeBlock->paramLiteralBoolTypes[$slot] ?? null;
                                if (!TypeCheck::parameterMatchesType($arg, $constraint, $literalBool)) {
                                    $paramName = $calleeBlock->paramNames[$paramIdx] ?? 'param'.$paramIdx;
                                    throw VM\ParamTypeError::forUserCall(
                                        $frame->call->getName(),
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
                    $this->applyClosureBinding($new, $frame->closureCall);
                    $frame->closureCall = null;
                    if (null !== $new->block && null !== $new->block->func && (int) ($new->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) {
                        $thisIdx = $new->block->slotIndexForVariableName('this');
                        if (null !== $thisIdx) {
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

                            return self::FIBER_SUSPEND;
                        }
                        $frame->call = null;
                        $frame->callArgs = [];
                        $frame->callArgEntries = [];
                        break;
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
                    ) {
                        ++$recvIdx;
                    }
                    $isVariadicSlot = null !== $frame->block->variadicParamIndex
                        && $frame->block->variadicParamIndex === (int) $op->arg2;
                    if ($isVariadicSlot) {
                        $variadicSlot = (int) $op->arg1;
                        $strict = null !== $frame->parent
                            ? $frame->parent->block->strictTypes
                            : $frame->block->strictTypes;
                        $n = count($frame->calledArgs);
                        try {
                            if (TypeCheck::variadicSlotNeedsElementChecks($frame->block, $variadicSlot)) {
                                $trailing = [];
                                for ($i = $recvIdx; $i < $n; ++$i) {
                                    $trailing[] = $frame->calledArgs[$i];
                                }
                                TypeCheck::verifyVariadicElements(
                                    $trailing,
                                    $strict,
                                    $frame->block->paramVariadicElementTypeConstraints[$variadicSlot] ?? null,
                                    $frame->block->paramVariadicElementGenericArrayTypeSpecs[$variadicSlot] ?? null,
                                    $frame->block->paramVariadicElementIntersectionConstraints[$variadicSlot] ?? null,
                                    $frame->block->paramVariadicElementDnfConstraints[$variadicSlot] ?? null,
                                    $this->context,
                                    isset($frame->block->paramIterableSlots[$variadicSlot])
                                );
                            }
                            if (
                                1 === $n - $recvIdx
                                && isset($frame->calledArgs[$recvIdx])
                            ) {
                                $sole = $frame->calledArgs[$recvIdx]->resolveIndirect();
                                if (
                                    Variable::TYPE_ARRAY === $sole->type
                                    && !$sole->toArray()->isPackedList()
                                ) {
                                    $arg1->copyFrom($sole);
                                    break;
                                }
                            }
                            $arg1->newArray();
                            $packed = $arg1->toArray();
                            for ($i = $recvIdx; $i < $n; ++$i) {
                                $copy = new Variable();
                                $copy->copyFrom($frame->calledArgs[$i]);
                                $packed->append($copy);
                            }
                        } catch (\TypeError $e) {
                            $catchFrame = $this->dispatchVmTypeError($e, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                        }
                        break;
                    }
                    if (array_key_exists($recvIdx, $frame->calledArgs)) {
                        if (isset($frame->block->paramByRef[(int) $op->arg2])) {
                            $arg1->indirect($frame->calledArgs[$recvIdx]);
                        } else {
                            $arg1->copyFrom($frame->calledArgs[$recvIdx]);
                        }
                    } elseif (null !== $op->arg3 && isset($frame->block->constants[$op->arg3])) {
                        $arg1->copyFrom($frame->block->constants[$op->arg3]);
                    } else {
                        throw new \LogicException('Missing required argument ' . $op->arg2);
                    }
                    $strict = null !== $frame->parent
                        ? $frame->parent->block->strictTypes
                        : $frame->block->strictTypes;
                    $arraySpec = $frame->block->paramGenericArrayTypeSpecs[$op->arg1] ?? null;
                    try {
                        if (
                            !TypeCheck::skipParameterTypeCheckForImplicitNullable(
                                $frame->block,
                                (int) $op->arg1,
                                $arg1
                            )
                        ) {
                            if (isset($frame->block->paramIterableSlots[$op->arg1])) {
                                IterableCheck::assertParameter($arg1, $this->context);
                            } else {
                                TypeCheck::coerceParameter($arg1, $strict, $arraySpec);
                            }
                        }
                        if (isset($frame->block->paramIntersectionConstraints[$op->arg1])) {
                            TypeCheck::assertParamIntersection(
                                $arg1,
                                $frame->block->paramIntersectionConstraints[$op->arg1],
                                $this->context
                            );
                        }
                        if (isset($frame->block->paramDnfConstraints[$op->arg1])) {
                            DnfCheck::assertMatches(
                                $arg1,
                                $frame->block->paramDnfConstraints[$op->arg1],
                                $this->context
                            );
                        }
                    } catch (\TypeError $e) {
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    }
                    break;
                case OpCode::TYPE_DECLARE_INTERFACE:
                    $name = $frame->scope[$op->arg1]->toString();
                    $lcname = strtolower($name);
                    if (isset($this->context->classes[$lcname])) {
                        throw new \LogicException("Duplicate interface definition for $name");
                    }
                    $ifaceEntry = new VM\ClassEntry($name);
                    $ifaceEntry->isInterface = true;
                    $ifaceEntry->interfaces = $op->classImplements;
                    if ($op->isSealed) {
                        $ifaceEntry->sealed = true;
                        $ifaceEntry->sealedPermits = $this->normalizeSealedPermits($name, $op->sealedPermits);
                    }
                    if (null !== $op->block1) {
                        self::defineClass($ifaceEntry, $op->block1);
                    }
                    $this->inheritFromInterfaces($ifaceEntry);
                    $this->context->classes[$lcname] = $ifaceEntry;
                    break;
                case OpCode::TYPE_DECLARE_TRAIT:
                    $name = $frame->scope[$op->arg1]->toString();
                    $lcname = strtolower($name);
                    if (isset($this->context->classes[$lcname])) {
                        throw new \LogicException("Duplicate trait definition for $name");
                    }
                    $traitEntry = new ClassEntry($name);
                    $traitEntry->isTrait = true;
                    $traitEntry->attributeNames = $op->attributeNames;
                    $traitEntry->attributeEntries = $op->attributeEntries;
                    self::defineClass($traitEntry, $op->block1);
                    $this->context->classes[$lcname] = $traitEntry;
                    break;
                case OpCode::TYPE_DECLARE_GLOBAL_CONST:
                    $name = $frame->scope[$op->arg1]->toString();
                    if (isset($frame->block->constants[$op->arg2])) {
                        $constValue = $frame->block->constants[$op->arg2];
                    } elseif (isset($frame->scope[$op->arg2])) {
                        $constValue = VM\ClassConstMaterializer::detachConstantValue($frame->scope[$op->arg2]);
                    } else {
                        throw new \LogicException('Global constant value must be a compile-time constant');
                    }
                    if (!$this->context->defineConstant($name, $constValue)) {
                        throw new \LogicException("Cannot redefine constant {$name}");
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
                    if (null !== $op->arg2 && isset($frame->block->constants[$op->arg2])) {
                        $classEntry->backedType = $frame->block->constants[$op->arg2]->toString();
                    }
                    $classEntry->interfaces = $op->classImplements;
                    $classEntry->isAbstract = $op->classIsAbstract;
                    self::defineClass($classEntry, $op->block1);
                    VM\EnumSupport::ensureBuiltinCasesMethod($classEntry);
                    VM\EnumSupport::ensureBuiltinEnumInterfaces($classEntry);
                    $this->context->classes[$lcname] = $classEntry;
                    $this->context->enums[$lcname] = true;
                    break;
                case OpCode::TYPE_DECLARE_CLASS:
                    $name = $frame->scope[$op->arg1]->toString();
                    $lcname = strtolower($name);
                    if (isset($this->context->classes[$lcname])) {
                        throw new \LogicException("Duplicate class definition for $name");
                    }
                    $classEntry = new ClassEntry($name);
                    $classEntry->interfaces = $op->classImplements;
                    if (null !== $op->arg2) {
                        $parentName = $frame->scope[$op->arg2]->toString();
                        $parentLc = strtolower($parentName);
                        if (!isset($this->context->classes[$parentLc])) {
                            $this->context->autoloadClass($parentName);
                        }
                        if (!isset($this->context->classes[$parentLc])) {
                            throw new \LogicException("Class {$name} extends unknown class {$parentName}");
                        }
                        $classEntry->parentLc = $parentLc;
                    }
                    if (null !== $op->arg3 && isset($frame->block->constants[$op->arg3])) {
                        $classFlags = $frame->block->constants[$op->arg3]->toInt();
                        $classEntry->readonly = VM\ClassFlags::isReadonly($classFlags);
                        $classEntry->isAbstract = VM\ClassFlags::isAbstract($classFlags);
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
                    self::defineClass($classEntry, $op->block1);
                    if (null !== $classEntry->parentLc) {
                        $this->inheritFromParent($classEntry);
                    }
                    $this->inheritFromInterfaces($classEntry);
                    VM\ClassValidator::finalizeClassDefinition($classEntry, $this->context);
                    $this->context->classes[$lcname] = $classEntry;
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
                    $object = new ObjectEntry($class);
                    $this->initInstancePropertyDefaults($object);
                    if (null !== $op->arg3 && VM\ExceptionSupport::classEntryImplementsThrowable($class, $this->context)) {
                        $newLine = (int) $op->arg3;
                        if ($newLine > 0) {
                            $object->getProperty(VM\ExceptionSupport::PROP_LINE)->int($newLine);
                        }
                    }
                    $result->object($object);
                    $frame->call = $object->constructor;
                    $frame->callArgs = [$result];
                    $frame->callArgEntries = [];
                    if (null === $frame->call) {
                        $object->constructed = true;
                    }
                    break;
                case OpCode::TYPE_PROPERTY_FETCH:
                    $result = $frame->scope[$op->arg1];
                    $var = $frame->scope[$op->arg2]->resolveIndirect();
                    $name = $frame->scope[$op->arg3]->toString();
                    if (Variable::TYPE_ENUM_CASE === $var->type) {
                        try {
                            $prop = $var->toEnumCase()->fetchProperty($name);
                        } catch (\LogicException $e) {
                            return $this->raise($e->getMessage(), $frame);
                        }
                        $result->copyFrom($prop);
                        break;
                    }
                    if ($var->type !== Variable::TYPE_OBJECT) {
                        throw new \LogicException("Unsupported property fetch on non-object");
                    }
                    $propertyObject = $var->toObject();
                    VM\LazyObjectSupport::ensureInitialized($this, $propertyObject);
                    if (EnumCaseSupport::isEnumCase($propertyObject)) {
                        $result->copyFrom(EnumCaseSupport::getProperty($propertyObject, $name));
                        break;
                    }
                    $forWrite = $frame->pos < $frame->block->nOpCodes
                        && OpCode::TYPE_ASSIGN === $frame->block->opCodes[$frame->pos]->type
                        && (int) $frame->block->opCodes[$frame->pos]->arg2 === (int) $op->arg1;
                    $magicGetForRead = !$forWrite
                        && $this->propertyReadUsesMagicGet($propertyObject, $name, $frame);
                    if (!$magicGetForRead) {
                        $catchFrame = $this->enforcePropertyVisibilityRead($propertyObject, $name, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    }
                    if ($propertyObject->hasProperty($name) && !$magicGetForRead) {
                        if ($forWrite) {
                            $result->indirect($this->fetchObjectPropertyWriteLvalue($propertyObject, $name, $frame));
                            break;
                        }
                        $hookValue = $this->fetchPropertyWithHooks($propertyObject, $name, $frame);
                        if (null !== $hookValue) {
                            $result->copyFrom($hookValue);
                        } else {
                            $result->indirect($propertyObject->getProperty($name));
                        }
                        break;
                    }
                    if ($forWrite) {
                        $catchFrame = $this->enforceReadonlyDynamicPropertyCreate($propertyObject, $name, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        $result->indirect($this->fetchObjectPropertyWriteLvalue($propertyObject, $name, $frame));
                        break;
                    }
                    if ($magicGetForRead) {
                        $this->deliverMagicGetRead($result, $propertyObject, $name);
                        break;
                    }
                    if ($propertyObject->class->allowsDynamicProperties) {
                        $result->indirect($propertyObject->allocateProperty($name));
                        break;
                    }
                    throw new \LogicException('Undefined property access');
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
                            $ht->append($frame->scope[$op->arg2]);
                            break;
                        }
                        $key = $frame->scope[$op->arg3]->resolveIndirect();
                        $value = $frame->scope[$op->arg2];
                        if ($key->is(Variable::TYPE_OBJECT) || $key->is(Variable::TYPE_ARRAY)) {
                            throw new \TypeError('Illegal offset type');
                        }
                        if ($key->is(Variable::TYPE_INTEGER) || $key->is(Variable::TYPE_FLOAT)) {
                            $ht->updateIndex($key->toInt(), $value);
                        } elseif ($key->is(Variable::TYPE_STRING)) {
                            $ht->update($key->toString(), $value);
                        } elseif ($key->is(Variable::TYPE_BOOLEAN)) {
                            $ht->updateIndex($key->toBool() ? 1 : 0, $value);
                        } else {
                            throw new \TypeError('Illegal offset type');
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
                    $result = $frame->scope[$op->arg1];
                    $source = $frame->scope[$op->arg2];
                    try {
                        VM\ArraySpread::spreadInto($this, $frame, $result->toArray(), $source);
                    } catch (\TypeError $e) {
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    }
                    break;
                case OpCode::TYPE_CLONE:
                    $result = $frame->scope[$op->arg1];
                    $src = $frame->scope[$op->arg2]->resolveIndirect();
                    if (Variable::TYPE_ENUM_CASE === $src->type) {
                        $enumCase = $src->toEnumCase();
                        $message = 'Cannot clone enum case '
                            .$enumCase->enumClass->name.'::'.$enumCase->caseName;
                        $catchFrame = $this->dispatchVmError($message, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    if (Variable::TYPE_OBJECT !== $src->type) {
                        throw new \LogicException('clone requires an object');
                    }
                    $cloned = $src->toObject()->cloneShallow();
                    $result->object($cloned);
                    $this->invokeCloneMagicMethod($cloned);
                    break;
                case OpCode::TYPE_BOOLEAN_NOT:
                    $value = !($frame->scope[$op->arg2]->toBool());
                    $dst = $frame->scope[$op->arg1];
                    $dst->bool($value);
                    break;
                case OpCode::TYPE_EMPTY:
                    $v = $frame->scope[$op->arg2]->resolveIndirect();
                    $frame->scope[$op->arg1]->bool(!ext\standard\boolval::isTruthy($v));
                    break;
                case OpCode::TYPE_ISSET:
                    $dst = $frame->scope[$op->arg1];
                    if (null !== $op->arg3) {
                        $container = $frame->scope[$op->arg2]->resolveIndirect();
                        if (Variable::TYPE_ARRAY === $container->type) {
                            $dst->bool($container->toArray()->offsetIsSet($frame->scope[$op->arg3]));
                            break;
                        }
                        if (Variable::TYPE_OBJECT === $container->type) {
                            $object = $container->toObject();
                            if ($this->objectImplementsArrayAccess($object)) {
                                $dst->bool($this->invokeArrayAccessOffsetExists(
                                    $object,
                                    $frame->scope[$op->arg3]
                                ));
                                break;
                            }
                            $propName = $frame->scope[$op->arg3]->toString();
                            VM\LazyObjectSupport::ensureInitialized($this, $object);
                            $dst->bool($this->objectPropertyIsSet($object, $propName, $frame));
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
                        $file = $frame->scope[$op->arg1]->toString();
                    }

                    $kind = $op->includeKind ?? OpCode::INCLUDE_KIND_INCLUDE_ONCE;
                    $once = $kind === OpCode::INCLUDE_KIND_INCLUDE_ONCE || $kind === OpCode::INCLUDE_KIND_REQUIRE_ONCE;
                    $isRequire = $kind === OpCode::INCLUDE_KIND_REQUIRE || $kind === OpCode::INCLUDE_KIND_REQUIRE_ONCE;

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
                            $gen->currentValue->copyFrom($frame->scope[$op->arg2]->resolveIndirect());
                        } elseif (isset($frame->block->constants[$op->arg2])) {
                            $gen->currentValue->copyFrom($frame->block->constants[$op->arg2]);
                        } else {
                            $gen->currentValue->null();
                        }
                    } else {
                        $gen->currentValue->null();
                    }
                    if (null !== $op->arg3) {
                        if (isset($frame->scope[$op->arg3])) {
                            $gen->currentKey->copyFrom($frame->scope[$op->arg3]->resolveIndirect());
                        } elseif (isset($frame->block->constants[$op->arg3])) {
                            $gen->currentKey->copyFrom($frame->block->constants[$op->arg3]);
                        } else {
                            $gen->currentKey->int($gen->autoKey++);
                        }
                    } else {
                        $gen->currentKey->int($gen->autoKey++);
                    }
                    if (null !== $op->arg1) {
                        $gen->yieldResultSlot = $op->arg1;
                    }
                    $gen->hasCurrent = true;
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
                            $iterable = VM\ForeachIterator::resolveTraversableObject($this, $frame, $container);
                            $gen->yieldFromContainer->copyFrom($iterable);
                            if ($this->variableIsGenerator($iterable)) {
                                $iterable->toObject()->generatorState->rewind();
                            } else {
                                $this->invokeForeachInstanceMethod($frame, $iterable, 'rewind');
                            }
                        } else {
                            throw new \TypeError('Can only use yield from on Traversable|array');
                        }
                    }
                    $container = $gen->yieldFromContainer->resolveIndirect();
                    if (Variable::TYPE_ARRAY === $container->type) {
                        if ($container->toArray()->iterValid()) {
                            $gen->currentKey->copyFrom($container->toArray()->iterCurrentKey());
                            $gen->currentValue->copyFrom($container->toArray()->iterCurrentValue(false));
                            $gen->hasCurrent = true;
                            $gen->frame = $frame;
                            $frame->pos--;
                            $frame->generatorYield = true;
                            break;
                        }
                        $gen->yieldFromActive = false;
                        $gen->yieldFromIteratorAdvance = false;
                        break;
                    }
                    if ($this->variableIsGenerator($container)) {
                        $inner = $container->toObject()->generatorState;
                        if ($this->advanceGeneratorIteration($inner)) {
                            $gen->currentKey->copyFrom($inner->currentKey);
                            $gen->currentValue->copyFrom($inner->currentValue);
                            $gen->hasCurrent = true;
                            $gen->frame = $frame;
                            $frame->pos--;
                            $frame->generatorYield = true;
                            break;
                        }
                        $gen->yieldFromActive = false;
                        $gen->yieldFromIteratorAdvance = false;
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
                            $gen->currentValue->copyFrom(
                                $this->invokeForeachInstanceMethod($frame, $container, 'current')
                            );
                            $gen->yieldFromIteratorAdvance = true;
                            $gen->hasCurrent = true;
                            $gen->frame = $frame;
                            $frame->pos--;
                            $frame->generatorYield = true;
                            break;
                        }
                        $gen->yieldFromActive = false;
                        $gen->yieldFromIteratorAdvance = false;
                        break;
                    }
                    throw new \TypeError('Can only use yield from on Traversable|array');
                case OpCode::TYPE_ITER_RESET:
                    $container = $frame->scope[$op->arg1]->resolveIndirect();
                    unset($this->context->foreachInvalidSlots[$op->arg1]);
                    if ($this->variableIsGenerator($container)) {
                        unset($this->context->foreachObjectAdvance[$op->arg1]);
                        unset($this->context->objectPropertyIterators[$op->arg1]);
                        $frame->iterators[$op->arg1] = $container;
                        $this->context->foreachIterators[$op->arg1] = $container;
                        $container->toObject()->generatorState->rewind();
                        break;
                    }
                    if (Variable::TYPE_ARRAY === $container->type) {
                        unset($this->context->foreachObjectAdvance[$op->arg1]);
                        unset($this->context->objectPropertyIterators[$op->arg1]);
                        $frame->iterators[$op->arg1] = $container;
                        $this->context->foreachIterators[$op->arg1] = $container;
                        $container->toArray()->iterReset();
                        break;
                    }
                    if (Variable::TYPE_OBJECT === $container->type) {
                        try {
                            unset($this->context->objectPropertyIterators[$op->arg1]);
                            $iterable = VM\ForeachIterator::resolveTraversableObject($this, $frame, $container);
                            $frame->iterators[$op->arg1] = $iterable;
                            $this->context->foreachIterators[$op->arg1] = $iterable;
                            if ($this->variableIsGenerator($iterable)) {
                                unset($this->context->foreachObjectAdvance[$op->arg1]);
                                $iterable->toObject()->generatorState->rewind();
                                break;
                            }
                            $this->context->foreachObjectAdvance[$op->arg1] = false;
                            $this->invokeForeachInstanceMethod($frame, $iterable, 'rewind');
                            break;
                        } catch (\TypeError) {
                            unset($this->context->foreachObjectAdvance[$op->arg1]);
                            $iter = new ObjectPropertyIterator($container->toObject());
                            $iter->reset();
                            $this->context->objectPropertyIterators[$op->arg1] = $iter;
                            break;
                        }
                    }
                    $this->warnForeachNonTraversable($container, $frame);
                    unset($this->context->foreachObjectAdvance[$op->arg1]);
                    unset($this->context->objectPropertyIterators[$op->arg1]);
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
                        $frame->scope[$op->arg1]->bool(
                            $this->objectForeachIterator($op->arg2)->valid()
                        );
                        break;
                    }
                    if (Variable::TYPE_ARRAY !== $container->type) {
                        throw new \LogicException('Iterator valid requires an array');
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
                        $frame->scope[$op->arg1]->copyFrom(
                            $this->objectForeachIterator($op->arg2)->currentKey()
                        );
                        break;
                    }
                    if (Variable::TYPE_ARRAY !== $container->type) {
                        throw new \LogicException('Iterator key requires an array');
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
                        $frame->scope[$op->arg1]->copyFrom(
                            $container->toObject()->generatorState->currentValue
                        );
                        break;
                    }
                    if (Variable::TYPE_OBJECT === $container->type) {
                        $byRef = (bool) $op->arg3;
                        if ($byRef) {
                            $frame->scope[$op->arg1]->indirect(
                                $this->objectForeachIterator($op->arg2)->currentValue(true)
                            );
                        } else {
                            $frame->scope[$op->arg1]->copyFrom(
                                $this->objectForeachIterator($op->arg2)->currentValue(false)
                            );
                        }
                        break;
                    }
                    if (Variable::TYPE_ARRAY !== $container->type) {
                        throw new \LogicException('Iterator value requires an array');
                    }
                    $byRef = (bool) $op->arg3;
                    if ($byRef) {
                        $frame->scope[$op->arg1]->indirect(
                            $container->toArray()->iterCurrentValue(true)
                        );
                    } else {
                        $frame->scope[$op->arg1]->copyFrom(
                            $container->toArray()->iterCurrentValue(false)
                        );
                    }
                    break;
                case OpCode::TYPE_TRY:
                    $this->context->activeTryHandlerFrames[] = $frame;
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
                            if (null !== $op->arg3) {
                                if (!isset($frame->scope[$op->arg3])) {
                                    $frame->scope[$op->arg3] = new Variable();
                                }
                                $frame->scope[$op->arg3]->copyFrom($caught);
                            }
                            $frame->activeCatchException = $caught;
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
                    if ($this->frameIsPropertySetHook($frame)) {
                        $this->context->propertyHookSetAborted = true;
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
                        VM\ExceptionTrace::captureOnThrow($frame, $thrown);
                    }
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
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
            }
            if ($frame->generatorYield) {
                $frame->generatorYield = false;

                return self::GENERATOR_YIELD;
            }
            if ($frame->fiberSuspend) {
                $frame->fiberSuspend = false;

                return self::FIBER_SUSPEND;
            }
        }
        if ($frame->ephemeral) {
            $this->context->scriptStack->pop();
            if (null !== $frame->parent) {
                $this->markObjectConstructedIfLeavingConstruct($frame);
                $child = $frame;
                $frame = $frame->parent;
                $this->releaseFrameObjectRefs($child);
                goto restart;
            }
            $this->releaseFrameObjectRefs($frame);
            goto nextframe;
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
            $child = $frame;
            $frame = $frame->parent;
            $this->releaseFrameObjectRefs($child);
            goto restart;
        }
        $this->releaseFrameObjectRefs($frame);
        goto nextframe;

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
            $caller->callArgs = [];
            $caller->callArgEntries = [];
            $frame = $caller;
            goto restart;
        }
        // Nested return <call>(): callee may finish with an empty run stack (#1885).
        if (null !== $frame->parent && null !== $frame->returnVar) {
            $child = $frame;
            $frame = $frame->parent;
            $this->releaseFrameObjectRefs($child);
            goto restart;
        }
        if ($frame->ephemeral && null !== $frame->parent) {
            $child = $frame;
            $frame = $frame->parent;
            $this->releaseFrameObjectRefs($child);
            goto restart;
        }

        return self::SUCCESS;
    }

    /**
     * Goto / label back-edges reuse the innermost frame for the target block (#1228).
     * php-cfg lowers `if (cond) goto L` as JumpIf to the label block; naive getFrame()
     * nests a new frame per iteration and never terminates on merge blocks.
     */
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
     * Pre/post increment/decrement with Zend bool→int coercion (#4727, #3552).
     * Rejects ++/-- on readonly properties after construction (#3149).
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
        $working = new Variable();
        $working->copyFrom($read->resolveIndirect());
        if ($prefix) {
            if ($increment) {
                $working->applyIncrement();
            } else {
                $working->applyDecrement();
            }
            $write->copyFrom($working);
            $result->copyFrom($working);

            return null;
        }
        $old = new Variable();
        $old->copyFrom($working);
        if ($increment) {
            $working->applyIncrement();
        } else {
            $working->applyDecrement();
        }
        $write->copyFrom($working);
        $result->copyFrom($old);

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

        // 3) include_path search (best-effort using host get_include_path when available)
        if (\function_exists('get_include_path')) {
            $includePath = (string) @get_include_path();
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
        }

        return null;
    }

    /** Zend get_debug_type() labels for TypeError messages (#4241). */
    private function valueDebugTypeLabel(Variable $value): string
    {
        if (Variable::TYPE_OBJECT === $value->type) {
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

    /** True when the next opcode assigns through this VAR_FETCH destination slot (#3801). */
    private function varFetchDestUsedAsAssignLvalue(Frame $frame, OpCode $op): bool
    {
        $nextIndex = $frame->pos;
        if ($nextIndex >= $frame->block->nOpCodes) {
            return false;
        }
        $next = $frame->block->opCodes[$nextIndex];

        return OpCode::TYPE_ASSIGN === $next->type && $next->arg2 === $op->arg1;
    }

    /**
     * Run an internal builtin handler; bridge native Error/Throwable into user catch (#3648).
     */
    private function executeInternalHandler(Frame $handlerFrame, Frame $callerFrame): ?Frame
    {
        try {
            $handlerFrame->handler->execute($handlerFrame);

            return null;
        } catch (\DivisionByZeroError $e) {
            return $this->dispatchVmDivisionByZeroError($e, $callerFrame);
        } catch (\ArgumentCountError $e) {
            return $this->dispatchVmArgumentCountError($e, $callerFrame);
        } catch (\TypeError $e) {
            return $this->dispatchVmTypeError($e, $callerFrame);
        } catch (\ValueError $e) {
            return $this->dispatchVmValueError($e, $callerFrame);
        } catch (VM\NativeFiberError $e) {
            return $this->dispatchVmFiberError($e, $callerFrame);
        } catch (\Error $e) {
            return $this->dispatchVmError($e->getMessage(), $callerFrame);
        } catch (VM\GeneratorUncaughtThrow $e) {
            return $this->dispatchUncaughtGeneratorThrow($e->thrown, $callerFrame);
        } catch (VM\MagicMethodInvocationAborted) {
            $this->clearTryCatchUnwindState();
            $callerFrame->call = null;
            $callerFrame->callArgs = [];
            $callerFrame->callArgEntries = [];
            $callerFrame->suppressNextEcho = true;
            ++$callerFrame->pos;

            return null;
        }
    }

    private function dispatchUncaughtGeneratorThrow(Variable $thrown, Frame $callerFrame): ?Frame
    {
        $catchFrame = $this->findCatchFrameForThrow($callerFrame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    /**
     * Bridge native TypeError from VM internals into user catch handlers (#3445).
     */
    private function dispatchVmTypeError(\TypeError $error, Frame $frame): ?Frame
    {
        $thrown = VM\BuiltinExceptionSupport::materializeTypeError($this->context, $error->getMessage());
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    /**
     * Bridge native ArgumentCountError from stdlib builtins into user catch handlers (#4034).
     */
    private function dispatchVmArgumentCountError(\ArgumentCountError $error, Frame $frame): ?Frame
    {
        $thrown = VM\BuiltinExceptionSupport::materializeArgumentCountError($this->context, $error->getMessage());
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    /**
     * Bridge native DivisionByZeroError from numeric ops into user catch handlers (#3562, #3371).
     */
    private function dispatchVmDivisionByZeroError(\DivisionByZeroError $error, Frame $frame): ?Frame
    {
        $thrown = VM\BuiltinExceptionSupport::materializeDivisionByZeroError($this->context, $error->getMessage());
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    /**
     * Bridge native ValueError from stdlib builtins into user catch handlers (#3763).
     */
    private function dispatchVmValueError(\ValueError $error, Frame $frame): ?Frame
    {
        $thrown = VM\BuiltinExceptionSupport::materializeValueError($this->context, $error->getMessage());
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
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
        $thrown = VM\BuiltinExceptionSupport::materializeError($this->context, $message);
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    /**
     * Bridge native FiberError from fiber lifecycle operations into user catch handlers (#4372).
     */
    private function dispatchVmFiberError(VM\NativeFiberError $error, Frame $frame): ?Frame
    {
        $thrown = VM\BuiltinExceptionSupport::materializeFiberError($this->context, $error->getMessage());
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    private function findCatchFrameForThrow(Frame $frame, Variable $thrown): ?Frame
    {
        $this->context->pendingException = $thrown;
        $handlers = $this->context->activeTryHandlerFrames;
        for ($i = \count($handlers) - 1; $i >= 0; --$i) {
            $handler = $handlers[$i];
            $catchFrame = $this->dispatchCatchForHandlerFrame($handler);
            if (null !== $catchFrame) {
                \array_splice($this->context->activeTryHandlerFrames, $i);
                if ($this->context->coercingObjectToString) {
                    $this->context->magicMethodThrowHandled = true;
                }

                return $catchFrame;
            }
        }
        for ($handler = $frame->parent ?? $frame; null !== $handler; $handler = $handler->parent) {
            $catchFrame = $this->dispatchCatchForHandlerFrame($handler);
            if (null !== $catchFrame) {
                if ($this->context->coercingObjectToString) {
                    $this->context->magicMethodThrowHandled = true;
                }

                return $catchFrame;
            }
        }
        $this->clearTryCatchUnwindState();

        return null;
    }

    private function dispatchCatchForHandlerFrame(Frame $handler): ?Frame
    {
        $this->rewindHandlerToCatchChain($handler);
        $catchFrame = $this->enterMatchingCatchHandler($handler);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $finallyFrame = $this->enterFinallyHandlerForUnwind($handler, true);
        if (null !== $finallyFrame) {
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
            $catchFrame = $op->block1->getFrame($this->context, $handler);
            if (null !== $op->arg3) {
                if (!isset($catchFrame->scope[$op->arg3])) {
                    $catchFrame->scope[$op->arg3] = new Variable();
                }
                $catchFrame->scope[$op->arg3]->copyFrom($caught);
            }
            $catchFrame->activeCatchException = $caught;
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

        return $finallyOp->block1->getFrame($this->context, $handler);
    }

    /** Run finally after a matching catch body before the try/catch merge block (Zend order). */
    private function beginCatchExitFinallyUnwind(Frame $frame, Block $target): ?Frame
    {
        if (null === $this->resolveActiveCatchException($frame) && null === $frame->activeCatchException) {
            return null;
        }
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

        return $merge->getFrame($this->context, $frame);
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
     * Leaving a try body via goto must run finally before the jump target (Zend order, #4491).
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
            // Normal try/catch completion uses the merge block (registered at TYPE_TRY).
            if (isset($this->context->tryMergeBlockIds[spl_object_id($target)])) {
                continue;
            }
            $this->context->pendingGotoAfterFinally = $target;

            return $this->enterFinallyHandlerForUnwind($handler, false);
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

    private function hasPendingFinally(Frame $handler): bool
    {
        if (null === $this->findFinallyOpForHandler($handler)) {
            return false;
        }

        return !isset($this->context->completedFinallyHandlers[spl_object_id($handler)]);
    }

    /** Normal try completion runs the finally CFG block directly; mark it done (#3082). */
    private function markFinallyCompletedWhenLeavingFinallyBody(Frame $frame): void
    {
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
        for ($handler = $from->parent; null !== $handler; $handler = $handler->parent) {
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
            try {
                $message = $entry->getProperty('message')->toString();
            } catch (\LogicException) {
                $message = 'Exception';
            }
            throw VM\ExceptionSupport::nativeUncaughtThrowable($entry, $message);
        }
        throw new \Exception($thrown->toString());
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
        $encoded = $op->catchTypes;
        if (null === $encoded || '' === $encoded) {
            return true;
        }
        $types = explode('|', $encoded);
        if (Variable::TYPE_OBJECT !== $thrown->type) {
            return false;
        }
        $class = $thrown->toObject()->class;
        foreach ($types as $typeName) {
            if ('' === $typeName) {
                continue;
            }
            if ($this->objectIsInstanceOfClass($class, $typeName)) {
                return true;
            }
        }

        return false;
    }

    private function objectIsInstanceOfClass(ClassEntry $class, string $typeName): bool
    {
        $want = strtolower(ltrim($typeName, '\\'));
        $target = $this->context->classes[$want] ?? null;
        if (null !== $target && $target->isInterface) {
            return VM\InterfaceCheck::entryImplements($class, $want, $this->context);
        }

        return VM\InterfaceCheck::entryIsInstanceOf($class, $want, $this->context);
    }

    private function valueInstanceOfClassName(Variable $value, string $className): bool
    {
        $resolved = $value->resolveIndirect();
        $className = strtolower(ltrim($className, '\\'));
        if (Variable::TYPE_ENUM_CASE === $resolved->type) {
            $enumClass = $resolved->toEnumCase()->enumClass;

            return VM\InterfaceCheck::entryIsInstanceOf($enumClass, $className, $this->context)
                || VM\InterfaceCheck::entryImplements($enumClass, $className, $this->context);
        }
        if (Variable::TYPE_OBJECT !== $resolved->type) {
            return false;
        }
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

        return $methodLc === $wantSet
            || $methodLc === $wantGet
            || $methodLc === strtolower($className.'::'.$wantSet)
            || $methodLc === strtolower($className.'::'.$wantGet);
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
                return null;
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

        return $this->invokeStaticPropertyHookRaw($func, $propName, $classLc, $frame);
    }

    private function invokeStaticPropertyHookRaw(
        Func\PHP $func,
        string $rawProperty,
        string $classLc,
        Frame $parentFrame,
        Variable ...$args
    ): Variable {
        $savedStack = $this->context->swapRunStack(null);
        try {
            $child = $func->getFrame($this->context, null);
            $child->propertyHookRawProperty = $rawProperty;
            $child->calledClass = $classLc;
            $child->calledArgs = $args;
            $out = new Variable();
            $child->returnVar = $out;
            $this->context->push($child);
            $result = $this->runFrames();
            if (self::SUCCESS !== $result) {
                throw new \LogicException('Static property hook invocation failed in this compiler build');
            }

            return $out->resolveIndirect();
        } finally {
            $this->context->swapRunStack($savedStack);
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
        $lcClass = strtolower($entry->name);
        $propMeta = $this->context->propertyHookRegistry[$lcClass][$prop->name]
            ?? $this->context->propertyHookRegistry[$lcClass][strtolower($prop->name)]
            ?? null;
        if (is_array($propMeta) && !empty($propMeta['virtual'])) {
            $prop->propertyHookVirtual = true;
        }
    }

    private function classPropertyMeta(ObjectEntry $object, string $propertyName): ?VM\ClassProperty
    {
        foreach ($object->class->properties as $prop) {
            if ($prop->name === $propertyName) {
                return $prop;
            }
        }

        return null;
    }

    /**
     * Invoke set hook instead of direct assign when applicable (#3145).
     */
    private function dispatchPropertySetHookAssign(Variable $lvalue, Variable $value, Frame $frame): bool
    {
        $target = $lvalue->resolveIndirect();
        $classLc = $target->staticPropertyClassLc;
        $staticPropName = $target->objectPropertyName;
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
            $hooks = $entry->staticPropertyHooks[$propLc] ?? [];
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
        $owner = $target->objectPropertyOwner;
        $propName = $target->objectPropertyName;
        if (null === $owner || null === $propName || $this->isPropertyHookRawWrite($frame, $propName)) {
            return false;
        }
        $meta = $this->classPropertyMeta($owner, $propName);
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

    private function fetchPropertyWithHooks(ObjectEntry $object, string $name, Frame $frame): ?Variable
    {
        if ($this->isPropertyHookRawWrite($frame, $name)) {
            return null;
        }
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

        return $this->invokePhpFunctionWithPropertyHookRaw($func, $name, $frame, $thisVar);
    }

    private function invokePhpFunctionWithPropertyHookRaw(Func\PHP $func, string $rawProperty, Frame $parentFrame, Variable ...$args): Variable
    {
        $savedStack = $this->context->swapRunStack(null);
        try {
            $child = $func->getFrame($this->context, null);
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
            if (self::SUCCESS !== $result) {
                throw new \LogicException('Property hook invocation failed in this compiler build');
            }

            return $out->resolveIndirect();
        } finally {
            $this->context->swapRunStack($savedStack);
        }
    }

    /**
     * Reject unset($scalar[$key]) — Zend ZEND_UNSET_DIM on non-array/string (#4880, zend_execute.c).
     *
     * @return Frame|null catch frame when try/catch (Error) handles the throw
     */
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

    /** Reject unset() on readonly properties; returns catch frame or throws when uncaught. */
    private function enforceReadonlyPropertyUnset(ObjectEntry $object, string $propName, Frame $frame): ?Frame
    {
        $declaringClass = $this->readonlyPropertyDeclaringClass($object, $propName);
        if (null === $declaringClass) {
            return null;
        }

        $thrown = VM\BuiltinExceptionSupport::materializeError(
            $this->context,
            sprintf('Cannot unset readonly property %s::$%s', $declaringClass, $propName)
        );
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
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

        return $this->enforceReadonlyPropertyWrite($lvalue, $frame);
    }

    /**
     * Reject writes to get-only virtual hooked properties (#4687, Zend zend_object_handlers.c).
     */
    private function enforceVirtualPropertyHookWrite(Variable $lvalue, Frame $frame): ?Frame
    {
        $target = $lvalue->resolveIndirect();
        $propName = $target->objectPropertyName;
        if (null === $propName || $this->isPropertyHookRawWrite($frame, $propName)) {
            return null;
        }
        $className = null;
        $virtual = false;
        $hasSetHook = false;
        $classLc = $target->staticPropertyClassLc;
        if (is_string($classLc) && isset($this->context->classes[$classLc])) {
            $entry = $this->context->classes[$classLc];
            $hooks = $entry->staticPropertyHooks[strtolower($propName)] ?? [];
            $virtual = !empty($hooks['virtual']);
            $hasSetHook = !empty($hooks['set']);
            $className = $entry->name;
        } else {
            $owner = $target->objectPropertyOwner;
            if (null === $owner) {
                return null;
            }
            $meta = $this->classPropertyMeta($owner, $propName);
            if (null === $meta) {
                return null;
            }
            $virtual = $meta->propertyHookVirtual;
            $hasSetHook = null !== $meta->setHookMethodLc;
            $className = $owner->class->name;
        }
        if (!$virtual || $hasSetHook) {
            return null;
        }

        $thrown = VM\BuiltinExceptionSupport::materializeError(
            $this->context,
            sprintf('Property %s::$%s is read-only', $className, $propName)
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
     * @return ?Frame catch frame when handled; null when no violation or after uncaught raise
     */
    private function enforceReadonlyDynamicPropertyCreate(ObjectEntry $object, string $name, Frame $frame): ?Frame
    {
        if (!$object->class->readonly || $this->hasInstanceMethod($object->class, '__set')) {
            return null;
        }
        if ($object->hasProperty($name)) {
            return null;
        }

        $thrown = VM\BuiltinExceptionSupport::materializeError(
            $this->context,
            sprintf('Cannot create dynamic property %s::$%s', $object->class->name, $name)
        );
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    /** Reject readonly property writes; returns catch frame or throws when uncaught. */
    private function enforceReadonlyPropertyWrite(Variable $lvalue, Frame $frame): ?Frame
    {
        $target = $lvalue->resolveIndirect();
        $owner = $target->objectPropertyOwner;
        if (null === $owner || !$owner->constructed) {
            return null;
        }
        $prop = $target->objectPropertyName ?? 'property';
        $declaringClass = $this->readonlyPropertyDeclaringClass($owner, $prop);
        if (null === $declaringClass) {
            return null;
        }

        $thrown = VM\BuiltinExceptionSupport::materializeError(
            $this->context,
            sprintf('Cannot modify readonly property %s::$%s', $declaringClass, $prop)
        );
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    private function enforcePropertyVisibilityWrite(Variable $lvalue, Frame $frame): ?Frame
    {
        $target = $lvalue->resolveIndirect();
        $owner = $target->objectPropertyOwner;
        if (null === $owner) {
            return null;
        }

        return $this->enforcePropertyVisibility($owner, $target->objectPropertyName ?? 'property', $frame);
    }

    private function enforcePropertyVisibilityRead(ObjectEntry $object, string $propName, Frame $frame): ?Frame
    {
        return $this->enforcePropertyVisibility($object, $propName, $frame);
    }

    private function enforcePropertyVisibility(ObjectEntry $object, string $propName, Frame $frame): ?Frame
    {
        $meta = $this->classPropertyMeta($object, $propName);
        if (null === $meta || MethodVisibility::isPublic($meta->visibility)) {
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
                fn (string $classLc, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLc, $ancestorLc)
            );
        } catch (\LogicException $e) {
            return $this->dispatchVmError($e->getMessage(), $frame);
        }

        return null;
    }

    private function enforceClassConstVisibility(ClassEntry $classEntry, string $constName, Frame $frame): ?Frame
    {
        $constLc = strtolower($constName);
        $vis = $classEntry->constVisibility[$constLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
        if (MethodVisibility::isPublic($vis)) {
            return null;
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

    private function callerClassLc(Frame $frame): ?string
    {
        if (null !== $frame->block->func && null !== $frame->block->func->class) {
            return strtolower($frame->block->func->class->value);
        }
        if (null !== $frame->calledClass && '' !== $frame->calledClass) {
            return strtolower($frame->calledClass);
        }

        return null;
    }

    private function readonlyPropertyDeclaringClass(ObjectEntry $object, string $propName): ?string
    {
        if ($object->class->readonly) {
            return $object->class->name;
        }
        $meta = $this->classPropertyMeta($object, $propName);
        if (null === $meta || !$meta->readonly) {
            return null;
        }
        if ('' !== $meta->declaringClassLc && isset($this->context->classes[$meta->declaringClassLc])) {
            return $this->context->classes[$meta->declaringClassLc]->name;
        }

        return $meta->declaringClassLc !== '' ? $meta->declaringClassLc : $object->class->name;
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
        $meta = $this->classPropertyMeta($owner, $propName);
        if (null === $meta) {
            return null;
        }
        $setVis = PropertyVisibility::effectiveSetVisibility($meta->visibility, $meta->setVisibility);
        $readVis = MethodVisibility::mask($meta->visibility);
        if ($setVis === $readVis) {
            return null;
        }
        try {
            PropertyVisibility::assertWritable(
                $setVis,
                $this->callerClassLc($frame),
                strtolower($owner->class->name),
                $owner->class->name,
                $propName,
                fn (string $child, string $parent): bool => $this->isSubclassOf($child, $parent)
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
     */
    private function warnForeachNonTraversable(Variable $container, Frame $frame): void
    {
        $resolved = $container->resolveIndirect();
        $this->context->errors->triggerError(
            'foreach() argument must be of type array|object, '
            .TypeCheck::typeNameForConstraint($resolved->type).' given',
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $this->context,
            $frame
        );
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
        if (null !== $sendValue) {
            $gen->pendingSend->copyFrom($sendValue);
            $gen->hasPendingSend = true;
        }

        return $this->advanceGeneratorIteration($gen);
    }

    /** Generator::throw() — inject Throwable at yield suspension (Zend zend_generators.c). */
    public function throwGenerator(GeneratorState $gen, Variable $exception): bool
    {
        if ($gen->done) {
            throw new \Exception('Cannot throw to a closed generator');
        }
        if (null === $gen->frame) {
            throw new \Exception('Cannot throw to an uninitialized generator');
        }
        $gen->pendingThrow->copyFrom($exception);
        $gen->hasPendingThrow = true;

        return $this->advanceGeneratorIteration($gen);
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
        $catchFrame = $this->findCatchFrameForGeneratorThrow($gen, $thrown);
        if (null !== $catchFrame) {
            $catchFrame->generatorState = $gen;
            $gen->frame = $catchFrame;

            return;
        }
        $gen->frame = null;
        $gen->markReturned(null);
        throw new VM\GeneratorUncaughtThrow($thrown);
    }

    /** Catch handlers inside the generator function only (not caller try/catch). */
    private function findCatchFrameForGeneratorThrow(GeneratorState $gen, Variable $thrown): ?Frame
    {
        $this->context->pendingException = $thrown;
        for ($handler = $gen->frame; null !== $handler; $handler = $handler->parent) {
            if ($handler->generatorState !== $gen && $this->findGeneratorState($handler) !== $gen) {
                break;
            }
            $catchFrame = $this->dispatchCatchForHandlerFrame($handler);
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
        try {
            $frame->scope[$validSlot]->bool($this->advanceGeneratorIteration($gen));

            return null;
        } catch (VM\GeneratorUncaughtThrow $e) {
            return $this->dispatchUncaughtGeneratorThrow($e->thrown, $frame);
        }
    }

    private function advanceGeneratorIteration(GeneratorState $gen): bool
    {
        if ($gen->done) {
            return false;
        }
        $this->applyGeneratorPendingSend($gen);
        $this->applyGeneratorPendingThrow($gen);
        if (null === $gen->frame) {
            $gen->frame = $gen->func->getFrame($this->context, null);
            $gen->frame->calledArgs = $gen->calledArgs;
            $gen->frame->generatorState = $gen;
            $gen->frame->pos = 0;
        }
        $savedStack = $this->context->swapRunStack(null);
        try {
            $this->context->push($gen->frame);
            try {
                $result = $this->runFrames();
            } catch (\TypeError $e) {
                $thrown = VM\BuiltinExceptionSupport::materializeTypeError($this->context, $e->getMessage());
                $catchFrame = $this->findCatchFrameForGeneratorThrow($gen, $thrown);
                if (null !== $catchFrame) {
                    $catchFrame->generatorState = $gen;
                    $gen->frame = $catchFrame;

                    return $this->advanceGeneratorIteration($gen);
                }
                $gen->frame = null;
                $gen->markReturned(null);
                throw new VM\GeneratorUncaughtThrow($thrown);
            }
        } finally {
            $this->context->swapRunStack($savedStack);
        }
        if (self::GENERATOR_YIELD === $result) {
            return $gen->hasCurrent;
        }
        $gen->frame = null;
        if (self::SUCCESS === $result) {
            if (!$gen->hasReturned) {
                $gen->markReturned(null);
            }
        }

        return false;
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
            $methodName = $frame->magicCallMethodName;
            $frame->magicCallMethodName = null;
            [$paramNames, $variadicIndex] = $this->calleeParamMetadata($frame->call);
            $userArgs = $this->resolveUserCallArgs($frame, $paramNames, $variadicIndex);
            $nameVar = new Variable(Variable::TYPE_STRING);
            $nameVar->string($methodName);
            $argsVar = new Variable();
            $argsVar->newArray();
            $packed = $argsVar->toArray();
            foreach ($userArgs as $i => $arg) {
                $copy = new Variable();
                $copy->copyFrom($arg);
                $packed->addIndex($i, $copy);
            }

            return array_merge($frame->callArgs, [$nameVar, $argsVar]);
        }

        [$paramNames, $variadicIndex] = $this->calleeParamMetadata($frame->call);

        $userArgs = $this->resolveUserCallArgs($frame, $paramNames, $variadicIndex);
        if ([] === $frame->callArgs) {
            return $userArgs;
        }

        return array_merge($frame->callArgs, $userArgs);
    }

    /**
     * @param list<string> $paramNames
     *
     * @return list<Variable>
     */
    private function resolveUserCallArgs(Frame $frame, array $paramNames, ?int $variadicIndex): array
    {
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
                        $variadicIndex
                    ) as $expanded
                ) {
                    $entries[] = $expanded;
                }
                continue;
            }
            $entries[] = $entry;
        }

        return NamedArgs::resolve($entries, $paramNames, $variadicIndex);
    }

    /**
     * @return array{0: list<string>, 1: ?int}
     */
    private function calleeParamMetadata(Func $call): array
    {
        if ($call instanceof Func\PHP) {
            return [$call->block->paramNames, $call->block->variadicParamIndex];
        }
        if ($call instanceof Func\Internal) {
            return [BuiltinParamNames::forFunction($call->getName()) ?? [], null];
        }

        return [[], null];
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
            $src = Block::findVariableInParentFramesByName($spec['name'], $frame);
            $stored = new Variable();
            if (null === $src) {
                $stored->null();
            } elseif ($spec['byRef']) {
                $stored->indirect($src->resolveIndirect());
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

    protected function bindClosureCallCaptures(Frame $callee, ?ClosureState $closureState): void
    {
        if (null === $closureState || [] === $closureState->captures) {
            return;
        }
        foreach ($closureState->captures as $capture) {
            $dest = $this->scopeSlot($callee, $capture['slot']);
            if ($capture['byRef']) {
                $dest->indirect($capture['var']->resolveIndirect());
            } else {
                $dest->copyFrom($capture['var']);
            }
        }
    }

    protected function initClosureCall(Frame $frame, ClosureState $state): void
    {
        if (null !== $state->methodName && null !== $state->methodReceiver) {
            if (null !== $state->boundScopeClass && '' !== $state->boundScopeClass) {
                $frame->calledClass = $state->boundScopeClass;
            }
            $this->initMethodCall($frame, $state->methodReceiver, $state->methodName);
            $frame->closureCall = null;

            return;
        }
        if (null !== $state->wrappedFunc) {
            $frame->call = $state->wrappedFunc;
            $frame->closureCall = null;
            $frame->callArgs = [];
            $frame->callArgEntries = [];

            return;
        }
        $frame->call = $state->func;
        $frame->closureCall = $state;
        $frame->callArgs = [];
        $frame->callArgEntries = [];
    }

    protected function applyClosureBinding(Frame $callee, ?ClosureState $closureState): void
    {
        $this->bindClosureCallCaptures($callee, $closureState);
        if (null === $closureState) {
            return;
        }
        if (null !== $closureState->boundThis) {
            $thisIdx = $closureState->func->block->slotIndexForVariableName('this');
            if (null !== $thisIdx) {
                $callee->scope[$thisIdx] = $closureState->boundThis;
            }
        }
        if (null !== $closureState->boundScopeClass && '' !== $closureState->boundScopeClass) {
            $callee->calledClass = $closureState->boundScopeClass;
        }
    }

    protected function resolveStaticClassName(string $className, Frame $frame): string
    {
        return $this->resolveClassScopeName($className, $frame);
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

    protected function resolveClassScopeName(string $className, Frame $frame): string
    {
        $lcClass = strtolower($className);
        if ('self' === $lcClass) {
            return $this->declaringClassLc($frame);
        }
        if ('static' === $lcClass) {
            return $this->lateStaticClassLc($frame);
        }
        if ('parent' === $lcClass) {
            $declaring = $this->declaringClassLc($frame);
            if (!isset($this->context->classes[$declaring])) {
                throw new \LogicException('parent:: used outside of class scope');
            }
            $parentLc = $this->context->classes[$declaring]->parentLc;
            if (null === $parentLc) {
                throw new \LogicException('parent:: used when class has no parent');
            }

            return $parentLc;
        }

        return $lcClass;
    }

    protected function declaringClassLc(Frame $frame): string
    {
        if (null !== $frame->block->func && null !== $frame->block->func->class) {
            return strtolower($frame->block->func->class->value);
        }
        // Bound closure scope (Closure::bind/bindTo $newScope) — #3673.
        if (null !== $frame->calledClass && '' !== $frame->calledClass) {
            return strtolower($frame->calledClass);
        }

        throw new \LogicException('self:: used outside of class scope');
    }

    protected function lateStaticClassLc(Frame $frame): string
    {
        if (null !== $frame->calledClass && '' !== $frame->calledClass) {
            return strtolower($frame->calledClass);
        }

        return $this->declaringClassLc($frame);
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

    protected function initMethodCall(Frame $frame, Variable $receiver, string $methodName): ?Frame
    {
        $methodLc = strtolower($methodName);
        $object = $receiver->toObject();
        if ($object->lazyPending) {
            VM\LazyObjectSupport::ensureInitialized($this, $object);
        }
        if (null !== $object->closureState && '__invoke' === $methodLc) {
            $this->initClosureCall($frame, $object->closureState);

            return null;
        }
        $class = $object->class;
        try {
            [$declaringClass, $methodLc] = $this->resolveInstanceMethod($class, $methodLc);
        } catch (\LogicException $e) {
            if (isset($class->methods['__call'])) {
                $frame->magicCallMethodName = $methodName;
                $frame->call = $class->methods['__call'];
                $frame->callArgs = [$receiver];
                $frame->callArgEntries = [];

                return null;
            }
            throw $e;
        }
        $vis = $declaringClass->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
        $callerClassLc = $this->callerClassLc($frame);
        $callerDisplay = null;
        if (null !== $callerClassLc && isset($this->context->classes[$callerClassLc])) {
            $callerDisplay = $this->context->classes[$callerClassLc]->name;
        }
        $declaredName = $declaringClass->methodNames[$methodLc] ?? $methodName;
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
            return $this->dispatchVmError($e->getMessage(), $frame);
        }
        $frame->call = $declaringClass->methods[$methodLc];
        $frame->callArgs = [$receiver];
        $frame->callArgEntries = [];

        return null;
    }

    protected function initStaticCallable(Frame $frame, string $callableName, bool $parentKeywordScope = false): void
    {
        [$className, $methodName] = explode('::', $callableName, 2);
        $lcClass = $this->resolveClassScopeName($className, $frame);
        if (!isset($this->context->classes[$lcClass])) {
            $this->context->autoloadClass($className);
        }
        if (!isset($this->context->classes[$lcClass])) {
            throw new \LogicException("Call to undefined static method {$callableName}()");
        }
        $class = $this->context->classes[$lcClass];
        $frame->staticCallClass = $class->name;
        $methodLc = strtolower($methodName);
        if ($class->isEnum && null !== $class->backedType && ('from' === $methodLc || 'tryfrom' === $methodLc)) {
            $frame->call = new VM\EnumFromHandler($class, 'tryfrom' === $methodLc);
            $frame->callArgs = [];
            $frame->callArgEntries = [];

            return;
        }
        try {
            [$class, $methodLc] = $this->resolveStaticMethod($lcClass, $methodLc);
        } catch (\LogicException $e) {
            $magicClass = $this->findMagicCallStaticClass($lcClass);
            if (null === $magicClass) {
                throw $e;
            }
            $frame->magicCallMethodName = $methodName;
            $vis = $magicClass->methodVisibility['__callstatic'] ?? \PHPCfg\Func::FLAG_PUBLIC;
            $callerClassLc = null;
            if (null !== $frame->block->func && null !== $frame->block->func->class) {
                $callerClassLc = strtolower($frame->block->func->class->value);
            }
            MethodVisibility::assertCallable(
                $vis,
                $callerClassLc,
                strtolower($magicClass->name),
                $magicClass->name,
                '__callStatic'
            );
            $frame->call = $magicClass->methods['__callstatic'];
            $frame->callArgs = [];
            $frame->callArgEntries = [];

            return;
        }
        $vis = $class->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
        $callerClassLc = null;
        if (null !== $frame->block->func && null !== $frame->block->func->class) {
            $callerClassLc = strtolower($frame->block->func->class->value);
        }
        if (null === $callerClassLc && null !== $frame->calledClass && '' !== $frame->calledClass) {
            $callerClassLc = strtolower($frame->calledClass);
        }
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
        $callerDisplay = null;
        if (null !== $callerClassLc && isset($this->context->classes[$callerClassLc])) {
            $callerDisplay = $this->context->classes[$callerClassLc]->name;
        }
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
        $frame->call = $class->methods[$methodLc];
        $frame->callArgs = $this->callArgsForStaticMethod($frame, $lcClass, $frame->call, $parentKeywordScope);
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
        if ($parentKeywordScope) {
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
        if (null === $frame->block->func || null === $frame->block->func->class) {
            return null;
        }
        if (($frame->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) {
            return null;
        }
        if (!empty($frame->callArgs)) {
            return $frame->callArgs[0];
        }
        if (!empty($frame->calledArgs)) {
            return $frame->calledArgs[0];
        }
        $idx = $frame->block->slotIndexForVariableName('this');
        if (null !== $idx && isset($frame->scope[$idx])) {
            return $frame->scope[$idx];
        }

        return $frame->block->findVariableByRuntimeName('this', $frame);
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
     * @param array<string, true> $ownMethods
     *
     * @return array<string, true>
     */
    protected function traitMethodExclusions(ClassEntry $entry, array $ownMethods): array
    {
        $excluded = $ownMethods;
        $visited = [];
        $current = $entry->parentLc;
        while (null !== $current && !isset($visited[$current])) {
            $visited[$current] = true;
            if (!isset($this->context->classes[$current])) {
                break;
            }
            foreach ($this->context->classes[$current]->methods as $name => $_) {
                $excluded[$name] = true;
            }
            $current = $this->context->classes[$current]->parentLc;
        }

        return $excluded;
    }

    protected function applyTraitUse(ClassEntry $entry, string $traitName, array $ownMethods = []): void
    {
        $this->applyTraitUsesWithAdaptations($entry, [$traitName], [], $ownMethods);
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
        array $ownMethods = []
    ): void {
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
                ];
            }
            foreach ($trait->abstractMethods as $name => $_) {
                if (!isset($entry->methods[$name]) && !isset($entry->abstractMethods[$name])) {
                    $entry->abstractMethods[$name] = true;
                }
            }
            foreach ($trait->staticProperties as $name => $storage) {
                if (isset($entry->staticProperties[$name])) {
                    throw new \LogicException(
                        "Trait property {$trait->name}::\${$name} conflicts with a property declared in another trait"
                    );
                }
                $entry->staticProperties[$name] = $this->cloneStaticPropertyStorage($storage);
                $entry->traitStaticPropertyNames[$name] = true;
            }
            $this->inheritTraitInstanceProperties($entry, $trait, $trait->name);
            foreach ($trait->constants as $name => $value) {
                if (isset($entry->constants[$name])) {
                    if ($this->classConstValuesIdentical($entry->constants[$name], $value)) {
                        continue;
                    }
                    $prevTrait = $entry->traitConstSources[$name] ?? $entry->name;
                    throw new \LogicException(
                        "Trait constant {$trait->name}::{$name} conflicts with {$prevTrait}::{$name}"
                    );
                }
                $entry->constants[$name] = $value;
                $entry->traitConstSources[$name] = $trait->name;
                if (isset($trait->constVisibility[$name])) {
                    $entry->constVisibility[$name] = $trait->constVisibility[$name];
                }
                if (isset($trait->constDeprecated[$name])) {
                    $entry->constDeprecated[$name] = $trait->constDeprecated[$name];
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
                if (isset($merged[$methodLc])) {
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
            if (isset($merged[$newNameLc])) {
                throw new \LogicException('Cannot redefine method ' . $newName);
            }

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
                ];
            } else {
                if (!isset($merged[$methodLc])) {
                    throw new \LogicException(
                        'An alias was defined for ' . $traitPrefix . (string) ($adaptation['method'] ?? '')
                        . ' but this method does not exist'
                    );
                }
                $data = $merged[$methodLc];
            }

            if (null !== $newModifier) {
                $data['vis'] = (int) $newModifier;
            }
            $data['methodNames'] = (string) $newName;
            $merged[$newNameLc] = $data;
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
                throw new \CompileError(
                    "Trait method {$data['traitName']}::{$methodLc} has not been applied as {$entry->name}::{$methodLc}, "
                    ."because of collision with {$prevTrait}::{$methodLc}"
                );
            }
            $entry->methods[$methodLc] = $data['method'];
            $entry->traitMethodSources[$methodLc] = $data['traitName'];
            $entry->methodVisibility[$methodLc] = $data['vis'];
            $entry->methodNames[$methodLc] = $data['methodNames'];
            if (null !== $data['attrs']) {
                $entry->methodAttributeNames[$methodLc] = $data['attrs'];
            }
            if (null !== $data['deprecated']) {
                $entry->methodDeprecated[$methodLc] = $data['deprecated'];
            }
            if (null !== $data['attributeEntries']) {
                $entry->methodAttributeEntries[$methodLc] = $data['attributeEntries'];
            }
            if (null !== $data['parameterMetadata']) {
                $entry->methodParameterMetadata[$methodLc] = $data['parameterMetadata'];
            }
            if ('__construct' === $methodLc && null === $entry->constructor) {
                $entry->constructor = $data['method'];
            }
        }
    }

    protected function inheritTraitInstanceProperties(ClassEntry $entry, ClassEntry $trait, string $traitName): void
    {
        foreach ($trait->properties as $property) {
            $propLc = strtolower($property->name);
            foreach ($entry->properties as $existing) {
                if (strtolower($existing->name) === $propLc) {
                    throw new \LogicException(
                        "Trait property {$traitName}::\${$property->name} conflicts with a property declared in another trait"
                    );
                }
            }
            $entry->properties[] = $this->cloneClassPropertyForEntry($property, $entry);
            if (isset($trait->propertyAttributeNames[$propLc])) {
                $entry->propertyAttributeNames[$propLc] = $trait->propertyAttributeNames[$propLc];
            }
            if (isset($trait->propertyAttributeEntries[$propLc])) {
                $entry->propertyAttributeEntries[$propLc] = $trait->propertyAttributeEntries[$propLc];
            }
        }
    }

    private function cloneClassPropertyForEntry(VM\ClassProperty $property, ClassEntry $entry): VM\ClassProperty
    {
        $prototype = clone $property->prototype;
        $default = null !== $property->default ? clone $property->default : null;
        $cloned = new VM\ClassProperty(
            $property->name,
            $default,
            $prototype,
            $property->readonly,
            $property->visibility,
            strtolower($entry->name),
            $property->setVisibility
        );
        $cloned->getHookMethodLc = $property->getHookMethodLc;
        $cloned->setHookMethodLc = $property->setHookMethodLc;
        $cloned->propertyHookVirtual = $property->propertyHookVirtual;
        $cloned->defaultInitBlock = $property->defaultInitBlock;
        $cloned->defaultInitResultSlot = $property->defaultInitResultSlot;

        return $cloned;
    }

    /**
     * @param list<string> $pendingTraits
     * @param array<string, true> $ownMethods
     */
    protected function flushPendingTraitUses(ClassEntry $entry, array $pendingTraits, array $ownMethods = []): void
    {
        if ([] === $pendingTraits) {
            return;
        }
        $this->applyTraitUsesWithAdaptations($entry, $pendingTraits, [], $ownMethods);
    }

    protected function inheritFromInterfaces(ClassEntry $entry): void
    {
        foreach ($entry->interfaces as $ifaceLc) {
            if (!isset($this->context->classes[$ifaceLc])) {
                continue;
            }
            $iface = $this->context->classes[$ifaceLc];
            foreach ($iface->constants as $name => $value) {
                if (!isset($entry->constants[$name])) {
                    $entry->constants[$name] = $value;
                    if (isset($iface->constVisibility[$name])) {
                        $entry->constVisibility[$name] = $iface->constVisibility[$name];
                    }
                }
            }
        }
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
        $clone = new Variable();
        $clone->copyFrom($source->resolveIndirect());

        return $clone;
    }

    protected function inheritFromParent(ClassEntry $entry): void
    {
        if (null === $entry->parentLc || !isset($this->context->classes[$entry->parentLc])) {
            return;
        }
        $parent = $this->context->classes[$entry->parentLc];
        foreach ($parent->interfaces as $iface) {
            if (!in_array($iface, $entry->interfaces, true)) {
                $entry->interfaces[] = $iface;
            }
        }
        foreach ($parent->methods as $name => $method) {
            if (!isset($entry->methods[$name])) {
                $vis = $parent->methodVisibility[$name] ?? \PHPCfg\Func::FLAG_PUBLIC;
                // Private methods are not inherited into subclass tables (Zend zend_inheritance).
                if (($vis & \PHPCfg\Func::FLAG_PRIVATE) !== 0) {
                    continue;
                }
                $entry->methods[$name] = $method;
                $entry->methodVisibility[$name] = $vis;
                if (isset($parent->methodDeprecated[$name])) {
                    $entry->methodDeprecated[$name] = $parent->methodDeprecated[$name];
                }
                $entry->methodNames[$name] = $parent->methodNames[$name] ?? $name;
            }
        }
        foreach ($parent->staticProperties as $name => $storage) {
            if (!isset($entry->staticProperties[$name])) {
                if (isset($parent->traitStaticPropertyNames[$name])) {
                    $entry->staticProperties[$name] = $this->cloneStaticPropertyStorage($storage);
                    $entry->traitStaticPropertyNames[$name] = true;
                } else {
                    // Class-declared inherited statics share one slot (Zend; #4668).
                    $entry->staticProperties[$name] = $storage;
                }
            }
        }
        foreach ($parent->constants as $name => $value) {
            if (!isset($entry->constants[$name])) {
                $entry->constants[$name] = $value;
                if (isset($parent->constVisibility[$name])) {
                    $entry->constVisibility[$name] = $parent->constVisibility[$name];
                }
                if (isset($parent->constDeprecated[$name])) {
                    $entry->constDeprecated[$name] = $parent->constDeprecated[$name];
                }
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
        foreach ($parent->properties as $property) {
            $exists = false;
            foreach ($entry->properties as $existing) {
                if ($existing->name === $property->name) {
                    $exists = true;
                    break;
                }
            }
            if (!$exists) {
                $entry->properties[] = $property;
            }
        }
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
    protected function resolveStaticMethod(string $lcClass, string $methodLc): array
    {
        $visited = [];
        while (!isset($visited[$lcClass])) {
            $visited[$lcClass] = true;
            if (!isset($this->context->classes[$lcClass])) {
                break;
            }
            $class = $this->context->classes[$lcClass];
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

    protected function initArrayCallable(Frame $frame, Variable $callable): void
    {
        $table = $callable->toArray();
        $idx0 = new Variable(Variable::TYPE_INTEGER);
        $idx0->int(0);
        $idx1 = new Variable(Variable::TYPE_INTEGER);
        $idx1->int(1);
        if (!$table->keyExists($idx0) || !$table->keyExists($idx1)) {
            throw new \LogicException('Invalid array callable');
        }
        $receiver = $table->findVariable($idx0, false)->resolveIndirect();
        $methodName = $table->findVariable($idx1, false)->resolveIndirect()->toString();
        if (Variable::TYPE_OBJECT !== $receiver->type) {
            throw new \LogicException('Invalid array callable');
        }
        $this->initMethodCall($frame, $receiver, $methodName);
    }

    protected function defineClass(ClassEntry $entry, Block $block): void {
        $frame = $block->getFrame($this->context);
        $ownMethods = $this->classBodyOwnMethodNames($block, $frame);
        $pendingNewDefaultOps = [];
        /** @var list<string> */
        $pendingTraits = [];
        foreach ($block->opCodes as $op) {
            if ([] !== $pendingNewDefaultOps) {
                if (OpCode::TYPE_DECLARE_PROPERTY === $op->type || OpCode::TYPE_DECLARE_STATIC_PROPERTY === $op->type) {
                    $this->finalizePendingNewPropertyDefault($frame, $block, $entry, $op, $pendingNewDefaultOps);
                    $pendingNewDefaultOps = [];

                    continue;
                }
                if (OpCode::TYPE_DECLARE_CLASS_CONST === $op->type) {
                    foreach ($pendingNewDefaultOps as $pendingOp) {
                        $this->executeClassBodyConstInitOpcode($frame, $pendingOp);
                    }
                    $pendingNewDefaultOps = [];
                } else {
                    $pendingNewDefaultOps[] = $op;

                    continue;
                }
            } elseif (OpCode::TYPE_NEW === $op->type) {
                $pendingNewDefaultOps[] = $op;

                continue;
            } elseif ($this->isClassBodyConstInitOpcode($op->type)) {
                $this->executeClassBodyConstInitOpcode($frame, $op);

                continue;
            }
            if ($this->isClassBodyDefaultInitOpcode($op->type)) {
                $this->flushPendingTraitUses($entry, $pendingTraits, $ownMethods);
                $pendingTraits = [];
                $this->executeClassBodyDefaultInitOpcode($frame, $op);

                continue;
            }
            if (VM\ClassConstExpr::isSupportedOpcode($op->type)) {
                VM\ClassConstExpr::execute($this->context, $frame, $op, $entry);

                continue;
            }
            switch ($op->type) {
                case OpCode::TYPE_USE_TRAIT:
                    $pendingTraits[] = $frame->scope[$op->arg1]->toString();
                    break;
                case OpCode::TYPE_TRAIT_USE_ADAPTATION:
                    $this->applyTraitUsesWithAdaptations($entry, $pendingTraits, $op->traitAdaptations, $ownMethods);
                    $pendingTraits = [];
                    break;
                case OpCode::TYPE_DECLARE_PROPERTY:
                    $this->flushPendingTraitUses($entry, $pendingTraits, $ownMethods);
                    $pendingTraits = [];
                    $name = $frame->scope[$op->arg1];
                    $default = is_null($op->arg2) ? null : $frame->scope[$op->arg2];
                    $propLc = strtolower($name->toString());
                    $entry->properties[] = new VM\ClassProperty(
                        $name->toString(),
                        $default,
                        $frame->scope[$op->arg3],
                        $op->propertyReadonly,
                        MethodVisibility::mask($op->propertyVisibility),
                        strtolower($entry->name),
                        (int) ($op->propertySetVisibility ?? 0)
                    );
                    if ([] !== $op->attributeNames) {
                        $entry->propertyAttributeNames[$propLc] = $op->attributeNames;
                    }
                    if ([] !== $op->attributeEntries) {
                        $entry->propertyAttributeEntries[$propLc] = $op->attributeEntries;
                    }
                    break;
                case OpCode::TYPE_DECLARE_STATIC_PROPERTY:
                    $this->flushPendingTraitUses($entry, $pendingTraits, $ownMethods);
                    $pendingTraits = [];
                    $name = strtolower($frame->scope[$op->arg1]->toString());
                    $storage = clone $frame->scope[$op->arg3];
                    if (!is_null($op->arg2)) {
                        $storage->copyFrom($frame->scope[$op->arg2]);
                    }
                    $entry->staticProperties[$name] = $storage;
                    break;
                case OpCode::TYPE_DECLARE_METHOD:
                    $this->flushPendingTraitUses($entry, $pendingTraits, $ownMethods);
                    $pendingTraits = [];
                    $declaredName = $frame->scope[$op->arg1]->toString();
                    $name = strtolower($declaredName);
                    $vis = \PHPCfg\Func::FLAG_PUBLIC;
                    if (null !== $op->arg3 && isset($block->constants[$op->arg3])) {
                        $vis = MethodVisibility::mask($block->constants[$op->arg3]->toInt());
                    }
                    $entry->methodVisibility[$name] = $vis;
                    unset($entry->traitMethodSources[$name]);
                    $entry->methodNames[$name] = $declaredName;
                    if ([] !== $op->attributeNames) {
                        $entry->methodAttributeNames[$name] = $op->attributeNames;
                    }
                    if (null !== $op->deprecatedMetadata) {
                        $entry->methodDeprecated[$name] = $op->deprecatedMetadata;
                    }
                    if ([] !== $op->attributeEntries) {
                        $entry->methodAttributeEntries[$name] = $op->attributeEntries;
                    }
                    if ([] !== $op->parameterMetadata) {
                        $entry->methodParameterMetadata[$name] = $op->parameterMetadata;
                    }
                    if (null !== $op->block1) {
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
                    $this->flushPendingTraitUses($entry, $pendingTraits, $ownMethods);
                    $pendingTraits = [];
                    $canonical = $frame->scope[$op->arg1]->toString();
                    $name = strtolower($canonical);
                    if ($entry->isEnum) {
                        if (!isset($block->constants[$op->arg2])) {
                            throw new \LogicException('Class constant value must be a compile-time constant');
                        }
                        $entry->constants[$name] = EnumCaseSupport::createCase(
                            $entry,
                            $canonical,
                            $block->constants[$op->arg2]
                        );
                        $entry->enumCaseCanonicalNames[$name] = $canonical;
                        $entry->enumCases[] = [
                            'name' => $canonical,
                            'value' => clone $block->constants[$op->arg2],
                        ];
                        if ([] !== $op->attributeEntries) {
                            $entry->enumCaseAttributeEntries[$name] = $op->attributeEntries;
                        }
                        if (null !== $op->deprecatedMetadata) {
                            $entry->constDeprecated[$name] = $op->deprecatedMetadata;
                        }
                        break;
                    }
                    $value = $this->resolveClassConstDefineValue($frame, $block, $op);
                    if (null !== $op->arg3 && isset($block->constants[$op->arg3])) {
                        $constraint = $block->constants[$op->arg3]->typeConstraint;
                        if (null !== $constraint) {
                            $check = new Variable();
                            $check->copyFrom($value);
                            TypeCheck::assertClassConstantValue($check, $constraint, $name);
                            $value->copyFrom($check);
                        }
                    }
                    $entry->constants[$name] = $value;
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
                    break;
                default:
                    $this->flushPendingTraitUses($entry, $pendingTraits, $ownMethods);
                    $pendingTraits = [];
                    throw new \LogicException(
                        'Other class body types are not jittable for now: '.opcode_type_name($op->type)
                    );
            }
        }
        $this->flushPendingTraitUses($entry, $pendingTraits, $ownMethods);
        if ([] !== $pendingNewDefaultOps) {
            throw new \LogicException('Unterminated property default `new` initializer in class body');
        }
        foreach ($entry->properties as $prop) {
            $this->linkPropertyHooks($entry, $prop);
        }
        $this->linkStaticPropertyHooks($entry);
    }

    private function resolveClassConstDefineValue(Frame $frame, Block $block, OpCode $op): Variable
    {
        if (isset($block->constants[$op->arg2])) {
            $value = new Variable();
            $value->copyFrom($block->constants[$op->arg2]);

            return $value;
        }
        if (isset($frame->scope[$op->arg2])) {
            return VM\ClassConstMaterializer::detachConstantValue($frame->scope[$op->arg2]);
        }
        throw new \LogicException('Class constant value must be a compile-time constant');
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
            $storage = clone $frame->scope[$declareOp->arg3];
            $storage->copyFrom($value);
            $entry->staticProperties[$name] = $storage;

            return;
        }

        $property = new VM\ClassProperty(
            $frame->scope[$declareOp->arg1]->toString(),
            null,
            $frame->scope[$declareOp->arg3],
            $declareOp->propertyReadonly
        );
        $property->defaultInitBlock = $block->fragmentForOpcodes($pendingNewDefaultOps);
        $property->defaultInitResultSlot = $resultSlot;
        $entry->properties[] = $property;
    }

    public function initInstancePropertyDefaults(ObjectEntry $object): void
    {
        foreach ($object->class->properties as $property) {
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

    private function executePropertyDefaultInitBlock(Block $initBlock, int $resultSlot): Variable
    {
        $initFrame = $initBlock->getFrame($this->context);
        $this->context->push($initFrame);
        $status = $this->runFrames();
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
            || OpCode::TYPE_FUNCCALL_EXEC_RETURN === $type;
    }

    private function isClassBodyDefaultInitOpcode(int $type): bool
    {
        return OpCode::TYPE_INIT_ARRAY === $type
            || OpCode::TYPE_ADD_ARRAY_ELEMENT === $type
            || OpCode::TYPE_ARRAY_SPREAD === $type;
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
                if ($class->isEnum) {
                    throw new \Error("Cannot instantiate enum {$class->name}");
                }
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
                $frame->callArgs = [];
                $frame->callArgEntries = [];
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
                    $ht->append($frame->scope[$op->arg2]);

                    break;
                }
                $key = $frame->scope[$op->arg3]->resolveIndirect();
                $value = $frame->scope[$op->arg2];
                if ($key->is(Variable::TYPE_OBJECT) || $key->is(Variable::TYPE_ARRAY)) {
                    throw new \TypeError('Illegal offset type');
                }
                if ($key->is(Variable::TYPE_INTEGER) || $key->is(Variable::TYPE_FLOAT)) {
                    $ht->updateIndex($key->toInt(), $value);
                } elseif ($key->is(Variable::TYPE_STRING)) {
                    $ht->update($key->toString(), $value);
                } elseif ($key->is(Variable::TYPE_BOOLEAN)) {
                    $ht->updateIndex($key->toBool() ? 1 : 0, $value);
                } else {
                    throw new \TypeError('Illegal offset type');
                }
                break;
            case OpCode::TYPE_ARRAY_SPREAD:
                $result = $frame->scope[$op->arg1];
                $source = $frame->scope[$op->arg2];
                VM\ArraySpread::spreadInto($this, $frame, $result->toArray(), $source);
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

    private function enforceReturnType(Frame $frame, ?Variable $value): void
    {
        $block = $frame->block;
        if (null === $block) {
            return;
        }
        if ($block->returnTypeNever) {
            TypeCheck::assertNeverReturn();

            return;
        }
        if ($block->returnTypeVoid) {
            TypeCheck::assertVoidReturn($value);

            return;
        }
        if ($block->returnTypeStatic) {
            if (null === $value) {
                return;
            }
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
                'Return value'
            );

            return;
        }
        if (null === $block->returnTypeConstraint || null === $value) {
            return;
        }
        $strict = $block->strictTypes;
        TypeCheck::coerceReturn(
            $value,
            $strict,
            $block->returnTypeConstraint,
            $block->returnLiteralBoolType
        );
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
        if (str_contains($name, '::')) {
            [$class, $method] = explode('::', $name, 2);
            $message = $meta->formatMethod($class, $method);
        } else {
            $message = $meta->formatFunction($name);
        }
        $this->emitDeprecatedNotice($message, $frame);
    }

    private function emitDeprecatedNotice(string $message, Frame $frame): void
    {
        $this->context->errors->triggerError(
            $message,
            ErrorReporter::E_USER_DEPRECATED,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $this->context,
            $frame
        );
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
        if (isset($classEntry->constants[$memberLc])) {
            if (isset($classEntry->constDeprecated[$memberLc])) {
                $this->emitDeprecatedNotice(
                    $classEntry->constDeprecated[$memberLc]->formatConstant(
                        $classEntry->name,
                        $memberNameRaw
                    ),
                    $frame
                );
            }
            $dest->copyFrom($classEntry->constants[$memberLc]);

            return true;
        }
        if (isset($classEntry->staticProperties[$memberLc])) {
            $dest->indirect($classEntry->staticProperties[$memberLc]);

            return true;
        }

        return false;
    }

    /**
     * Invoke user __destruct() once (Zend zend_objects_destroy_object; #3144).
     */
    public function invokeUserDestructor(ObjectEntry $object): void
    {
        if ($object->destructorInvoked) {
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
        } finally {
            $this->context->swapRunStack($savedStack);
            ObjectLifetime::releaseRef($object);
        }
    }

    private function releaseFrameObjectRefs(Frame $frame): void
    {
        foreach ($frame->scope as $slot) {
            ObjectLifetime::releaseDirectObject($slot);
        }
        foreach ($frame->iterators as $iter) {
            ObjectLifetime::releaseDirectObject($iter);
        }
    }

}
