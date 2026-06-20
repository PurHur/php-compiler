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
use PHPCompiler\Func;
use PHPCompiler\ext\standard\VmEval;
use PHPCompiler\ext\standard\VmForwardStaticCall;
use PHPCompiler\ext\standard\VmIteratorWalk;
use PHPCompiler\VM\ForeachIterator;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\CastSupport;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\DnfCheck;
use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\EnumCaseEntry;
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
use PHPCompiler\VM\WeakMapIterator;
use PHPCompiler\VM\WeakRefSupport;
use PHPCompiler\VM\ReferencableCheck;
use PHPCompiler\VM\ScriptExit;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\TraitCompositionConflictMessage;
use PHPCompiler\VM\TypedPropertyReadSignal;
use PHPCompiler\VM\VmVarFetch;
use PHPCompiler\VM\WeakRefRegistry;
use PHPCompiler\VM\Variable;
use PHPCompiler\Web\Superglobals;

class VM {
    const SUCCESS = 1;
    const FAILURE = 2;

    private static ?self $running = null;

    /** Frame executing the current opcode (property hook ref read/write, #6426). */
    private ?Frame $executingFrame = null;

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
                    ? VM\BuiltinExceptionSupport::materializeNativeError($this->context, $native)
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
    public function coerceVariableToString(Variable $var, ?Frame $frame = null): string
    {
        $var = $var->resolveIndirect();
        if (Variable::TYPE_OBJECT !== $var->type) {
            return $var->toString($this, $frame);
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
                throw new VM\MagicMethodInvocationAborted();
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
        $methodLc = strtolower($methodName);
        [$declaring] = $this->resolveInstanceMethod($object->class, $methodLc);
        $func = $declaring->methods[$methodLc];
        $thisVar = new Variable();
        $thisVar->object($object);
        if ($func instanceof Func\Internal) {
            $caller = $this->coercionCallerFrame();
            $result = new Variable();
            $catchFrame = $this->invokeVmClassMethod($func, $caller, $result, $thisVar, ...$extraArgs);
            if (null !== $catchFrame) {
                throw new VM\MagicMethodInvocationAborted();
            }

            return $result;
        }
        if (!$func instanceof Func\PHP) {
            throw new \LogicException("{$declaring->name}::{$methodName}() is not invokable in this compiler build");
        }

        return $this->invokePhpFunction($func, $thisVar, ...$extraArgs);
    }

    public function objectImplementsArrayAccess(ObjectEntry $object): bool
    {
        return VM\InterfaceCheck::entryImplements($object->class, 'arrayaccess', $this->context);
    }

    /** Array, ArrayAccess, or Traversable RHS for guarded list destructuring (#4325, #7440, #7452). */
    private function variableIsListDestructUnpackable(Variable $value): bool
    {
        $value = $value->resolveIndirect();
        if (Variable::TYPE_ARRAY === $value->type) {
            return true;
        }
        if (Variable::TYPE_OBJECT !== $value->type) {
            return false;
        }
        if ($this->objectImplementsArrayAccess($value->toObject())) {
            return true;
        }

        return VM\IterableCheck::isIterable($value, $this->context);
    }

    /**
     * Snapshot array literal elements so compiler expr temps can be reused (#5593, #5598, #5627).
     */
    private function materializeArrayElementForStorage(Variable $value): Variable
    {
        if (!$value->isIndirect()) {
            return $value;
        }
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

        $resultOut->copyFrom($this->invokePhpFunction($func, $thisVar, $key));

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
        $this->invokePhpFunction($func, $thisVar, $key, $value);

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
        $this->invokePhpFunction($func, $thisVar, $key, $value);
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

        $resultOut->copyFrom($this->invokePhpFunction($func, $thisVar, $key));

        return null;
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
        $this->invokePhpFunction($func, $thisVar, $key);

        return null;
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
        $handlerFrame->calledArgs = $args;
        $handlerFrame->returnVar = $returnVar;

        return $this->executeInternalHandler($handlerFrame, $callerFrame);
    }

    /**
     * isset($obj->prop) — Zend zend_std_has_property / __isset parity (#3298, #4586).
     * Hooked properties with get hook invoke get when backing is initialized (#9696, zend_property_hooks.c).
     */
    public function objectPropertyIsSet(ObjectEntry $object, string $propName, ?Frame $frame = null): bool
    {
        $hookedIsset = $this->issetHookedPropertyForIssetEmpty($object, $propName, $frame);
        if (null !== $hookedIsset) {
            return $hookedIsset;
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
     * ?? / ??= on property hooks — Zend checks backing null/uninit, not get-hook return (#6472, #8902).
     */
    public function objectPropertyIsSetForCoalesceAssign(ObjectEntry $object, string $propName, ?Frame $frame = null): bool
    {
        $hookedIsset = $this->issetHookedPropertyWithoutGetHook($object, $propName);
        if (null !== $hookedIsset) {
            return $hookedIsset;
        }

        return $this->objectPropertyIsSet($object, $propName, null);
    }

    /**
     * ?? / ??= on static property hooks — read backing without get hook (#9683, zend_property_hooks.c).
     */
    public function fetchStaticPropertyForCoalesce(string $classLc, string $propNameRaw, Variable $dst): void
    {
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
     * ?? / ??= isset probe on hooked properties — backing only, never get hook (#8902, #6472).
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
     * isset($obj->hooked) — uninitialized backing probes without get; initialized invokes get (#9696, zend_std_has_property).
     *
     * @return bool|null null when the property is not hook-backed
     */
    private function issetHookedPropertyForIssetEmpty(ObjectEntry $object, string $propName, ?Frame $frame): ?bool
    {
        if (!$this->instancePropertyHasHooks($object, $propName)) {
            return null;
        }
        $backing = $this->hookedPropertyBackingValue($object, $propName);
        if (false === $backing) {
            return null;
        }
        $uninit = $backing->isUndefined() || VM\TypedPropertyCheck::isUninitialized($backing);
        if (!$this->instancePropertyHasGetHook($object, $propName)) {
            if ($uninit) {
                return false;
            }

            return Variable::TYPE_NULL !== $backing->type;
        }
        if ($uninit) {
            if (null !== $frame && $this->hookedPropertyUsesVirtualIssetEmptySemantics($object, $propName)) {
                $hookValue = $this->fetchVirtualGetOnlyHookForIssetEmpty($object, $propName, $frame);
                if (null !== $hookValue) {
                    $value = $hookValue->resolveIndirect();

                    return Variable::TYPE_NULL !== $value->type;
                }
            }

            return false;
        }
        if (null === $frame) {
            return Variable::TYPE_NULL !== $backing->type;
        }
        try {
            $hookValue = $this->fetchPropertyWithHooks($object, $propName, $frame);
        } catch (VM\PropertyHookRefWriteSignal) {
            return false;
        }
        if (null === $hookValue) {
            return Variable::TYPE_NULL !== $backing->type;
        }
        $value = $hookValue->resolveIndirect();

        return Variable::TYPE_NULL !== $value->type;
    }

    /**
     * Virtual hooked properties route isset/empty through get (#9832, zend_property_hooks.c).
     */
    private function hookedPropertyUsesVirtualIssetEmptySemantics(ObjectEntry $object, string $propName): bool
    {
        if (!$this->instancePropertyHasGetHook($object, $propName)) {
            return false;
        }
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

    /**
     * Virtual get-only hooked properties invoke get for isset/empty (#9832, zend_property_hooks.c).
     *
     * @return Variable|null null when get hook must not run
     */
    private function fetchVirtualGetOnlyHookForIssetEmpty(
        ObjectEntry $object,
        string $propName,
        Frame $frame
    ): ?Variable {
        if (!$this->hookedPropertyUsesVirtualIssetEmptySemantics($object, $propName)) {
            return null;
        }
        try {
            return $this->fetchPropertyWithHooks($object, $propName, $frame);
        } catch (VM\PropertyHookRefWriteSignal) {
            return null;
        }
    }

    /**
     * ?? / ??= left branch on property hooks — read backing without get hook (#6472, #8902).
     */
    public function fetchObjectPropertyForCoalesce(ObjectEntry $object, string $propName, Variable $dst): void
    {
        $backing = $this->hookedPropertyBackingValue($object, $propName);
        if (false !== $backing) {
            $dst->copyFrom($backing);

            return;
        }
        if ($object->hasProperty($propName)) {
            $dst->copyFrom($object->getProperty($propName));
        } else {
            $dst->undefined();
        }
    }

    /**
     * empty($obj->prop) — uninitialized typed slots are empty without read (#6787, zend_object_handlers.c);
     * dynamic / __isset-only properties keep isset semantics (#3298).
     */
    public function emptyObjectProperty(ObjectEntry $object, string $propName, Frame $frame, Variable $dst): ?Frame
    {
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
        $meta = $this->classPropertyMeta($object, $propName);
        if (null === $meta || !$meta->prototype->isUndefined()) {
            $dst->bool(!$this->objectPropertyIsSet($object, $propName, $frame));

            return null;
        }
        $catchFrame = $this->enforcePropertyVisibilityRead($object, $propName, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        if ($object->hasProperty($propName)) {
            $props = $object->getRawProperties();
            if (isset($props[$propName]) && VM\TypedPropertyCheck::isUninitialized($props[$propName])) {
                $dst->bool(true);

                return null;
            }
            $slot = $object->getProperty($propName);
            $dst->bool(!ext\standard\boolval::isTruthy($slot));

            return null;
        }
        if ($this->propertyReadUsesMagicGet($object, $propName, $frame)) {
            $read = new Variable();
            $this->deliverMagicGetRead($read, $object, $propName);
            $dst->bool(!ext\standard\boolval::isTruthy($read));

            return null;
        }
        $dst->bool(true);

        return null;
    }

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
     * unset($obj->hooked) — invoke unset hook, reset separate backing, or Error (#6471, #6502).
     */
    private function dispatchHookedInstancePropertyUnset(ObjectEntry $object, string $propName, Frame $frame): ?Frame
    {
        if ($this->isPropertyHookRawWrite($frame, $propName)) {
            $this->unsetHookedInstancePropertyRaw($object, $propName);

            return null;
        }
        if ($this->invokeInstancePropertyUnsetHook($object, $propName, $frame)) {
            return null;
        }
        $meta = $this->classPropertyMeta($object, $propName);
        if (null !== $meta && (null !== $meta->getHookMethodLc || null !== $meta->setHookMethodLc)) {
            if (!$this->hookedPropertyHasSeparateBacking($object, $propName)) {
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
        }
        $this->unsetHookedInstanceProperty($object, $propName);

        return null;
    }

    /** unset(Class::$hooked) — unset hook, separate backing reset, or Error (#6502). */
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
            $propMeta = $this->context->propertyHookRegistry[$classLc][$propLc]
                ?? $this->context->propertyHookRegistry[$classLc][$propNameRaw]
                ?? null;
            $backingName = is_array($propMeta)
                ? ($propMeta['setBacking'] ?? $propMeta['getBacking'] ?? null)
                : null;
            $separateBacking = null !== $backingName && strcasecmp($backingName, $propLc) !== 0;
            if (!$separateBacking) {
                $className = $this->context->classes[$classLc]->name ?? $classLc;

                return $this->raiseVirtualPropertyHookUnsetError(
                    $className,
                    $propNameRaw,
                    $frame
                );
            }
        }
        $storage->reset();
        $storage->type = Variable::TYPE_UNDEFINED;

        return null;
    }

    private function hookedPropertyHasSeparateBacking(ObjectEntry $object, string $propName): bool
    {
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

        return strcasecmp($backingName, $propName) !== 0 && $object->hasProperty($backingName);
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
            if (null !== $backingName && strcasecmp($backingName, $propName) !== 0) {
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
            if (null !== $backingName && strcasecmp($backingName, $propNameRaw) !== 0) {
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
     * empty($obj->hooked) — uninitialized backing probes without get; initialized invokes get (#9696, zend_std_has_property).
     */
    private function emptyHookedProperty(ObjectEntry $object, string $propName, Frame $frame, Variable $dst): bool
    {
        if (!$this->instancePropertyHasHooks($object, $propName)) {
            return false;
        }
        $backing = $this->hookedPropertyBackingValue($object, $propName);
        if (false === $backing) {
            return false;
        }
        $uninit = $backing->isUndefined() || VM\TypedPropertyCheck::isUninitialized($backing);
        if (!$this->instancePropertyHasGetHook($object, $propName)) {
            if ($uninit) {
                $dst->bool(true);

                return true;
            }
            $dst->bool(!ext\standard\boolval::isTruthy($backing));

            return true;
        }
        if ($uninit || $this->hookedPropertyUsesVirtualIssetEmptySemantics($object, $propName)) {
            $hookValue = $this->fetchVirtualGetOnlyHookForIssetEmpty($object, $propName, $frame);
            if (null !== $hookValue) {
                $value = $hookValue->resolveIndirect();
                $dst->bool(!ext\standard\boolval::isTruthy($value));

                return true;
            }
            if ($uninit) {
                $dst->bool(true);

                return true;
            }
        }
        $hookValue = $this->fetchPropertyWithHooks($object, $propName, $frame);
        if (null === $hookValue) {
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
            $slot = $object->getProperty($backingName);
            if (VM\TypedPropertyCheck::propertyAllowsNull($slot)) {
                $slot->null();
            } else {
                $slot->reset();
                $slot->type = Variable::TYPE_UNDEFINED;
            }
        }
        if (0 !== strcasecmp($backingName, $propName)) {
            $this->unsetObjectProperty($object, $propName);
        }
    }

    /** Clear registry-recorded get/set backing field after hooked-property unset (#6471, #5191). */
    private function resetHookedPropertyBackingField(ObjectEntry $object, string $propName): void
    {
        $meta = $this->classPropertyMeta($object, $propName);
        if (null === $meta || null === $meta->setHookMethodLc) {
            return;
        }
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
        $slot = $object->getProperty($backingName);
        if (VM\TypedPropertyCheck::propertyAllowsNull($slot)) {
            $slot->null();

            return;
        }
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
                'Object of class '.$var->toEnumCase()->enumClass->name.' could not be converted to string'
            );
        }
        if (Variable::TYPE_OBJECT !== $var->type) {
            return $var->toString($this, $frame);
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
     * Properties for var_dump / print_r when __debugInfo is defined (Zend parity, #3259, #6604).
     *
     * @return array<string, Variable>
     */
    public function getObjectDebugProperties(ObjectEntry $object, ?Frame $frame = null): array
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
        if (null !== $frame) {
            return $this->collectDebugPropertiesForBuiltin($object, $frame);
        }

        return $object->class->getProperties($object->getRawProperties(), ClassEntry::PROP_PURPOSE_DEBUG);
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
     * var_dump()/print_r() property list — mangled keys, get hooks invoked (#6604).
     *
     * php-src: zend_get_properties_for(..., ZEND_PROP_PURPOSE_DEBUG) + zend_read_property_ex
     *
     * @return array<string, Variable>
     */
    private function collectDebugPropertiesForBuiltin(ObjectEntry $object, Frame $frame): array
    {
        $ctx = $this->context;
        $scopeFrame = $frame;
        while (null !== $scopeFrame && null !== $scopeFrame->handler) {
            $scopeFrame = $scopeFrame->parent;
        }
        if (null === $scopeFrame) {
            $scopeFrame = $frame;
        }
        $hookBackingLc = $this->separatePropertyHookBackingNameSet($object);
        /** @var array<string, Variable> $result */
        $result = [];
        /** @var array<string, true> $seenLc */
        $seenLc = [];
        foreach (array_reverse(\PHPCompiler\ext\standard\VmReflection::classHierarchyChain($object->class, $ctx)) as $class) {
            foreach ($class->properties as $meta) {
                $lc = strtolower($meta->name);
                if (isset($seenLc[$lc]) || isset($hookBackingLc[$lc])) {
                    continue;
                }
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
                    if (VM\TypedPropertyCheck::omitFromPropertyEnumeration($value)) {
                        continue;
                    }
                    $key = \PHPCompiler\ext\standard\VmReflection::manglePropertyKey($meta, $ctx);
                    $copy = new Variable();
                    $copy->copyFrom($value);
                    $result[$key] = $copy;

                    continue;
                }
                if (!$object->hasProperty($meta->name)) {
                    continue;
                }
                $value = $object->getProperty($meta->name)->resolveIndirect();
                if (VM\TypedPropertyCheck::omitFromPropertyEnumeration($value)) {
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
            if (isset($seenLc[$nameLc]) || isset($hookBackingLc[$nameLc])) {
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

    /**
     * Declared + dynamic properties for get_object_vars() get-hook reads (#5203, #6453).
     *
     * php-src: zend_hooked_object_build_properties + zend_read_property_ex
     *
     * @return array<string, Variable>
     */
    public function collectObjectVarsForBuiltin(ObjectEntry $object, Frame $frame): array
    {
        return $this->collectObjectPropertiesForBuiltin($object, $frame, false);
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
        $hookBackingLc = $forVarExport ? $this->separatePropertyHookBackingNameSet($object) : [];
        /** @var array<string, Variable> $result */
        $result = [];
        /** @var array<string, true> $seenLc */
        $seenLc = [];
        foreach (array_reverse(\PHPCompiler\ext\standard\VmReflection::classHierarchyChain($object->class, $ctx)) as $class) {
            foreach ($class->properties as $meta) {
                $lc = strtolower($meta->name);
                if (isset($seenLc[$lc]) || isset($hookBackingLc[$lc])) {
                    continue;
                }
                if (JitMcjitEmbed::isEmbedClassPadProperty($meta->name)) {
                    continue;
                }
                $seenLc[$lc] = true;
                if (!$forVarExport && !$this->isPropertyAccessibleForObjectVars($meta, $callerClassLc)) {
                    continue;
                }
                if ($meta->propertyHookVirtual && null === $meta->getHookMethodLc) {
                    continue;
                }
                if (null !== $meta->getHookMethodLc) {
                    $hookValue = $this->fetchPropertyWithHooks($object, $meta->name, $scopeFrame);
                    if (null === $hookValue) {
                        continue;
                    }
                    $value = $hookValue->resolveIndirect();
                    if (VM\TypedPropertyCheck::omitFromPropertyEnumeration($value)) {
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
                if (VM\TypedPropertyCheck::omitFromPropertyEnumeration($value)) {
                    continue;
                }
                $copy = new Variable();
                $copy->copyFrom($value);
                $result[$meta->name] = $copy;
            }
        }
        foreach ($object->getRawProperties() as $name => $prop) {
            $nameLc = strtolower($name);
            if (isset($seenLc[$nameLc]) || isset($hookBackingLc[$nameLc])) {
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

    /**
     * Public properties for plain-object serialize() — get hooks invoked (#6474, zend_property_hooks.c / var.c).
     *
     * @return array<string, Variable>
     */
    public function collectPublicPropertiesForSerialize(ObjectEntry $object, Frame $frame): array
    {
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
                    if (VM\TypedPropertyCheck::omitFromPropertyEnumeration($value)) {
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
                if (VM\TypedPropertyCheck::omitFromPropertyEnumeration($value)) {
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
            if (VM\TypedPropertyCheck::omitFromPropertyEnumeration($value)) {
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
     */
    public function assignUnserializeProperty(
        ObjectEntry $object,
        string $propName,
        Variable $value,
        Frame $frame
    ): void {
        if ($this->assignHookedPropertyBackingStorage($object, $propName, $value)) {
            return;
        }
        $hookFrame = $this->resolvePropertyHookParentFrame($frame);
        $writeLvalue = new Variable();
        $writeLvalue->objectPropertyOwner = $object;
        $writeLvalue->objectPropertyName = $propName;
        if ($this->dispatchPropertySetHookAssign($writeLvalue, $value, $hookFrame)) {
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
            $callerDisplay = null;
            if (null !== $callerClassLc && isset($this->context->classes[$callerClassLc])) {
                $callerDisplay = $this->context->classes[$callerClassLc]->name;
            }
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
        try {
            [$declaringClass, $methodLc] = $this->resolveInstanceMethod($class, '__construct');
            $vis = $declaringClass->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
            $callerClassLc = $this->callerClassLc($frame);
            $callerDisplay = null;
            if (null !== $callerClassLc && isset($this->context->classes[$callerClassLc])) {
                $callerDisplay = $this->context->classes[$callerClassLc]->name;
            }
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
     * Zend zend_std_clone_object: shallow copy then user __clone() when defined (#3170).
     *
     * Must run on an isolated run stack with parent frame linkage — nested runFrames() from
     * invokePhpFunctionOnStack would pop the clone opcode caller off the shared stack (#10165).
     */
    protected function invokeCloneMagicMethod(ObjectEntry $object, Frame $parentFrame): void
    {
        $class = $object->class;
        if (!isset($class->methods['__clone'])) {
            return;
        }
        $func = $class->methods['__clone'];
        $thisVar = new Variable(Variable::TYPE_OBJECT);
        $thisVar->object($object);
        $savedStack = null !== $this->context->currentFiber
            ? null
            : $this->context->swapRunStack(null);
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
            if (self::FIBER_SUSPEND === $result) {
                throw new \LogicException('Fiber suspend during __clone() is not supported in this compiler build');
            }
            if (self::SUCCESS !== $result) {
                throw new \LogicException('__clone() invocation failed in this compiler build');
            }
        } finally {
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
                fn (string $classLc, string $ancestorLc): bool => $this->isClassSameOrSubclassOf($classLc, $ancestorLc),
                $meta->getVisibility
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
        if (VM\ObjectReadonlySupport::isDynamicReadonly($object)) {
            $thrown = VM\BuiltinExceptionSupport::materializeError(
                $this->context,
                VM\ObjectReadonlySupport::modifyObjectMessage($object)
            );
            $this->raiseUncaughtException($thrown);
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
        if ($this->instanceMethodReturnsByRef($object, '__get')) {
            return $this->invokeMagicGet($object, $name);
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
        return $this->invokeClosureFrom(null, $closureState, true, ...$args);
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
            $this->context->push($child);
            $result = $this->runFrames();
            if (self::SUCCESS !== $result) {
                throw new \LogicException('Closure invocation failed in this compiler build');
            }

            return $out->resolveIndirect();
        } finally {
            if ($isolated) {
                $this->context->swapRunStack($savedStack);
            }
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

        $returnSlot = new Variable();

        return $this->runFiberExecution($fiber, $returnSlot);
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
                    $out->copyFrom($fiber->suspendReturn);

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
            $out->copyFrom($fiber->suspendReturn);

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
        $this->context->pendingException = null;
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
        if (Variable::TYPE_OBJECT === $iterator->type) {
            if (null === $frame) {
                throw new \LogicException('iterator_to_array() on Traversable object requires VM frame');
            }

            return $this->iteratorObjectToArray($frame, $iterator, $preserveKeys);
        }

        throw new \TypeError(
            'iterator_to_array(): Argument #1 ($iterator) must be of type '.IterableCheck::TYPE_LABEL
        );
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
        $key = $key->resolveIndirect();
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
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
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
            $this->assertDeferredDefinitionsBeforeRuntime($op->type);
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
                    $arg3 = isset($frame->block->constants[$op->arg3])
                        ? $frame->block->constants[$op->arg3]
                        : $frame->scope[$op->arg3];
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
                    $catchFrame = $this->enforceReadonlyPropertyWrite($arg2, $frame);
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
                            $stored = VM\EnumCaseSupport::materializeConstantValue($this->context, $arg3);
                            $arg2->copyFrom($stored);
                            $arg1->copyFrom($stored);
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
                    if (
                        $op->arg2 !== $op->arg3
                        && $frame->block->assignTempSlotIsDead((int) $op->arg3)
                    ) {
                        $arg3->null();
                    }
                    if (
                        $op->arg1 !== $op->arg2
                        && $op->arg1 !== $op->arg3
                        && $frame->block->assignTempSlotIsDead((int) $op->arg1)
                    ) {
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
                    $this->markScopeSlotInitialized($frame, (int) $op->arg2);
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
                    if (null !== ($msg = $this->asymmetricPropertyWriteMessage($lhs, $frame))) {
                        $catchFrame = $this->dispatchVmError($msg, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    }
                    $this->emitPropertyWriteDeprecation($lhs, $frame);
                    $rhsSlot = $frame->scope[$op->arg2];
                    // Reference acquisition follows set visibility (php.net asymmetric visibility, #7070).
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
                    $catchFrame = $this->guardUnboundThisRead($frame, (int) $op->arg2);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $rhs = $rhsSlot->resolveIndirect();
                    // ArrayDimFetch / property fetch temps are indirect to live storage; write the
                    // reference into that cell instead of redirecting the temp (#5349).
                    $writeTarget = $lhs->isIndirect() ? $lhs->directIndirectTarget() : $lhs;
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
                    $hookRefLvalue = $this->resolvePropertyHookRefWriteLvalue($rhsSlot, $frame);
                    if (null !== $hookRefLvalue) {
                        if (!$this->propertyWriteHasSetHook($hookRefLvalue)) {
                            $catchFrame = $this->enforceVirtualPropertyHookWrite($hookRefLvalue, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        }
                        $stableLvalue = $this->stablePropertyHookRefWriteLvalue($hookRefLvalue);
                        $hookRefVar = new Variable();
                        $hookRefVar->propertyHookRef(new VM\PropertyHookRef($this, $stableLvalue));
                        $writeTarget->indirect($hookRefVar);
                        break;
                    }
                    // Object property / static / nested ref slots are live storage (Zend FE_FETCH_R,
                    // #5245). Main-script globals use an indirect wrapper — still need a shared ref
                    // cell so unset($a) does not destroy $b (#5368).
                    if (
                        null !== $rhs->objectPropertyOwner
                        || ($rhsSlot->isIndirect() && !$this->context->isGlobalStorage($rhs))
                    ) {
                        $writeTarget->indirect($rhs);
                        break;
                    }
                    if (Variable::TYPE_INDIRECT !== $rhs->type) {
                        $ref = new Variable();
                        $ref->copyFrom($rhs);
                        $rhs->indirect($ref);
                    }
                    $writeTarget->indirect($rhs->resolveIndirect());
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
                            if (Variable::TYPE_STRING === $unpack->type) {
                                $catchFrame = $this->dispatchVmTypeError(
                                    new \TypeError(JIT\ListUnpackHelper::LIST_DESTRUCT_STRING_MESSAGE),
                                    $frame
                                );
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }
                                break;
                            }
                            $frame = $this->frameForBranch($frame, $op->block1);
                            goto restart;
                        }
                        $catchFrame = $this->materializeListDestructIterableRhs($unpackSlot, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
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
                            $this->context->errors->indirectModificationOfOverloadedElement(
                                $container->arrayAccessOffsetClassName(),
                                $this->context,
                                $frame,
                                '' !== $frame->scriptPath ? $frame->scriptPath : null
                            );
                            $arg1->null();
                            break;
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
                        $appendCell = $container->toArray()->append(new Variable);
                        $arg1->indirect($appendCell);
                        $this->tagHookedPropertyDimWriteLvalue($arg1, $containerSlot);
                        break;
                    }
                    $arg3 = $frame->scope[$op->arg3];
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
                        try {
                            if (!$forWrite && !$table->keyExists($arg3)) {
                                $this->context->errors->undefinedArrayKey(
                                    $arg3,
                                    $this->context,
                                    $frame,
                                    '' !== $frame->scriptPath ? $frame->scriptPath : null
                                );
                            }
                            $arg1->indirect($table->findVariable($arg3, $forWrite));
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
                        $frame->scope[$op->arg1]->castFrom(Variable::TYPE_INTEGER, $frame->scope[$op->arg2], $this, $frame);
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
                        $frame->scope[$op->arg1]->castFrom(Variable::TYPE_FLOAT, $frame->scope[$op->arg2], $this, $frame);
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
                    try {
                        $frame->scope[$op->arg1]->castFrom(Variable::TYPE_STRING, $frame->scope[$op->arg2], $this, $frame);
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
                        CastSupport::toArray($frame->scope[$op->arg2], $this->context->classes)
                    );
                    break;
                case OpCode::TYPE_CAST_OBJECT:
                    $dst = $frame->scope[$op->arg1];
                    $src = $frame->scope[$op->arg2]->resolveIndirect();
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
                case OpCode::TYPE_CAST_VOID:
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
                        if (
                            $op->isIncDec
                            && (OpCode::TYPE_PLUS === $op->type || OpCode::TYPE_MINUS === $op->type)
                        ) {
                            $arg1->incDecOp($op->type, $arg2, $arg3);
                        } else {
                            $arg1->numericOp($op->type, $arg2, $arg3, $this, $frame);
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
                    $arg2 = $frame->scope[$op->arg2];
                    $arg3 = $frame->scope[$op->arg3];
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
                    }
                    break;

                case OpCode::TYPE_UNARY_MINUS:
                case OpCode::TYPE_UNARY_PLUS:
                case OpCode::TYPE_BITWISE_NOT:
                    $arg1 = $frame->scope[$op->arg1];
                    $arg2 = $frame->scope[$op->arg2];
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
                        $arg2 = $this->coerceVariableToString($frame->scope[$op->arg2], $frame);
                        $arg3 = $this->coerceVariableToString($frame->scope[$op->arg3], $frame);
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
                        $printed = $this->valueToPrintString($frame->scope[$op->arg1], $frame);
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
                    } catch (\ParseError $e) {
                        $catchFrame = $this->dispatchVmParseError($e, $frame);
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
                        VM\TypedPropertyCheck::nullsafeShortCircuitReceiver($receiver)
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
                        $constName = $frame->scope[$op->arg2]->toString();
                        if (null !== $op->arg3) {
                            $constName = $frame->scope[$op->arg3]->toString().'\\'.$constName;
                        }
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
                    $frame->scope[$op->arg1]->copyFrom($value);
                    break;
                case OpCode::TYPE_STATICCALL_INIT:
                    $instanceScopeCall = false;
                    $scopeClassName = null;
                    $staticCallMethodName = '';
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
                            $lcClass = $this->resolveClassScopeName($className, $frame);
                            $callableName = $this->context->classes[$lcClass]->name.'::'.$staticCallMethodName;
                        }
                        $this->initStaticCallable($frame, $callableName, $parentKeywordScope);
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
                        $traitConstFrame = $this->enforceDirectTraitConstAccess($classEntry, $memberNameRaw, $frame);
                        if (null !== $traitConstFrame) {
                            $frame = $traitConstFrame;
                            goto restart;
                        }
                        $constLc = strtolower($memberNameRaw);
                        if (isset($classEntry->constants[$constLc])) {
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
                                    "Undefined class constant {$classEntry->name}::{$memberNameRaw}",
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

                        return $this->raise("Unknown class for constant fetch: {$className}", $frame);
                    }
                    $classEntry = $this->context->classes[$lcClass];
                    $traitConstFrame = $this->enforceDirectTraitConstAccess($classEntry, $memberNameRaw, $frame);
                    if (null !== $traitConstFrame) {
                        $frame = $traitConstFrame;
                        goto restart;
                    }
                    $constLc = strtolower($memberNameRaw);
                    if (isset($classEntry->constants[$constLc])) {
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
                    try {
                        if (!$this->copyClassConstOrStaticPropertyByName(
                            $classEntry,
                            $memberNameRaw,
                            $frame->scope[$op->arg1],
                            $frame
                        )) {
                            $catchFrame = $this->dispatchVmError(
                                "Undefined class constant {$className}::{$memberNameRaw}",
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

                        return $this->raise(
                            "Access to undeclared static property {$classLabel}::\${$propNameRaw}",
                            $frame
                        );
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
                        $dest = $frame->scope[$op->arg1];
                        $this->fetchStaticPropertyForCoalesce($lcClass, $propNameRaw, $dest);
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
                    $dest->indirect($storage);
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

                        return $this->raise(
                            "Access to undeclared static property {$classLabel}::\${$propNameRaw}",
                            $frame
                        );
                    }
                    $catchFrame = $this->enforceVirtualStaticPropertyHookUnset($lcClass, $propName, $propNameRaw, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $catchFrame = $this->enforceTypedStaticPropertyUnset($lcClass, $propNameRaw, $storage, $frame);
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
                        if ($this->objectImplementsArrayAccess($object)) {
                            $catchFrame = $this->invokeArrayAccessOffsetUnset($object, $key, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
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
                        $catchFrame = $this->enforceReadonlyPropertyUnset($object, $propName, $frame);
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
                case OpCode::TYPE_FROM_CALLABLE:
                    if (isset($frame->scope[$op->arg2])) {
                        $callable = $frame->scope[$op->arg2]->resolveIndirect();
                    } elseif (isset($frame->block->constants[$op->arg2])) {
                        $callable = $frame->block->constants[$op->arg2];
                    } else {
                        throw new \LogicException('TYPE_FROM_CALLABLE missing callable slot');
                    }
                    try {
                        $entry = VM\ClosureSupport::fromCallable($this->context, $frame, $callable);
                        $frame->scope[$op->arg1]->object($entry);
                    } catch (\Error $e) {
                        $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    } catch (\TypeError $e) {
                        $catchFrame = $this->dispatchVmTypeError($e, $frame);
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
                    $captures = $this->bindClosureCaptures($frame, $op->closureCaptures);
                    $state = new ClosureState($closureFunc, $captures);
                    $state->applyDefinitionSite($op->sourceLocation, $op->block1);
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
                    if (null !== $op->arg1 && isset($frame->scope[$op->arg1])) {
                        $catchFrame = $this->guardUnboundThisRead($frame, (int) $op->arg1);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    }
                    $returnValue = $this->resolveVmReturnValue($frame, $op);
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
                    if (Variable::TYPE_OBJECT === $callee->type) {
                        $closureState = $callee->toObject()->closureState;
                        if (null !== $closureState) {
                            $this->initClosureCall($frame, $closureState);
                            $frame->closureCallableSlot = $op->arg1;
                            break;
                        }
                        $catchFrame = $this->initMethodCall($frame, $callee, '__invoke');
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    if (Variable::TYPE_ENUM_CASE === $callee->type) {
                        $receiver = VM\EnumCaseSupport::receiverForInstanceMethod($callee);
                        $catchFrame = $this->initMethodCall($frame, $receiver, '__invoke');
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
                        try {
                            $this->initStaticCallable($frame, $name);
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
                    $lcname = strtolower($name);
                    if (!isset($this->context->functions[$lcname])) {
                        throw new \LogicException("Call to undefined function $lcname()");
                    }
                    $frame->call = $this->context->functions[$lcname];
                    $frame->callArgs = [];
                    $frame->callArgEntries = [];
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
                        throw new \LogicException('Method call on non-object');
                    }
                    $receiver = VM\EnumCaseSupport::receiverForInstanceMethod($receiver);
                    $catchFrame = $this->initMethodCall($frame, $receiver, $methodName);
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
                    $this->warnUndefinedVariableForScopeRead($frame, $argSlot);
                    $value = $this->resolveOutgoingCallArgValue($frame, $argSlot);
                    if ($this->isUnboundLocalScopeRead($frame, $argSlot)) {
                        $resolved = $value->resolveIndirect();
                        if ($resolved->isUndefined()) {
                            $sent = new Variable();
                            $sent->null();
                            $value = $sent;
                        }
                    }
                    $argIndex = \count($frame->callArgEntries);
                    if (!$this->outgoingCallArgNeedsReference($frame, $argIndex)) {
                        $snapshot = new Variable();
                        $snapshot->duplicateFrom($value);
                        $value = $snapshot;
                    }
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
                    $this->emitCallNoDiscardNotice($frame, $op);
                    if ($frame->call instanceof Func\PHP && $frame->call->block->isGenerator) {
                        try {
                            $calledArgs = $this->resolveOutgoingCallArgs($frame);
                            ReferencableCheck::assertOutgoingCallArgs($frame->call, $frame, $calledArgs);
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
                        $frame->callArgs = [];
                        $frame->callArgEntries = [];
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
                                        $frame->call->getName(),
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
                    $closureState = $this->resolvePendingClosureState($frame);
                    $frame->closureCallableSlot = null;
                    $frame->closureCall = null;
                    $frame->pendingClosureInvoke = null;
                    // Only bind captures/$this when entering the closure body, not nested $this->method() (#4927).
                    if (
                        null !== $closureState
                        && $frame->call instanceof Func\PHP
                        && $frame->call === $closureState->func
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

                            return self::FIBER_SUSPEND;
                        }
                        $frame->call = null;
                        $frame->callArgs = [];
                        $frame->callArgEntries = [];
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
                            if (TypeCheck::variadicSlotNeedsElementChecks($frame->block, $variadicSlot)) {
                                $trailing = [];
                                for ($i = $recvIdx; $i <= $variadicEndIdx; ++$i) {
                                    if (array_key_exists($i, $frame->calledArgs)) {
                                        $trailing[] = $frame->calledArgs[$i];
                                    }
                                }
                                TypeCheck::verifyVariadicElements(
                                    $trailing,
                                    $strict,
                                    $frame->block->paramVariadicElementTypeConstraints[$variadicSlot] ?? null,
                                    $frame->block->paramVariadicElementGenericArrayTypeSpecs[$variadicSlot] ?? null,
                                    $frame->block->paramVariadicElementIntersectionConstraints[$variadicSlot] ?? null,
                                    $frame->block->paramVariadicElementDnfConstraints[$variadicSlot] ?? null,
                                    $this->context,
                                    isset($frame->block->paramIterableSlots[$variadicSlot]),
                                    isset($frame->block->paramNeverSlots[$variadicSlot]),
                                    $frame->block->paramVariadicElementIntersectionDisplayLabels[$variadicSlot] ?? null
                                );
                            }
                            $variadicArgCount = 0;
                            for ($i = $recvIdx; $i <= $variadicEndIdx; ++$i) {
                                if (array_key_exists($i, $frame->calledArgs)) {
                                    ++$variadicArgCount;
                                }
                            }
                            if (
                                1 === $variadicArgCount
                                && array_key_exists($recvIdx, $frame->calledArgs)
                            ) {
                                $sole = $frame->calledArgs[$recvIdx]->resolveIndirect();
                                if (
                                    Variable::TYPE_ARRAY === $sole->type
                                    && !$sole->toArray()->isPackedList()
                                ) {
                                    $arg1->copyFrom($sole);
                                    $this->markScopeSlotInitialized($frame, (int) $op->arg1);
                                    break;
                                }
                            }
                            $arg1->newArray();
                            $packed = $arg1->toArray();
                            for ($i = $recvIdx; $i <= $variadicEndIdx; ++$i) {
                                if (!array_key_exists($i, $frame->calledArgs)) {
                                    continue;
                                }
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
                        $error = VM\ParamArgumentCountError::forTooFewAtReceive($frame, (int) $op->arg2);
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
                    try {
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
                                IterableCheck::assertParameter($arg1, $this->context);
                            } elseif (isset($frame->block->paramDnfConstraints[$op->arg1])) {
                                DnfCheck::assertMatches(
                                    $arg1,
                                    $frame->block->paramDnfConstraints[$op->arg1],
                                    $this->context
                                );
                            } elseif (isset($frame->block->paramIntersectionConstraints[$op->arg1])) {
                                TypeCheck::assertParamIntersection(
                                    $arg1,
                                    $frame->block->paramIntersectionConstraints[$op->arg1],
                                    $this->context,
                                    $frame->block->paramIntersectionDisplayLabels[$op->arg1] ?? null
                                );
                            } else {
                                TypeCheck::coerceParameter($arg1, $strict, $arraySpec);
                            }
                        }
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
                    $ifaceEntry->interfaces = $op->classImplements;
                    if ($op->isSealed) {
                        $ifaceEntry->sealed = true;
                        $ifaceEntry->sealedPermits = $this->normalizeSealedPermits($name, $op->sealedPermits);
                    }
                    if (null !== $op->block1) {
                        self::defineClass($ifaceEntry, $op->block1, $frame);
                    }
                    $this->inheritFromInterfaces($ifaceEntry);
                    $this->context->classes[$lcname] = $ifaceEntry;
                    $this->propagateInterfaceConstantsToImplementors($lcname);
                    $this->flushDeferredTraitUses();
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
                    $traitEntry->attributeNames = $op->attributeNames;
                    $traitEntry->attributeEntries = $op->attributeEntries;
                    self::defineClass($traitEntry, $op->block1, $frame);
                    $this->context->classes[$lcname] = $traitEntry;
                    $this->flushDeferredTraitUses();
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
                    if (!$this->context->defineConstant($name, $constValue)) {
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
                    $classEntry->attributeNames = $op->attributeNames;
                    $classEntry->attributeEntries = $op->attributeEntries;
                    $classEntry->classDeprecated = $op->deprecatedMetadata;
                    self::defineClass($classEntry, $op->block1, $frame);
                    $this->inheritFromInterfaces($classEntry);
                    VM\EnumSupport::ensureBuiltinCasesMethod($classEntry);
                    VM\EnumSupport::ensureBuiltinEnumInterfaces($classEntry);
                    $this->context->classes[$lcname] = $classEntry;
                    $this->context->enums[$lcname] = true;
                    $this->flushDeferredTraitUses();
                    $this->flushDeferredClassConstants();
                    break;
                case OpCode::TYPE_DECLARE_CLASS:
                    $name = $frame->scope[$op->arg1]->toString();
                    $lcname = strtolower($name);
                    if (isset($this->context->classes[$lcname])) {
                        throw new \LogicException("Duplicate class definition for $name");
                    }
                    $classEntry = new ClassEntry($name);
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
                    self::defineClass($classEntry, $op->block1, $frame);
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
                        VM\ClassValidator::finalizeClassDefinition($classEntry, $this->context);
                    }
                    $this->context->classes[$lcname] = $classEntry;
                    if ($parentPending) {
                        $this->context->deferredParentInheritance[] = [
                            'childLc' => $lcname,
                            'parentName' => $parentName,
                        ];
                    }
                    $this->flushDeferredParentInheritance();
                    $this->flushDeferredTraitUses();
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
                    $frame->call = $object->constructor;
                    $frame->callArgs = [$result];
                    $frame->callArgEntries = [];
                    if (null === $frame->call) {
                        $object->constructed = true;
                        $newResultSlot = (int) $op->arg1;
                        if (!$this->isVmScopeSlotUsedByFollowingOps($frame, $newResultSlot)) {
                            $this->releaseVmDeadScopeSlot($frame, $newResultSlot);
                        }
                    }
                    break;
                case OpCode::TYPE_PROPERTY_FETCH:
                    $result = $frame->scope[$op->arg1];
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
                        $forWrite = $this->propertyFetchDestUsedAsAssignLvalue($frame, $op);
                        $readonlyMsg = EnumCaseSupport::readonlyPseudoPropertyViolationMessage(
                            $enumEntry,
                            $name,
                            false
                        );
                        if ($forWrite && null !== $readonlyMsg) {
                            $catchFrame = $this->dispatchVmError($readonlyMsg, $frame);
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
                        $forWrite = $this->propertyFetchDestUsedAsAssignLvalue($frame, $op);
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
                        if (Variable::TYPE_NULL === $resolved->type) {
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
                        VM\LazyObjectSupport::ensureInitialized($this, $propertyObject);
                    }
                    $propertyObject = VM\LazyObjectSupport::getLazyInstance($propertyObject);
                    if (EnumCaseSupport::isEnumCase($propertyObject)) {
                        $forWrite = $this->propertyFetchDestUsedAsAssignLvalue($frame, $op);
                        $readonlyMsg = EnumCaseSupport::readonlyPseudoPropertyViolationMessage(
                            $propertyObject->class,
                            $name,
                            false
                        );
                        if ($forWrite && null !== $readonlyMsg) {
                            $catchFrame = $this->dispatchVmError($readonlyMsg, $frame);
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
                    $forWrite = $this->propertyFetchDestUsedAsAssignLvalue($frame, $op);
                    $magicGetForRead = !$forWrite
                        && !$op->propertyHookCoalesceRead
                        && $this->propertyReadUsesMagicGet($propertyObject, $name, $frame);
                    if (!$magicGetForRead && !$forWrite) {
                        $catchFrame = $this->enforcePropertyVisibilityRead($propertyObject, $name, $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                    }
                    if ($op->propertyHookCoalesceRead && !$forWrite) {
                        $this->fetchObjectPropertyForCoalesce($propertyObject, $name, $result);
                        break;
                    }
                    if ($propertyObject->hasProperty($name) && !$magicGetForRead) {
                        if (!$forWrite) {
                            $this->emitInstancePropertyAccessDeprecation($propertyObject, $name, $frame);
                        }
                        if ($forWrite) {
                            $writeProxy = new Variable();
                            $writeProxy->objectPropertyOwner = $propertyObject;
                            $writeProxy->objectPropertyName = $name;
                            $catchFrame = $this->enforceVirtualPropertyHookWrite($writeProxy, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
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
                            $result->indirect($this->fetchObjectPropertyWriteLvalue($propertyObject, $name, $frame));
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
                            $propSlot = $propertyObject->getProperty($name);
                            if ($op->nullsafeFetchPropertyRead) {
                                VM\TypedPropertyCheck::assertReadable($propSlot);
                            }
                            $result->indirect($propSlot);
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
                            $ht->append($this->materializeArrayElementForStorage(
                                $this->resolveOutgoingCallArgValue($frame, $op->arg2)
                            ));
                            break;
                        }
                        $key = $this->resolveOutgoingCallArgValue($frame, $op->arg3)->resolveIndirect();
                        $value = $this->materializeArrayElementForStorage(
                            $this->resolveOutgoingCallArgValue($frame, $op->arg2)
                        );
                        if ($key->is(Variable::TYPE_OBJECT) || $key->is(Variable::TYPE_ARRAY)) {
                            throw new \TypeError('Illegal offset type');
                        }
                        VM\EnumCaseSupport::rejectIllegalArrayOffset($key);
                        if ($key->is(Variable::TYPE_INTEGER) || $key->is(Variable::TYPE_FLOAT)) {
                            $ht->updateIndex($key->toInt(), $value);
                        } elseif ($key->is(Variable::TYPE_STRING)) {
                            $ht->update($key->toString(), $value);
                        } elseif ($key->is(Variable::TYPE_BOOLEAN)) {
                            $ht->updateIndex($key->toBool() ? 1 : 0, $value);
                        } elseif ($key->is(Variable::TYPE_NULL)) {
                            $ht->update('', $value);
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
                    VM\ArraySpread::spreadInto(
                        $this,
                        $frame,
                        $result->toArray(),
                        $source,
                        (int) ($op->arg3 ?? 0)
                    );
                    break;
                case OpCode::TYPE_CLONE:
                    $result = $frame->scope[$op->arg1];
                    $src = $frame->scope[$op->arg2]->resolveIndirect();
                    $uncloneableEnumClass = VM\EnumCaseSupport::uncloneableEnumClassForClone(
                        $src,
                        $this->context
                    );
                    if (null !== $uncloneableEnumClass) {
                        $message = 'Trying to clone an uncloneable object of class '.$uncloneableEnumClass;
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
                    $srcObject = $src->toObject();
                    $catchFrame = $this->enforceCloneVisibility($srcObject, $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $cloned = $srcObject->cloneShallow();
                    $result->object($cloned);
                    $this->invokeCloneMagicMethod($cloned, $frame);
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
                    VM\LazyObjectSupport::ensureInitialized($this, $object);
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
                                $dst->bool($container->toArray()->offsetIsSet($frame->scope[$op->arg3]));
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
                            if ($this->objectImplementsArrayAccess($object)) {
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
                            VM\LazyObjectSupport::ensureInitialized($this, $object);
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
                            $gen->currentValue->copyFrom($container->toArray()->iterCurrentValue(false));
                            $gen->hasCurrent = true;
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
                        if ($this->advanceGeneratorIteration($inner)) {
                            $gen->currentKey->copyFrom($inner->currentKey);
                            $gen->currentValue->copyFrom($inner->currentValue);
                            $gen->hasCurrent = true;
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
                        $this->completeYieldFromDelegation($gen, $frame, $op, null);
                        break;
                    }
                    $this->throwYieldFromInvalidContainer($container);
                case OpCode::TYPE_ITER_RESET:
                    $container = $frame->scope[$op->arg1]->resolveIndirect();
                    unset($this->context->foreachInvalidSlots[$op->arg1]);
                    if ($this->variableIsGenerator($container)) {
                        unset($this->context->foreachObjectAdvance[$op->arg1]);
                        unset($this->context->objectPropertyIterators[$op->arg1]);
                        unset($this->context->weakMapIterators[$op->arg1]);
                        $frame->iterators[$op->arg1] = $container;
                        $this->context->foreachIterators[$op->arg1] = $container;
                        $container->toObject()->generatorState->rewind();
                        break;
                    }
                    if (Variable::TYPE_ARRAY === $container->type) {
                        unset($this->context->foreachObjectAdvance[$op->arg1]);
                        unset($this->context->objectPropertyIterators[$op->arg1]);
                        unset($this->context->weakMapIterators[$op->arg1]);
                        $frame->iterators[$op->arg1] = $container;
                        $this->context->foreachIterators[$op->arg1] = $container;
                        $container->toArray()->iterReset();
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
                                $iterable->toObject()->generatorState->rewind();
                                break;
                            }
                            $this->context->foreachObjectAdvance[$op->arg1] = false;
                            $this->invokeForeachInstanceMethod($frame, $iterable, 'rewind');
                            break;
                        } catch (\TypeError) {
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
                        }
                    }
                    $this->warnForeachNonTraversable($container, $frame);
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
                        if ((bool) $op->arg3) {
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
                        throw new \LogicException('Iterator value requires an array');
                    }
                    $byRef = (bool) $op->arg3;
                    if ($byRef) {
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
        if ([] !== $this->context->deferredTraitUses) {
            $this->finalizeDeferredTraitUses();
        }
        if ([] !== $this->context->deferredClassConstants) {
            $this->finalizeAllDeferredClassConstants();
        }
        if ([] !== $this->context->deferredParentInheritance) {
            $this->finalizeDeferredParentInheritance();
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
            if ($this->isFunctionStaticInitContinueReturn($frame)) {
                $entry = $frame->parent;
                if (null !== $entry->returnVar) {
                    $entry->returnVar->copyFrom($returnValue);
                }
                $this->releaseFrameObjectRefs($frame);
                $caller = $this->context->pop();
                if (null !== $caller) {
                    $caller->callArgs = [];
                    $caller->callArgEntries = [];
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
        if (null === $func || null === $func->class) {
            return false;
        }
        if ((($func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) !== 0) {
            return true;
        }

        return !isset($frame->scope[$thisIdx]);
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
        $catchFrame = $this->enforceVirtualPropertyHookWrite($write, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
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
                if ($increment) {
                    $working->applyIncrement();
                } else {
                    $working->applyDecrement();
                }
                $write->copyFrom($working);
                $result->copyFrom($working);
            } else {
                $old = new Variable();
                $old->copyFrom($working);
                if ($increment) {
                    $working->applyIncrement();
                } else {
                    $working->applyDecrement();
                }
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

    private function isUnboundLocalScopeRead(Frame $frame, int $slot): bool
    {
        if (!isset($frame->scope[$slot])) {
            return false;
        }
        if (null === $frame->block || $frame->block->isMainScript() || $frame->block->inheritUndefinedLocals) {
            return false;
        }
        $name = $this->resolveScopeSlotVariableName($frame, $slot);
        if (null === $name || 'this' === $name) {
            return false;
        }
        if (null !== $name && $frame->block->declaresGlobalName($name)) {
            return false;
        }
        $resolved = $frame->scope[$slot]->resolveIndirect();
        if ($resolved->isUndefined()) {
            return true;
        }
        if (null !== $this->context->globalNameForStorage($resolved)) {
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
                    $working->applyIncrement();
                } else {
                    $working->applyDecrement();
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
                $working->applyIncrement();
            } else {
                $working->applyDecrement();
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
     * Resolve `$operand::class` (Zend zend_compile.c FETCH_CLASS on enum case / object).
     */
    private function resolveClassPseudoConstFromOperand(Variable $operand): ?string
    {
        $operand = $operand->resolveIndirect();
        if (Variable::TYPE_ENUM_CASE === $operand->type) {
            return $operand->toEnumCase()->enumClass->name;
        }
        if (Variable::TYPE_OBJECT === $operand->type) {
            return $operand->toObject()->class->name;
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

    /** True when a following opcode assigns through this PROPERTY_FETCH destination slot (#5370). */
    private function propertyFetchDestUsedAsAssignLvalue(Frame $frame, OpCode $op): bool
    {
        $destSlot = (int) $op->arg1;
        for ($j = $frame->pos, $n = $frame->block->nOpCodes; $j < $n; $j++) {
            $candidate = $frame->block->opCodes[$j] ?? null;
            if (null === $candidate) {
                continue;
            }
            if (OpCode::destSlotUsedAsAssignLvalue($candidate, $destSlot)) {
                return true;
            }
        }

        return false;
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

    /** True when fetch dest is the container for a following dim write ($prop[] = / $prop[k] =, #6775). */
    private function propertyFetchDestUsedAsDimWriteContainer(Frame $frame, OpCode $op): bool
    {
        $destSlot = (int) $op->arg1;
        $next = $frame->block->opCodes[$frame->pos] ?? null;
        if (null === $next) {
            return false;
        }

        return OpCode::destSlotUsedAsDimWriteContainer($next, $destSlot);
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
            }
        }
        try {
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
        } catch (VM\NativeDateInvalidTimeZoneException $e) {
            return $this->dispatchVmDateInvalidTimeZoneException($e, $callerFrame);
        } catch (VM\NativeDateMalformedStringException $e) {
            return $this->dispatchVmDateMalformedStringException($e, $callerFrame);
        } catch (VM\NativeDateRangeError $e) {
            return $this->dispatchVmDateRangeError($e, $callerFrame);
        } catch (VM\NativeDateObjectError $e) {
            return $this->dispatchVmDateObjectError($e, $callerFrame);
        } catch (\Error $e) {
            return $this->dispatchVmError($e->getMessage(), $callerFrame);
        } catch (VM\GeneratorUncaughtThrow $e) {
            return $this->dispatchUncaughtGeneratorThrow($e->thrown, $callerFrame);
        } catch (VM\FiberUncaughtThrow $e) {
            return $this->dispatchUncaughtGeneratorThrow($e->thrown, $callerFrame);
        } catch (TypedPropertyReadSignal $signal) {
            $catchFrame = $this->findCatchFrameForThrow($callerFrame, $signal->errorObject);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            $this->raiseUncaughtException($signal->errorObject);

            return null;
        } catch (ScriptExit $e) {
            throw $e;
        } catch (\LogicException $e) {
            return $this->dispatchVmLogicException($e, $callerFrame);
        } catch (VM\MagicMethodInvocationAborted) {
            $this->clearTryCatchUnwindState();
            $callerFrame->call = null;
            $callerFrame->callArgs = [];
            $callerFrame->callArgEntries = [];
            $callerFrame->suppressNextEcho = true;
            ++$callerFrame->pos;

            return null;
        } catch (\Exception $e) {
            return $this->dispatchVmEngineException($e->getMessage(), $callerFrame);
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

    /** Bridge native Exception from builtins (e.g. Generator::rewind after run, #5195). */
    private function dispatchVmEngineException(string $message, Frame $frame): ?Frame
    {
        $thrown = $this->makeEngineError($message, 'Exception');
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
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
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
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
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeTypeError(
            $this->context,
            $error->getMessage(),
            $file,
            $line
        );
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            if ($this->stashPropertyHookSetExternalCatch($frame, $catchFrame)) {
                return null;
            }

            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    /** ASSIGN to ArrayAccess lvalue — dispatch deferred offsetSet TypeError (#8949). */
    private function assignCopyFrom(Variable $dst, Variable $src, Frame $frame): ?Frame
    {
        try {
            $dst->copyFrom($src);

            return null;
        } catch (\TypeError $e) {
            $resolved = $dst->resolveIndirect();
            if ($resolved->isArrayAccessOffset()) {
                $dst->null();
            }

            return $this->dispatchVmTypeError($e, $frame);
        }
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
     * Bridge native ArithmeticError from stdlib builtins into user catch handlers (#4724).
     */
    private function dispatchVmArithmeticError(\ArithmeticError $error, Frame $frame): ?Frame
    {
        $thrown = VM\BuiltinExceptionSupport::materializeArithmeticError($this->context, $error->getMessage());
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
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
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
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            if ($this->stashPropertyHookSetExternalCatch($frame, $catchFrame)) {
                return null;
            }

            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

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
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
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
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
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
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    /** Bridge native JsonException from ext/json builtins into user catch handlers (#3281). */
    private function dispatchVmJsonException(\JsonException $error, Frame $frame): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeJsonException(
            $this->context,
            $error->getMessage(),
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
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    /** Bridge malformed DateTime strings from date builtins into user catch handlers (#7113). */
    private function dispatchVmDateMalformedStringException(
        VM\NativeDateMalformedStringException $error,
        Frame $frame
    ): ?Frame
    {
        [$file, $line] = VM\ExceptionSupport::userFatalSite($frame);
        $thrown = VM\BuiltinExceptionSupport::materializeException(
            $this->context,
            $error->getMessage(),
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
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
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
                $catchFrame = $this->dispatchCatchForHandlerFrame($handler);
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
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
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
        for ($handler = $frame->parent; null !== $handler; $handler = $handler->parent) {
            $finallyOp = $this->findFinallyOpForHandler($handler);
            if (null === $finallyOp || null === $finallyOp->block1) {
                continue;
            }
            if ($finallyOp->block1 === $frame->block) {
                return true;
            }
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
            VM\ExceptionSupport::emitNativeUncaughtFatal(
                VM\ExceptionSupport::nativeUncaughtThrowable(
                    $entry,
                    VM\ExceptionSupport::readThrowableMessage($entry)
                )
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
        VM\ExceptionSupport::emitNativeUncaughtFatalWithNext(
            VM\ExceptionSupport::nativeUncaughtThrowable(
                $primaryEntry,
                VM\ExceptionSupport::readThrowableMessage($primaryEntry)
            ),
            VM\ExceptionSupport::nativeUncaughtThrowable(
                $nextEntry,
                VM\ExceptionSupport::readThrowableMessage($nextEntry)
            )
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
        if (!$storage->hasDeclaredTypeConstraint()) {
            return;
        }
        $storage->staticPropertyClassLc = strtolower($entry->name);
        $storage->objectPropertyName = $propDisplayName;
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
        try {
            $child = $func->getFrame($this->context, null);
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
     * Property lvalue for assign-by-ref when rhs is a hooked property (#6426).
     */
    private function resolvePropertyHookRefWriteLvalue(Variable $operand, Frame $frame): ?Variable
    {
        $propName = $this->resolvePropertyWriteName($operand);
        if (null === $propName || $this->isPropertyHookRawWrite($frame, $propName)) {
            return null;
        }
        if (null !== $this->resolvePropertyWriteOwner($operand)) {
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
        try {
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

    /** Reject unset() on readonly properties; returns catch frame or throws when uncaught. */
    private function enforceReadonlyPropertyUnset(ObjectEntry $object, string $propName, Frame $frame): ?Frame
    {
        if (VM\ObjectReadonlySupport::isDynamicReadonly($object)) {
            $thrown = VM\BuiltinExceptionSupport::materializeError(
                $this->context,
                VM\ObjectReadonlySupport::unsetObjectMessage($object)
            );
            $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            $this->raiseUncaughtException($thrown);

            return null;
        }

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

    /** Reject unset() on get-only or write-only virtual hooked instance properties (#6425, #6491). */
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
        if ($hasSet && $hasGet) {
            return null;
        }
        $className = $object->class->name;
        if ('' !== $meta->declaringClassLc && isset($this->context->classes[$meta->declaringClassLc])) {
            $className = $this->context->classes[$meta->declaringClassLc]->name;
        }

        return $this->raiseVirtualPropertyHookUnsetError($className, $propName, $frame);
    }

    /** Reject unset() on typed static properties (Zend zend_object_handlers.c, #6648). */
    private function enforceTypedStaticPropertyUnset(
        string $classLc,
        string $propNameRaw,
        Variable $storage,
        Frame $frame
    ): ?Frame {
        if (!$storage->hasDeclaredTypeConstraint()) {
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

    /** Reject unset() on get-only or write-only virtual hooked static properties (#6425, #6491). */
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
        if ($hasSet && $hasGet) {
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

    /** Reject reads/isset/empty on write-only hooked instance properties (#6484, zend_property_hooks.c). */
    private function enforceWriteOnlyVirtualPropertyRead(ObjectEntry $object, string $propName, Frame $frame): ?Frame
    {
        $meta = $this->classPropertyMeta($object, $propName);
        if (null === $meta || null === $meta->setHookMethodLc || null !== $meta->getHookMethodLc) {
            return null;
        }
        $className = $object->class->name;
        if ('' !== $meta->declaringClassLc && isset($this->context->classes[$meta->declaringClassLc])) {
            $className = $this->context->classes[$meta->declaringClassLc]->name;
        }

        return $this->raiseWriteOnlyVirtualPropertyReadError($className, $propName, $frame);
    }

    /** Reject reads on write-only hooked static properties (#6484). */
    private function enforceWriteOnlyVirtualStaticPropertyRead(string $classLc, string $propName, Frame $frame): ?Frame
    {
        $hooks = $this->resolveStaticPropertyHooks($classLc, strtolower($propName));
        if (null === $hooks || empty($hooks['set']) || !empty($hooks['get'])) {
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
        $thrown = VM\BuiltinExceptionSupport::materializeError(
            $this->context,
            sprintf('Cannot read property %s::$%s without get hook', $className, $propName)
        );
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    /**
     * Inside a property hook, $this->prop reads/writes backing — virtual hooks have none (#10005, zend_object_handlers.c).
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
        $className = $this->resolveHookedPropertyClassName($object, $propName);
        if ($this->instancePropertyIsVirtualHook($object, $propName)) {
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
        $backing = $this->hookedPropertyBackingValue($object, $propName);
        if (false !== $backing && ($backing->isUndefined() || VM\TypedPropertyCheck::isUninitialized($backing))) {
            return $this->raiseVirtualPropertyHookRawAccessError($className, $propName, true, $frame);
        }

        return null;
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
        $catchFrame = $this->enforceVirtualPropertyHookWrite($lvalue, $frame);
        if (null !== $catchFrame) {
            return $catchFrame;
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
        $hasSetHook = false;
        $classLc = $target->staticPropertyClassLc;
        if (is_string($classLc) && isset($this->context->classes[$classLc])) {
            $entry = $this->context->classes[$classLc];
            $hooks = $this->resolveStaticPropertyHooks($classLc, strtolower($propName)) ?? [];
            $virtual = !empty($hooks['virtual']);
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
            $hasSetHook = null !== $meta->setHookMethodLc;
            $className = $owner->class->name;
        }
        if (!$virtual || $hasSetHook) {
            return null;
        }
        if ($this->propertyHasDistinctAsymmetricSetVisibility($classLc, $propName, $lvalue)) {
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
        if (VM\ObjectReadonlySupport::isDynamicReadonly($object)) {
            $thrown = VM\BuiltinExceptionSupport::materializeError(
                $this->context,
                VM\ObjectReadonlySupport::modifyObjectMessage($object)
            );
            $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
            if (null !== $catchFrame) {
                return $catchFrame;
            }
            $this->raiseUncaughtException($thrown);

            return null;
        }

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
        if ($this->shouldDeferReadonlyForPropertySetHook($lvalue, $frame)) {
            return null;
        }
        $target = $lvalue->resolveIndirect();
        $owner = $this->resolvePropertyWriteOwner($lvalue);
        if (null !== $owner && VM\ObjectReadonlySupport::isDynamicReadonly($owner)) {
            $thrown = VM\BuiltinExceptionSupport::materializeError(
                $this->context,
                VM\ObjectReadonlySupport::modifyObjectMessage($owner)
            );
            $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
            if (null !== $catchFrame) {
                if ($this->stashPropertyHookSetExternalCatch($frame, $catchFrame)) {
                    return null;
                }

                return $catchFrame;
            }
            $this->raiseUncaughtException($thrown);

            return null;
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
            $declaringClassLc = $this->readonlyPropertyDeclaringClassLc($owner, $prop);
            $callerClassLc = $this->callerClassLc($frame);
            if (null !== $declaringClassLc && null !== $callerClassLc && $callerClassLc !== $declaringClassLc) {
                $thrown = VM\BuiltinExceptionSupport::materializeError(
                    $this->context,
                    sprintf(
                        'Cannot initialize readonly property %s::$%s from %s',
                        $declaringClass,
                        $prop,
                        $this->propertyWriteScopeLabel($frame)
                    )
                );
                $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
                if (null !== $catchFrame) {
                    if ($this->stashPropertyHookSetExternalCatch($frame, $catchFrame)) {
                        return null;
                    }

                    return $catchFrame;
                }
                $this->raiseUncaughtException($thrown);

                return null;
            }

            return null;
        }
        if (VM\CloneWithSupport::consumeReinit($owner, $prop)) {
            return null;
        }

        $thrown = VM\BuiltinExceptionSupport::materializeError(
            $this->context,
            $this->readonlyPropertyWriteErrorMessage($owner, $prop, $declaringClass, $frame)
        );
        $catchFrame = $this->findCatchFrameForThrow($frame, $thrown);
        if (null !== $catchFrame) {
            if ($this->stashPropertyHookSetExternalCatch($frame, $catchFrame)) {
                return null;
            }

            return $catchFrame;
        }
        $this->raiseUncaughtException($thrown);

        return null;
    }

    /** Zend zend_readonly_property_modification_error — init vs modify wording (#5463). */
    private function readonlyPropertyWriteErrorMessage(
        ObjectEntry $owner,
        string $prop,
        string $declaringClass,
        Frame $frame
    ): string {
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

    private function enforcePropertyReadVisibility(ObjectEntry $object, string $propName, Frame $frame): ?Frame
    {
        $meta = $this->classPropertyMeta($object, $propName);
        if (null === $meta) {
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
        $meta = $this->classPropertyMeta($object, $propName);
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

    /**
     * @return array{
     *     visibility: int,
     *     setVisibility: int,
     *     getVisibility: int,
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
            PropertyVisibility::assertAccessible(
                $meta['visibility'],
                $this->callerClassLc($frame),
                $meta['declaringClassLc'],
                $meta['declaringClassDisplay'],
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
        try {
            PropertyVisibility::assertWritable(
                $setVis,
                $this->callerClassLc($frame),
                $meta['declaringClassLc'],
                $meta['declaringClassDisplay'],
                $propName,
                fn (string $child, string $parent): bool => $this->isSubclassOf($child, $parent)
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
            PropertyVisibility::assertAccessible(
                $meta['visibility'],
                $callerLc,
                $meta['declaringClassLc'],
                $meta['declaringClassDisplay'],
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

    private function callerClassLc(Frame $frame): ?string
    {
        $classLc = null;
        if (null !== $frame->block && null !== $frame->block->func && null !== $frame->block->func->class) {
            $classLc = strtolower($frame->block->func->class->value);
        } elseif (null !== $frame->calledClass && '' !== $frame->calledClass) {
            $classLc = strtolower($frame->calledClass);
        }
        if (null === $classLc) {
            return null;
        }
        $traitLc = $this->traitScopeLcForFrameMethod($frame, $classLc);

        return $traitLc ?? $classLc;
    }

    /** Trait-sourced methods use trait scope for private member access (#4834, zend_compile.c). */
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
        $traitName = $this->context->classes[$classLc]->traitMethodSources[$methodLc] ?? null;
        if (null === $traitName) {
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
     * Hook-block `private(set);` is not get-only read-only — defer to asymmetric write guard (#9872).
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
     */
    private function warnForeachNonTraversable(Variable $container, Frame $frame): void
    {
        $resolved = $container->resolveIndirect();
        $this->context->errors->triggerErrorWithHandlerFirst(
            'foreach() argument must be of type array|object, '
            .TypeCheck::typeNameForConstraint($resolved->type).' given',
            ErrorReporter::E_WARNING,
            '' !== $frame->scriptPath ? $frame->scriptPath : null,
            $this->context,
            $frame
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
        $this->stashPendingException($thrown);
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
            if (null !== $gen->closureCall) {
                $this->applyClosureBinding($gen->frame, $gen->closureCall);
            }
        }
        $gen->started = true;
        $savedStack = $this->context->swapRunStack(null);
        try {
            $this->context->push($gen->frame);
            try {
                $result = $this->runFrames();
            } catch (\TypeError|\Error $e) {
                $thrown = VM\BuiltinExceptionSupport::materializeNativeError($this->context, $e);
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
            $userArgs = $this->resolveUserCallArgs(
                $frame,
                $paramNames,
                $variadicIndex,
                $this->internalBuiltinFunctionName($frame->call)
            );
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

            $args = array_merge($frame->callArgs, [$nameVar, $argsVar]);
            $this->separateInternalByRefArgsForWrite($frame->call, $args);

            return $args;
        }

        [$paramNames, $variadicIndex] = $this->calleeParamMetadata($frame->call);

        $userArgs = $this->resolveUserCallArgs(
            $frame,
            $paramNames,
            $variadicIndex,
            $this->internalBuiltinFunctionName($frame->call)
        );
        if ([] === $frame->callArgs) {
            $this->separateInternalByRefArgsForWrite($frame->call, $userArgs);

            return $userArgs;
        }

        $args = array_merge($frame->callArgs, $userArgs);
        $this->separateInternalByRefArgsForWrite($frame->call, $args);

        return $args;
    }

    /**
     * COW-separate array zvals passed by reference to internal builtins (Zend zval separation, #6689).
     *
     * @param list<Variable> $calledArgs
     */
    private function separateInternalByRefArgsForWrite(Func $call, array $calledArgs): void
    {
        if (!$call instanceof Func\Internal) {
            return;
        }
        $name = $call->getName();
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
            if (isset($calledArgs[$i])) {
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
            // Named locals must stay tied to scope for by-ref outgoing calls (#9505, #9700).
            if (null !== $frame->block && $frame->block->isNamedVariableSlot($slot)) {
                return $frame->scope[$slot];
            }
            $resolved = $frame->scope[$slot]->resolveIndirect();
            if (null !== $const && $this->isImmortalEnumCaseBlockConstant($const)) {
                if (VM\EnumCaseSupport::isEnumCaseVariable($resolved)) {
                    return $frame->scope[$slot];
                }
                if ($resolved->isUndefined() || $this->isEnumSlotClobberCandidate($resolved)) {
                    $value = new Variable();
                    $value->copyFrom($const);

                    return $value;
                }

                return $frame->scope[$slot];
            }
            if (Variable::TYPE_NULL !== $resolved->type && !$resolved->isUndefined()) {
                if (null === $const || $resolved->type === $const->type) {
                    return $frame->scope[$slot];
                }
                // Array dim fetch / spread temps hold live objects; do not substitute NULL block constants (#8814).
                if (!$this->isEnumSlotClobberCandidate($resolved)) {
                    return $frame->scope[$slot];
                }
            }
        }
        if (null !== $const) {
            $value = new Variable();
            $value->copyFrom($const);

            return $value;
        }

        return $frame->scope[$slot];
    }

    /**
     * Whether an outgoing call argument binds by reference (Zend ZEND_SEND_REF).
     */
    private function outgoingCallArgNeedsReference(Frame $frame, int $argIndex): bool
    {
        if (null === $frame->call) {
            return false;
        }
        if ($frame->call instanceof Func\Internal) {
            $name = $frame->call->getName();
            if (\in_array($argIndex, BuiltinByRefParams::forFunction($name), true)) {
                return true;
            }
            $variadicFrom = BuiltinByRefParams::variadicByRefFromIndex($name);

            return null !== $variadicFrom && $argIndex >= $variadicFrom;
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
            ) {
                $thisArgOffset = 1;
            }
            $paramIdx = $argIndex - $thisArgOffset;

            return isset($block->paramByRef[$paramIdx]);
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
    private function resolveUserCallArgs(Frame $frame, array $paramNames, ?int $variadicIndex, ?string $functionName = null): array
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

        return NamedArgs::resolve($entries, $paramNames, $variadicIndex, $functionName);
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

    private function internalBuiltinFunctionName(Func $call): ?string
    {
        return $call instanceof Func\Internal ? $call->getName() : null;
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
        return null !== $frame->closureCall;
    }

    protected function ensureFunctionStaticForFrame(Frame $frame, string $storageKey): Variable
    {
        if ($this->frameUsesClosureStaticStorage($frame)) {
            return $frame->closureCall->ensureStatic($storageKey);
        }

        return $this->context->ensureFunctionStatic($storageKey);
    }

    protected function isFunctionStaticInitializedForFrame(Frame $frame, string $storageKey): bool
    {
        if ($this->frameUsesClosureStaticStorage($frame)) {
            return $frame->closureCall->isStaticInitialized($storageKey);
        }

        return $this->context->isFunctionStaticInitialized($storageKey);
    }

    protected function markFunctionStaticInitializedForFrame(Frame $frame, string $storageKey): void
    {
        if ($this->frameUsesClosureStaticStorage($frame)) {
            $frame->closureCall->markStaticInitialized($storageKey);

            return;
        }
        $this->context->markFunctionStaticInitialized($storageKey);
    }

    protected function applyFunctionStaticTypeMetadata(Variable $storage, Frame $frame, OpCode $op): void
    {
        if (null === $op->functionStaticTypeSlot || !isset($frame->block->constants[$op->functionStaticTypeSlot])) {
            return;
        }
        $proto = $frame->block->constants[$op->functionStaticTypeSlot];
        $resolved = $storage->resolveIndirect();
        $resolved->typeConstraint = $proto->typeConstraint;
        $resolved->classConstraint = $proto->classConstraint;
        $resolved->literalBoolType = $proto->literalBoolType;
        $resolved->unionTypeConstraints = $proto->unionTypeConstraints;
        $resolved->declaredTypeLabel = $proto->declaredTypeLabel;
        $resolved->genericArrayTypeSpec = $proto->genericArrayTypeSpec;
        $resolved->dnfArms = $proto->dnfArms;
        if (null !== $op->functionStaticVarName && '' !== $op->functionStaticVarName) {
            $resolved->functionStaticVarName = $op->functionStaticVarName;
        }
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
        $frame->pendingClosureInvoke = $state;
        $frame->callArgs = [];
        $frame->callArgEntries = [];
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
        if (null !== $closureState->boundScopeClass && '' !== $closureState->boundScopeClass) {
            $callee->calledClass = $closureState->boundScopeClass;
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
                PseudoClassScope::fatalInGlobalScope('parent');
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
        if (null !== $frame->block->func && null !== $frame->block->func->class) {
            return strtolower($frame->block->func->class->value);
        }
        // Bound closure scope (Closure::bind/bindTo $newScope) — #3673.
        if (null !== $frame->calledClass && '' !== $frame->calledClass) {
            return strtolower($frame->calledClass);
        }

        if ('static' === $scopeKeyword) {
            PseudoClassScope::fatalNoActiveClassScope('static');
        }
        PseudoClassScope::fatalInGlobalScope($scopeKeyword);
    }

    protected function lateStaticClassLc(Frame $frame): string
    {
        if (null !== $frame->calledClass && '' !== $frame->calledClass) {
            return strtolower($frame->calledClass);
        }

        return $this->declaringClassLc($frame, 'static');
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
        if ($object->lazyPending && 'marklazyobjectasinitialized' !== $methodLc) {
            VM\LazyObjectSupport::ensureInitialized($this, $object);
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
            if (isset($class->methods['__call'])) {
                $frame->magicCallMethodName = $methodName;
                $frame->call = $class->methods['__call'];
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
            [$class, $methodLc] = $this->resolveStaticMethod($lcClass, $methodLc);
            $parentScopeInstanceCall = ($parentKeywordScope
                && null !== $frame->block->func
                && null !== $frame->block->func->class
                && !(($frame->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC))
                || $this->isDirectParentScopeInstanceCall($frame, $lcClass);
            if (!$parentScopeInstanceCall) {
                $this->assertMethodCallableStatically($class, $methodLc);
            }
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
        if (null === $frame->block->func || null === $frame->block->func->class) {
            return null;
        }
        if (($frame->block->func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) {
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
        array $ownMethods
    ): void {
        $this->context->deferredTraitUses[] = [
            'entry' => $entry,
            'traitNames' => $traitNames,
            'adaptations' => $adaptations,
            'ownMethods' => $ownMethods,
        ];
    }

    protected function flushDeferredTraitUses(): void
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
                $deferred['ownMethods']
            );
        }
        $this->context->deferredTraitUses = $remaining;
    }

    protected function flushDeferredParentInheritance(): void
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
            VM\ClassValidator::finalizeClassDefinition($entry, $this->context);
        }
        $this->context->deferredParentInheritance = $remaining;
    }

    protected function finalizeDeferredParentInheritance(): void
    {
        $this->flushDeferredParentInheritance();
        if ([] === $this->context->deferredParentInheritance) {
            return;
        }
        $deferred = $this->context->deferredParentInheritance[0];
        $childName = $this->context->classes[$deferred['childLc']]->name ?? $deferred['childLc'];
        throw new \LogicException(
            "Class {$childName} extends unknown class {$deferred['parentName']}"
        );
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
            $this->finalizeAllDeferredClassConstants();
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
        array $ownMethods = []
    ): void {
        if ([] === $traitNames) {
            return;
        }

        if (!$this->canResolveAllTraitEntries($traitNames)) {
            $this->queueDeferredTraitUse($entry, $traitNames, $adaptations, $ownMethods);

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
                    $prevTrait = $usedTraitNameByLc[$declaringLc]
                        ?? $this->context->classes[$declaringLc]->name
                        ?? $declaringLc;
                    throw new \LogicException(TraitCompositionConflictMessage::incompatibleProperty(
                        $prevTrait,
                        $trait->name,
                        $name,
                        $entry->name
                    ));
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
                $entry->staticPropertyDeclaringClassLc[$name] = $trait->staticPropertyDeclaringClassLc[$name]
                    ?? $traitLc;
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

            if (null !== $newModifier) {
                $data['vis'] = (int) $newModifier;
            }
            $data['methodNames'] = (string) $newName;
            // Trait-qualified `TB::f as g` adds an alias without renaming the merged winner `f`.
            if (null === $traitLcFilter) {
                unset($merged[$methodLc]);
            }
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
                $methodLc
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

    protected function inheritTraitInstanceProperties(ClassEntry $entry, ClassEntry $trait, string $traitName): void
    {
        $traitLc = strtolower(ltrim($traitName, '\\'));
        foreach ($trait->properties as $property) {
            $propLc = strtolower($property->name);
            foreach ($entry->properties as $existing) {
                if (strtolower($existing->name) === $propLc) {
                    if ($existing->declaringClassLc === $traitLc) {
                        continue 2;
                    }
                    $prevTraitLc = $existing->declaringClassLc;
                    $prevTrait = isset($this->context->classes[$prevTraitLc])
                        ? $this->context->classes[$prevTraitLc]->name
                        : $prevTraitLc;
                    throw new \LogicException(TraitCompositionConflictMessage::incompatibleProperty(
                        $prevTrait,
                        $traitName,
                        $property->name,
                        $entry->name
                    ));
                }
            }
            $entry->properties[] = $this->cloneClassPropertyForEntry($property, $entry);
            if (isset($trait->propertyAttributeNames[$propLc])) {
                $entry->propertyAttributeNames[$propLc] = $trait->propertyAttributeNames[$propLc];
            }
            if (isset($trait->propertyAttributeEntries[$propLc])) {
                $entry->propertyAttributeEntries[$propLc] = $trait->propertyAttributeEntries[$propLc];
            }
            if (isset($trait->propDeprecated[$propLc])) {
                $entry->propDeprecated[$propLc] = $trait->propDeprecated[$propLc];
            }
        }
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
            $property->getVisibility
        );
        $cloned->getHookMethodLc = $property->getHookMethodLc;
        $cloned->setHookMethodLc = $property->setHookMethodLc;
        $cloned->unsetHookMethodLc = $property->unsetHookMethodLc;
        $cloned->propertyHookVirtual = $property->propertyHookVirtual;
        $cloned->fromConstructorPromotion = $property->fromConstructorPromotion;
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
            $this->inheritInterfacePropertyRules($entry, $iface);
            $this->inheritInterfacePropertyHooks($entry, $iface);
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
                return $parent->constants[$memberLc];
            }
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
                if (isset($parent->methodDeclaringClassLc[$name])) {
                    $entry->methodDeclaringClassLc[$name] = $parent->methodDeclaringClassLc[$name];
                }
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
                if (isset($parent->staticPropertyVisibility[$name])) {
                    $entry->staticPropertyVisibility[$name] = $parent->staticPropertyVisibility[$name];
                }
                if (isset($parent->staticPropertySetVisibility[$name])) {
                    $entry->staticPropertySetVisibility[$name] = $parent->staticPropertySetVisibility[$name];
                }
                if (isset($parent->staticPropertyGetVisibility[$name])) {
                    $entry->staticPropertyGetVisibility[$name] = $parent->staticPropertyGetVisibility[$name];
                }
                if (isset($parent->staticPropertyDeclaringClassLc[$name])) {
                    $entry->staticPropertyDeclaringClassLc[$name] = $parent->staticPropertyDeclaringClassLc[$name];
                }
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
            if (!isset($entry->constants[$name])) {
                $entry->constants[$name] = $value;
                if (isset($parent->constVisibility[$name])) {
                    $entry->constVisibility[$name] = $parent->constVisibility[$name];
                }
                if (isset($parent->constDeprecated[$name])) {
                    $entry->constDeprecated[$name] = $parent->constDeprecated[$name];
                }
                if (isset($parent->constFinal[$name])) {
                    $entry->constFinal[$name] = true;
                }
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
        if (Variable::TYPE_OBJECT !== $receiver->type
            && Variable::TYPE_ENUM_CASE !== $receiver->type) {
            throw new \LogicException('Invalid array callable');
        }
        if (Variable::TYPE_ENUM_CASE === $receiver->type) {
            $receiver = VM\EnumCaseSupport::receiverForInstanceMethod($receiver);
        }
        $this->initMethodCall($frame, $receiver, $methodName);
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
                $this->flushPendingTraitUses($entry, $pendingTraits, $ownMethods);
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
                    $this->applyTraitUsesWithAdaptations($entry, $pendingTraits, $op->traitAdaptations, $ownMethods);
                    $pendingTraits = [];
                    break;
                case OpCode::TYPE_DECLARE_PROPERTY:
                    $this->flushPendingTraitUses($entry, $pendingTraits, $ownMethods);
                    $pendingTraits = [];
                    $name = $frame->scope[$op->arg1];
                    $default = $this->resolveCompileTimePropertyDefaultSlot($frame, $block, $op->arg2);
                    $propLc = strtolower($name->toString());
                    $prop = new VM\ClassProperty(
                        $name->toString(),
                        $default,
                        $frame->scope[$op->arg3],
                        $op->propertyReadonly,
                        MethodVisibility::mask($op->propertyVisibility),
                        strtolower($entry->name),
                        (int) ($op->propertySetVisibility ?? 0),
                        (int) ($op->propertyGetVisibility ?? 0)
                    );
                    $prop->fromConstructorPromotion = $op->propertyFromConstructorPromotion;
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
                    break;
                case OpCode::TYPE_DECLARE_STATIC_PROPERTY:
                    $this->flushPendingTraitUses($entry, $pendingTraits, $ownMethods);
                    $pendingTraits = [];
                    $name = strtolower($frame->scope[$op->arg1]->toString());
                    $storage = clone $frame->scope[$op->arg3];
                    $default = $this->resolveCompileTimePropertyDefaultSlot($frame, $block, $op->arg2);
                    if (null !== $default) {
                        $storage->copyFrom($default);
                    }
                    $this->linkStaticTypedPropertySlot(
                        $storage,
                        $entry,
                        $frame->scope[$op->arg1]->toString()
                    );
                    $entry->staticProperties[$name] = $storage;
                    $entry->staticPropertyVisibility[$name] = MethodVisibility::mask($op->propertyVisibility);
                    $entry->staticPropertySetVisibility[$name] = (int) ($op->propertySetVisibility ?? 0);
                    $entry->staticPropertyGetVisibility[$name] = (int) ($op->propertyGetVisibility ?? 0);
                    $entry->staticPropertyDeclaringClassLc[$name] = strtolower($entry->name);
                    if (null !== $op->deprecatedMetadata) {
                        $entry->propDeprecated[$name] = $op->deprecatedMetadata;
                    }
                    break;
                case OpCode::TYPE_DECLARE_METHOD:
                    $this->flushPendingTraitUses($entry, $pendingTraits, $ownMethods);
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
                    if (null !== $op->sourceLocation) {
                        $entry->methodSourceLocations[$name] = $op->sourceLocation;
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
                    $this->applyClassConstDeclaration($entry, $block, $frame, $op);
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
        $name = strtolower($canonical);
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
            TypeCheck::assertClassConstantTypedValue($check, $block->constants[$op->arg3], $name);
            $value->copyFrom($check);
        }
        $this->rejectIncompatibleTraitClassConstOverride($entry, $name, $canonical, $value);
        $entry->constants[$name] = $value;
        $entry->constNames[$name] = $canonical;
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
                $name = strtolower($frame->scope[$op->arg1]->toString());
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
            $storage = clone $frame->scope[$declareOp->arg3];
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
            $entry->staticPropertyDeclaringClassLc[$name] = strtolower($entry->name);

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
    public function instantiateFromNewCallable(ClassEntry $class, Frame $frame, Variable ...$ctorArgs): ObjectEntry
    {
        if ($class->isEnum) {
            throw new \Error("Cannot instantiate enum {$class->name}");
        }
        if ($class->isAbstract) {
            throw new \Error("Cannot instantiate abstract class {$class->name}");
        }
        if ($class->isInterface) {
            throw new \Error("Cannot instantiate interface {$class->name}");
        }
        VM\ClassValidator::assertInstantiable($class);
        if (null !== $class->constructor || $this->hasInstanceMethod($class, '__construct')) {
            try {
                [$declaringClass, $methodLc] = $this->resolveInstanceMethod($class, '__construct');
                $vis = $declaringClass->methodVisibility[$methodLc] ?? \PHPCfg\Func::FLAG_PUBLIC;
                $callerClassLc = $this->callerClassLc($frame);
                $callerDisplay = null;
                if (null !== $callerClassLc && isset($this->context->classes[$callerClassLc])) {
                    $callerDisplay = $this->context->classes[$callerClassLc]->name;
                }
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
                if ($class->isEnum) {
                    throw new \Error("Cannot instantiate enum {$class->name}");
                }
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
                    $ht->append($this->resolveOutgoingCallArgValue($frame, $op->arg2));

                    break;
                }
                $key = $this->resolveOutgoingCallArgValue($frame, $op->arg3)->resolveIndirect();
                $value = $this->resolveOutgoingCallArgValue($frame, $op->arg2);
                if ($key->is(Variable::TYPE_OBJECT) || $key->is(Variable::TYPE_ARRAY)) {
                    throw new \TypeError('Illegal offset type');
                }
                VM\EnumCaseSupport::rejectIllegalArrayOffset($key);
                $storeIndirect = $value->isIndirect();
                if ($key->is(Variable::TYPE_INTEGER) || $key->is(Variable::TYPE_FLOAT)) {
                    $storeIndirect
                        ? $ht->updateIndirectIndex($key->toInt(), $value)
                        : $ht->updateIndex($key->toInt(), $value);
                } elseif ($key->is(Variable::TYPE_STRING)) {
                    $storeIndirect
                        ? $ht->updateIndirect($key->toString(), $value)
                        : $ht->update($key->toString(), $value);
                } elseif ($key->is(Variable::TYPE_BOOLEAN)) {
                    $index = $key->toBool() ? 1 : 0;
                    $storeIndirect
                        ? $ht->updateIndirectIndex($index, $value)
                        : $ht->updateIndex($index, $value);
                } elseif ($key->is(Variable::TYPE_NULL)) {
                    $storeIndirect
                        ? $ht->updateIndirect('', $value)
                        : $ht->update('', $value);
                } else {
                    throw new \TypeError('Illegal offset type');
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
            $resolved = $frame->scope[$slot]->resolveIndirect();
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
        if (null !== $block->returnClassConstraint && null !== $value) {
            $returnLabel = ltrim($block->returnDeclaredTypeLabel ?? $block->returnClassConstraint, '\\');
            if (!($block->isGenerator && 'Generator' === $returnLabel)) {
                TypeCheck::assertObjectReturn(
                    $value,
                    $block->returnClassConstraint,
                    $block->returnDeclaredTypeLabel ?? $block->returnClassConstraint,
                    $this->returnTypeCallableName($block->func)
                );
            }

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

    private function returnTypeCallableName(?\PHPCfg\Func $func): ?string
    {
        if (null === $func) {
            return null;
        }
        if (null !== $func->class && '' !== $func->class) {
            return $func->class.'::'.$func->name;
        }

        return $func->name;
    }

    private function emitCallDeprecationNotice(Frame $frame): void
    {
        if (null === $frame->call || !($frame->call instanceof Func\PHP)) {
            return;
        }
        $meta = $frame->call->deprecated;
        if (null === $meta || !$meta->emitsRuntimeNotice()) {
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
                $this->emitDeprecatedNotice(
                    $meta->formatConstant($classEntry->name, $memberNameRaw),
                    $frame
                );
            }
        }
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
        $meta = $declEntry->propDeprecated[$propLc];
        if (!$meta->emitsRuntimeNotice()) {
            return;
        }
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
        if (!$depMeta->emitsRuntimeNotice()) {
            return;
        }
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
        if (isset($classEntry->constants[$memberLc])) {
            $this->emitClassConstFetchDeprecation($classEntry, $memberNameRaw, $memberLc, $frame);
            if ($classEntry->isEnum && null !== $classEntry->backedType) {
                VM\EnumSupport::ensureBackedEnumValuesUnique($classEntry);
            }
            if (EnumCaseSupport::fetchCaseByMemberName($classEntry, $memberLc, $dest, $this->context)) {
                return true;
            }
            $dest->copyFrom(
                EnumCaseSupport::materializeConstantValue($this->context, $classEntry->constants[$memberLc])
            );

            return true;
        }
        $inheritedConst = $this->resolveInheritedClassConstant($classEntry, $memberLc);
        if (null !== $inheritedConst) {
            $this->emitClassConstFetchDeprecation($classEntry, $memberNameRaw, $memberLc, $frame);
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
            foreach ([$next->arg1, $next->arg2, $next->arg3] as $arg) {
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
    private function releaseVmStatementDeadTemps(Frame $frame, int ...$keepSlots): void
    {
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
            $this->releaseVmDeadScopeSlot($frame, $slot);
        }
    }

    private function releaseVmDeadScopeSlot(Frame $frame, int $slot): void
    {
        if (!isset($frame->scope[$slot]) || $frame->block->isNamedVariableSlot($slot)) {
            return;
        }
        ObjectLifetime::releaseDirectObject($frame->scope[$slot]);
        $frame->scope[$slot]->null();
    }

}
