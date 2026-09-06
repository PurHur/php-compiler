<?php

/*
 * This file is part of PHP-Compiler, a PHP CFG Compiler for PHP code
 *
 * @copyright 2015 Anthony Ferrara. All rights reserved
 * @license MIT See LICENSE at the root of the project for more info
 */

namespace PHPCompiler;

require_once __DIR__.'/OpCodeNames.php';
require_once __DIR__.'/VM/OutgoingCallTempRelease.php';
require_once __DIR__.'/VM/Concern/ObjectPropertyIssetEmptyUnset.php';
require_once __DIR__.'/VM/Concern/ObjectPropertyCollectAndSerialize.php';
require_once __DIR__.'/VM/Concern/ObjectPropertyHooks.php';
require_once __DIR__.'/VM/Concern/ObjectPropertyReadonlyAndVisibility.php';
require_once __DIR__.'/VM/Concern/ClassTraitComposition.php';
require_once __DIR__.'/VM/Concern/ClassInheritDefineAndConstDeclare.php';
require_once __DIR__.'/VM/Concern/ObjectPropertyMagicAndClone.php';
require_once __DIR__.'/VM/Concern/PropertyFetchDestAndHookedDimWrite.php';
require_once __DIR__.'/VM/Concern/TypedIntRecursiveAndCountedLoopFastPath.php';
require_once __DIR__.'/VM/Concern/ExecuteIncDecAndScopeOperandRead.php';
require_once __DIR__.'/VM/Concern/TryCatchFinallyAndUncaughtDispatch.php';
require_once __DIR__.'/VM/Concern/BuiltinHostExceptionDispatch.php';
require_once __DIR__.'/VM/Concern/UserInvokeArrayAccessAndClosureCall.php';

use PHPCompiler\BuiltinByRefParams;
use PHPCompiler\Compiler\AttributeNames;
use PHPCompiler\Compiler\NoDiscardMetadata;
use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\Func;
use PHPCompiler\ext\standard\VmEval;
use PHPCompiler\ext\standard\VmForwardStaticCall;
use PHPCompiler\ext\standard\VmIteratorWalk;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\VM\ForeachIterator;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\CastSupport;
use PHPCompiler\VM\ClassEntry;
use PHPCompiler\VM\DnfCheck;
use PHPCompiler\VM\ClosureState;
use PHPCompiler\VM\ClosureRichDisplayName;
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

    use OutgoingCallTempRelease;
    use ObjectPropertyIssetEmptyUnset;
    use ObjectPropertyCollectAndSerialize;
    use ObjectPropertyHooks;
    use ObjectPropertyReadonlyAndVisibility;
    use ClassTraitComposition;
    use ClassInheritDefineAndConstDeclare;
    use ObjectPropertyMagicAndClone;
    use PropertyFetchDestAndHookedDimWrite;
    use TypedIntRecursiveAndCountedLoopFastPath;
    use ExecuteIncDecAndScopeOperandRead;
    use TryCatchFinallyAndUncaughtDispatch;
    use BuiltinHostExceptionDispatch;
    use UserInvokeArrayAccessAndClosureCall;
    const SUCCESS = 1;
    const FAILURE = 2;

    private static ?self $running = null;

    /** Frame executing the current opcode (property hook ref read/write, #6426). */
    private ?Frame $executingFrame = null;

    /** Active builtin handler while {@see executeInternalHandler} bridges a throw (#11677). */
    private ?Frame $builtinHandlerFrameForTrace = null;

    /** Reused ++/-- scratch slots — avoid per-iteration Variable alloc in hot loops (#15906, #36148). */
    private ?Variable $incDecScratchWorking = null;

    private ?Variable $incDecScratchBefore = null;

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
        // ZEND_INCLUDE_OR_EVAL copies EX(This) into the eval unit (#31902).
        $this->inheritIncludeThis($child, $caller);
        // Scope comes from getFrame($caller); parent must stay null so nested runFrames exits.
        $child->parent = null;
        $child->returnVar = $out;
        // Zend __FILE__/__DIR__: enclosing script path + call site (#25809, zend_eval_string).
        [$evalFile] = VM\ExceptionSupport::evalFatalSite($caller, 1);
        $child->scriptPath = $evalFile;
        // zend_eval_string copies called_scope AND func->scope (self ≠ static on subclass) (#31912).
        $this->inheritEvalClassScope($child, $caller);
        // ZEND_INCLUDE_OR_EVAL also copies EX(This); isolation must not drop object context (#4410).
        $this->inheritIncludeThis($child, $caller);
        $this->context->scriptStack->push($child->scriptPath);
        $prevDeferDepth = $this->context->deferCatchBelowTryHandlerDepth;
        $this->context->deferCatchBelowTryHandlerDepth = \count($this->context->activeTryHandlerFrames);
        // Isolate nested runFrames so eval completion does not continue the caller (#31912).
        // Isolated stack — nested eval return must not pop the outer method/script
        // frame that is executing TYPE_EVAL (#31902; same shape as coercion invoke).
        $savedStack = $this->context->swapRunStack(null);
        try {
            $this->context->push($child);
            $result = $this->runFrames();
            if (self::SUCCESS !== $result) {
                throw new \LogicException('eval() execution failed in this compiler build');
            }
        } catch (VM\BuiltinCallbackCatchRedirect $redirect) {
            $this->context->swapRunStack($savedStack);
            $savedStack = null;
            throw $redirect;
        } finally {
            if (null !== $savedStack) {
                $this->context->swapRunStack($savedStack);
            }
            $this->context->deferCatchBelowTryHandlerDepth = $prevDeferDepth;
            // Ephemeral eval finish already pops this path; pop only if still on top.
            if ($this->context->scriptStack->current() === $evalFile
                || $this->context->scriptStack->current() === VM\ScriptStack::normalize($evalFile)
            ) {
                $this->context->scriptStack->pop();
            }
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
        if (!VM\SplArraySupport::isArrayObjectClass($entry->class->name)) {
            return null;
        }
        if (!VM\SplArraySupport::hasState($entry)) {
            return null;
        }
        $table = VM\SplArraySupport::getArrayCopy($entry);
        if (null === $table) {
            return null;
        }
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
     *
     * When an opcode is executing, stamp user file/line like zend_throw_exception
     * so caught Errors (typed-property reads, by-ref fetch, …) match Zend getFile()/getLine()
     * (#31859, zend_exceptions.c / zend_object_handlers.c).
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
        // php-src zend_throw_exception / zend_exception_get_props: default code is 0.
        $obj->getProperty(VM\ExceptionSupport::PROP_CODE)->int(0);
        if (null !== $this->executingFrame) {
            [$file, $line] = VM\ExceptionSupport::userFatalSite($this->executingFrame);
            VM\ExceptionSupport::stampThrowableSite($obj, $file, $line);
        }
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

        $this->executingFrame = $frame;
        $limits = $this->context->executionLimits;
        $timerDisabled = $limits->isTimerDisabled();
        // Cache deferred-definitions state: the three arrays are only populated by
        // declaration opcodes (DECLARE_CLASS etc.), so a block containing none will
        // never need the flush. Checking a bool per-op is ~20× cheaper than calling
        // assertDeferredDefinitionsBeforeRuntime() which does three empty-array
        // comparisons plus a method dispatch (#36411 / #36449).
        $hasDeferredDefs = [] !== $this->context->deferredTraitUses
            || [] !== $this->context->deferredClassConstants
            || [] !== $this->context->deferredParentInheritance;

        while ($frame->pos < $frame->block->nOpCodes) {
            if (!$timerDisabled) {
                $limits->check($this->context, $frame);
            }
            $op = $frame->block->opCodes[$frame->pos++];
            if ($hasDeferredDefs) {
                try {
                    $this->assertDeferredDefinitionsBeforeRuntime($op->type);
                } catch (\Error $deferredParentError) {
                    $catchFrame = $this->dispatchVmError($deferredParentError->getMessage(), $frame);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    break;
                }
                // Re-check after flush: if all resolved, skip on subsequent ops.
                $hasDeferredDefs = [] !== $this->context->deferredTraitUses
                    || [] !== $this->context->deferredClassConstants
                    || [] !== $this->context->deferredParentInheritance;
            } elseif (
                OpCode::TYPE_DECLARE_CLASS === $op->type
                || OpCode::TYPE_DECLARE_ENUM === $op->type
                || OpCode::TYPE_DECLARE_TRAIT === $op->type
                || OpCode::TYPE_DECLARE_INTERFACE === $op->type
                || OpCode::TYPE_FUNCDEF === $op->type
                || OpCode::TYPE_DECLARE_GLOBAL_CONST === $op->type
            ) {
                // Next non-declaration op in this block must flush (#25627).
                $hasDeferredDefs = true;
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
                    // Stale FETCH_DIM (read) indirect after temp-slot reuse: writing `$cond = $bool`
                    // must not punch through into `$Block['data']['type']` (#36380 Parsedown lists).
                    // Keep write-through for FETCH_DIM_W lvalues, property lvalues, and explicit
                    // PHP references (`$r =& …` / foreach-by-ref — {@see Variable::$phpReference}).
                    if (
                        $arg2->isIndirect()
                        && !$arg2->phpReference
                        && !$arg2->propertyAssignLvalue
                        && !$this->assignDestKeptAsWriteThrough($frame, (int) $op->arg2)
                    ) {
                        $arg2->reset();
                    }
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
                        VM\SplArraySupport::offsetSet($writeTarget->arrayAsPropsTarget, $key, $arg3);
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
                    VM\DomVmRuntimeSupport::retainUserHandleFromVariable($arg2);
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
                    // `$r = &$obj->uninitTyped` — get_property_ptr_ptr Error / nullable ZVAL_NULL (#31771).
                    VM\TypedPropertyCheck::prepareWritableByReference($rhsSlot);
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
                    // Zend zend_assign_to_variable_reference: non-variable RHS → Notice + value assign (#30015).
                    if (!VM\ReferencableCheck::isReferenceable($rhsSlot, $frame)) {
                        VM\ReferencableCheck::emitNonVariableAssignRefNotice($frame);
                        $catchFrame = $this->assignCopyFrom($lhs, $rhsSlot, $frame);
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
                    // Without `&get`, read_property(BP_VAR_W) still invokes get for side effects,
                    // then Errors unless the get result is an object (#29719).
                    $hookRefLvalue = $this->resolvePropertyHookRefWriteLvalue($rhsSlot, $frame);
                    if (null !== $hookRefLvalue) {
                        if (!$this->propertyHookGetIsByRef($hookRefLvalue)) {
                            $catchFrame = $this->assignRefFromHookedPropertyWithoutByRefGet(
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
                        $writeTarget->indirectAsPhpReference($rhs);
                        $this->markTypedPropertyByRefAlias($writeTarget, $rhs);
                        if ($writeTarget !== $lhs) {
                            $lhs->indirectAsPhpReference($writeTarget->resolveIndirect());
                        } else {
                            $lhs->phpReference = true;
                        }
                        break;
                    }
                    if (
                        null !== $rhs->staticPropertyClassLc
                        && null !== $rhs->objectPropertyName
                    ) {
                        $writeTarget->indirectAsPhpReference($rhs);
                        $this->markTypedPropertyByRefAlias($writeTarget, $rhs);
                        if ($writeTarget !== $lhs) {
                            $lhs->indirectAsPhpReference($writeTarget->resolveIndirect());
                        } else {
                            $lhs->phpReference = true;
                        }
                        break;
                    }
                    if (
                        $rhsSlot->isIndirect()
                        && !$this->context->isGlobalStorage($rhs)
                        && !$rhs->hashTableBucketCell
                    ) {
                        $writeTarget->indirectAsPhpReference($rhs);
                        $this->markTypedPropertyByRefAlias($writeTarget, $rhs);
                        if ($writeTarget !== $lhs) {
                            $lhs->indirectAsPhpReference($writeTarget->resolveIndirect());
                        } else {
                            $lhs->phpReference = true;
                        }
                        break;
                    }
                    if (Variable::TYPE_INDIRECT !== $rhs->type) {
                        $ref = new Variable();
                        $ref->copyFrom($rhs);
                        $rhs->indirect($ref);
                    }
                    $writeTarget->indirectAsPhpReference($rhs->resolveIndirect());
                    $this->markTypedPropertyByRefAlias($writeTarget, $rhs->resolveIndirect());
                    // Named CV may have held a stale dim-read indirect (slot reuse); ensure the
                    // CV slot itself is the phpReference wrapper (#36380).
                    if ($writeTarget !== $lhs) {
                        $lhs->indirectAsPhpReference($writeTarget->resolveIndirect());
                    } else {
                        $lhs->phpReference = true;
                    }
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
                        if (null !== $appendCell) {
                            $this->markPersistentHashTableBucketIfNeeded($containerSlot, $appendCell);
                        }
                        $this->tagHookedPropertyDimWriteLvalue($arg1, $containerSlot);
                        break;
                    }
                    // Literal dim keys live in block->constants; scope[slot] may be a CV that
                    // aliased the same integer and was later assigned an array (#36380 /
                    // Parsedown `$this->DefinitionData['Reference'][$id] = $Data`).
                    $arg3 = $this->readDimKeyOperand($frame, (int) $op->arg3);
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
                            // ++/-- / += FETCH_DIM_W: warn on missing key then treat as null (#30078, #31991).
                            $forRwOp = $forWrite && (
                                $this->propertyFetchDestUsedAsIncDec($frame, $op)
                                || $this->propertyFetchDestUsedAsCompoundAssign($frame, $op)
                                || $this->propertyFetchDestUsedAsDimRwContainer($frame, $op)
                            );
                            if (
                                (!$forWrite && !$fetchIs || $forRwOp)
                                && !$table->keyExists($arg3, false, $frame, false)
                            ) {
                                $this->context->errors->undefinedArrayKey(
                                    $arg3,
                                    $this->context,
                                    $frame,
                                    '' !== $frame->scriptPath ? $frame->scriptPath : null
                                );
                            }
                            // Coalesce left read: isset already emitted float→int DEP (#29664).
                            $emitFloatKeyDep = !$op->arrayDimFetchSkipFloatKeyDeprecation;
                            $dimCell = $table->findVariable(
                                $arg3,
                                $forWrite,
                                $this->context,
                                $frame,
                                $emitFloatKeyDep
                            );
                            $arg1->indirect($dimCell);
                            if ($forWrite && null !== $dimCell) {
                                $this->markPersistentHashTableBucketIfNeeded($containerSlot, $dimCell);
                            }
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
                        && null !== ($dimHandler = $this->context->findObjectDimensionHandler($container->toObject()))
                    ) {
                        // Extension-owned read_dimension (DOM collections / ResourceBundle; #20311, #25145, #36204).
                        // Not ArrayAccess — writes stay "Cannot use object of type … as array".
                        if ($forWrite) {
                            if ($dimHandler->rejectWrite) {
                                $className = $container->toObject()->class->name;
                                $catchFrame = $this->dispatchVmError(
                                    'Cannot use object of type ' . $className . ' as array',
                                    $frame
                                );
                                if (null !== $catchFrame) {
                                    $frame = $catchFrame;
                                    goto restart;
                                }
                            }
                            break;
                        }
                        try {
                            ($dimHandler->read)($container->toObject(), $arg3, $arg1);
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
                        // Resource as array subject — Zend Warning/null on read, scalar Error on write (#30028).
                        if (
                            Variable::TYPE_OBJECT === $container->type
                            && VM\ResourceSupport::isResourceObject($container->toObject())
                        ) {
                            if ($forWrite) {
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
                            if (!$fetchIs) {
                                $this->context->errors->arrayOffsetOnResource(
                                    $this->context,
                                    $frame,
                                    $scriptFile
                                );
                            }
                            $arg1->null();
                            break;
                        }
                        if (!$forWrite && TypeCheck::isScalarNonContainerDimRead($container)) {
                            if (!$fetchIs) {
                                $resolved = $container->resolveIndirect();
                                $this->context->errors->arrayOffsetOnNonContainer(
                                    VM\ErrorReporter::arrayOffsetTypeLabel($resolved),
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
                    // Encapsed "$this" / "{$this}" lowers to CAST_STRING on the this CV (#31728).
                    $catchFrame = $this->guardUnboundThisRead($frame, (int) $op->arg2);
                    if (null !== $catchFrame) {
                        $frame->callSiteLine = $savedCallSiteLine;
                        $frame = $catchFrame;
                        goto restart;
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
                    $src = $this->readScopeOperandForRuntimeRead($frame, (int) $op->arg2);
                    $dst = $frame->scope[$op->arg1];
                    $dst->copyFrom(VM\CastSupport::toObject($src, $this->context->classes));
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
                    if (1 === $frame->pos) {
                        $jumpIfOp = $frame->block->opCodes[1] ?? null;
                        if ($jumpIfOp instanceof OpCode && OpCode::TYPE_JUMPIF === $jumpIfOp->type) {
                            $loopExit = $this->tryExecuteCountedIntForLoopAtJumpIf($frame, $jumpIfOp);
                            if (null !== $loopExit) {
                                $frame = $loopExit;
                                continue 2;
                            }
                        }
                    }
                    // fall through
                case OpCode::TYPE_GREATER:
                case OpCode::TYPE_SMALLER_OR_EQUAL:
                case OpCode::TYPE_GREATER_OR_EQUAL:
                    if ($this->tryExecuteRelationalCompareFastPath($frame, $op)) {
                        break;
                    }
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
                    // "$this" / concat with this CV — Error outside object context (#31728).
                    $catchFrame = $this->guardUnboundThisRead($frame, (int) $op->arg2);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
                    $catchFrame = $this->guardUnboundThisRead($frame, (int) $op->arg3);
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
                    // echo $this outside object context — Error (zend_execute.c ZEND_ECHO / FETCH_THIS, #31901).
                    $catchFrame = $this->guardUnboundThisRead($frame, (int) $op->arg1);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
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
                    // print $this outside object context — Error (zend_execute.c ZEND_PRINT / FETCH_THIS, #31901).
                    $catchFrame = $this->guardUnboundThisRead($frame, (int) $op->arg2);
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
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
                    if (
                        $arg1
                        && [] === $this->context->activeTryHandlerFrames
                        && null === $this->context->activeCatchHandlerFrame
                        && !$this->frameIsInFinallyBody($frame)
                    ) {
                        $loopExit = $this->tryExecuteCountedIntForLoopAtJumpIf($frame, $op);
                        if (null !== $loopExit) {
                            $frame = $loopExit;
                            goto restart;
                        }
                    }
                    $this->releaseVmStatementDeadTemps($frame, $condSlot);
                    $this->releaseVmJumpIfCondTemps($frame, $condSlot);
                    $branchTarget = $arg1 ? $op->block1 : $op->block2;
                    if (
                        [] === $this->context->activeTryHandlerFrames
                        && null === $this->context->activeCatchHandlerFrame
                        && !$this->frameIsInFinallyBody($frame)
                    ) {
                        $frame = $this->frameForBranch($frame, $branchTarget);
                        goto restart;
                    }
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
                            // String (or Error) — do not stringify bool/int/null/array (#30059).
                            $className = VM\InstanceOfClassName::resolveClassNamePreservingCase(
                                $classOperand
                            );
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
                        // String class name only — reject bool/int/null/array (#30059).
                        $className = VM\InstanceOfClassName::resolveClassNamePreservingCase(
                            $classOperand
                        );
                        $lcClass = $this->resolveClassScopeName($className, $frame);
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
                            $keyword = $op->instanceofScopeKeyword;
                            if (null !== $keyword && '' !== $keyword) {
                                // Trait `instanceof self` → composing class (#31729, zend_inheritance.c).
                                $className = $this->resolveClassScopeName($keyword, $frame);
                            } else {
                                $className = VM\InstanceOfClassName::resolveClassName($frame->scope[$op->arg3]);
                            }
                            $matches = $this->valueInstanceOfClassName($value, $className);
                        }
                        $frame->scope[$op->arg1]->bool($matches);
                    } catch (\Error|\LogicException $e) {
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
                        if ($this->propertyFetchDestUsedAsLiveRefBinding($frame, $op)) {
                            VM\TypedPropertyCheck::prepareWritableByReference($storage);
                        }
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
                        // BP_VAR_W dim-assign/append auto-inits or TypeError (#31770/#31819);
                        // BP_VAR_RW ++/+= Errors (#31784).
                        if ($this->propertyFetchAllowsTypedArrayDimAutoInit($frame, $op)) {
                            VM\TypedPropertyCheck::tryInitEmptyArrayForDimWrite($storage);
                        } else {
                            VM\TypedPropertyCheck::assertReadable($storage);
                        }
                    }
                    if ($this->propertyFetchDestUsedAsLiveRefBinding($frame, $op)) {
                        VM\TypedPropertyCheck::prepareWritableByReference($storage);
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
                    // ZEND_UNSET_OBJ on non-object (array/scalar/null/…) — silent no-op
                    // (zend_vm_def.h; #30065). Must run before ARRAY / UNSET_DIM paths so
                    // unset($arr->prop) does not delete an array key and false stays silent.
                    if (VM\VmUnset::isNonObjectUnsetPropNoop($op->unsetOnProperty, $container->type)) {
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
                    // ZEND_UNSET_DIM: null/undef silent no-op; false → Deprecated only (leaves false)
                    // — does not promote to array (zend_vm_def.h; #30099).
                    if (VM\VmUnset::isNullOrUndefUnsetDimNoop($container)) {
                        break;
                    }
                    if (VM\VmUnset::isFalseUnsetDimDeprecated($container)) {
                        $this->context->errors->internalDeprecated(
                            TypeCheck::FALSE_TO_ARRAY_DEPRECATED_MESSAGE,
                            $this->context,
                            $frame,
                            '' !== $frame->scriptPath ? $frame->scriptPath : null
                        );
                        break;
                    }
                    $unsetDimMsg = Variable::TYPE_STRING === $container->type
                        ? VM\VmUnset::ERROR_STRING_OFFSET
                        : VM\VmUnset::ERROR_NON_ARRAY;
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
                    $rich = ClosureRichDisplayName::preferFromOp($op, $op->block1);
                    if (null !== $rich && '' !== $rich) {
                        $state->richDisplayName = $rich;
                    }
                    if (
                        (null === $state->boundScopeClass || '' === $state->boundScopeClass)
                        && null !== $op->closureDeclaringClass
                        && '' !== $op->closureDeclaringClass
                    ) {
                        $state->boundScopeClass = $op->closureDeclaringClass;
                    }
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
                        $existing = $this->context->functions[$lcname];
                        $prevFile = '';
                        $prevLine = 0;
                        if ($existing instanceof Func\PHP && null !== $existing->sourceLocation) {
                            $prevFile = $existing->sourceLocation->filename;
                            $prevLine = $existing->sourceLocation->startLine;
                        }
                        $message = ('' !== $prevFile && 'unknown' !== $prevFile && $prevLine > 0)
                            ? sprintf(
                                'Cannot redeclare %s() (previously declared in %s:%d)',
                                $name,
                                $prevFile,
                                $prevLine
                            )
                            : sprintf('Cannot redeclare %s()', $name);
                        $error = new \CompileError($message);
                        // Inside eval(): rethrow so TYPE_EVAL can raiseEvalCompileFatal.
                        // Outside eval, uncatchable E_COMPILE_ERROR like Zend (#31109).
                        if (VmEval::EVAL_FILENAME === $frame->scriptPath
                            || str_ends_with((string) $frame->scriptPath, VmEval::EVAL_FILENAME)
                        ) {
                            throw $error;
                        }
                        $this->raiseClassDeclareCompileFatal($error, $frame);
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
                            $this->initStaticCallable($frame, $name, false, false, false, true);
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
                        // zend_zval_value_name — bool prints true/false, not bool (#30054).
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
                    if (Variable::TYPE_OBJECT === $receiver->type
                        && VM\ResourceSupport::isResourceObject($receiver->toObject())) {
                        $catchFrame = $this->dispatchVmError(
                            sprintf('Call to a member function %s() on resource', $methodName),
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
                        if ([] === $frame->pendingOutboundCallRestore) {
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
                        // Typed-int self-recursive leaf (fibo_r): evaluate in host PHP (#36411 / #36449).
                        if (
                            $frame->call instanceof Func\PHP
                            && $this->tryExecuteTypedIntSelfRecursive($frame->call, $calledArgs, $frame, $op)
                        ) {
                            break;
                        }
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
                            foreach ($calleeBlock->argRecvOpcodes() as $recv) {
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
                        $this->raiseDuplicateClassLikeDeclareFatal('interface', $name, $frame);
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
                        $this->raiseDuplicateClassLikeDeclareFatal('trait', $name, $frame);
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
                        $this->raiseDuplicateClassLikeDeclareFatal('enum', $name, $frame);
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
                        $this->raiseDuplicateClassLikeDeclareFatal('class', $name, $frame);
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
                    // Zend ZEND_NEW: classname operand is string or object (Z_OBJCE_P) (#30058).
                    try {
                        $rawName = VM\InstanceOfClassName::resolveClassNamePreservingCase(
                            $frame->scope[$op->arg2]
                        );
                        $lcname = $this->resolveClassScopeName($rawName, $frame);
                    } catch (\Error $e) {
                        $catchFrame = $this->dispatchVmError($e->getMessage(), $frame);
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
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
                        // zend_zval_value_name — bool prints true/false, not bool (#30054 / #30066).
                        $typeName = VM\EnumCaseSupport::typeNameForTypeErrorActual($resolved);
                        $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
                        $forWrite = $propertyFetchForWrite
                            || $this->propertyFetchDestUsedAsAssignLvalue($frame, $op)
                            || $this->propertyFetchDestUsedAsReadBeforeAssign($frame, $op);
                        if ($forWrite) {
                            // ZEND_PRE/POST_INC/DEC_OBJ: verb is increment/decrement for any
                            // non-object (null and true/false), not only null (#7431 / #30075).
                            if ($this->propertyFetchDestUsedAsIncDec($frame, $op)) {
                                $catchFrame = $this->dispatchVmError(
                                    sprintf(
                                        'Attempt to increment/decrement property "%s" on %s',
                                        $name,
                                        $typeName
                                    ),
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
                        if ($op->propertyHookCoalesceRead) {
                            // ?? / ??= BP_VAR_IS on non-object — silent null (#30120, zend_vm_def.h).
                            $result->null();
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
                    // Static prop via -> / ?->: E_NOTICE then dynamic/undefined (zend_object_handlers.c, #30017).
                    // isset / ?? (propertyHookCoalesceRead) are silent; inaccessible protected/private Error.
                    $catchFrame = $this->handleStaticPropertyAccessedAsInstance(
                        $propertyObject,
                        $name,
                        $frame,
                        $op->propertyHookCoalesceRead
                    );
                    if (null !== $catchFrame) {
                        $frame = $catchFrame;
                        goto restart;
                    }
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
                    if (VM\ResourceSupport::isResourceObject($propertyObject)) {
                        $forWrite = $propertyFetchForWrite
                            || $this->propertyFetchDestUsedAsAssignLvalue($frame, $op)
                            || $this->propertyFetchDestUsedAsReadBeforeAssign($frame, $op);
                        if ($forWrite) {
                            if ($this->propertyFetchDestUsedAsIncDec($frame, $op)) {
                                $catchFrame = $this->dispatchVmError(
                                    sprintf('Attempt to increment/decrement property "%s" on resource', $name),
                                    $frame
                                );
                            } else {
                                $catchFrame = $this->dispatchVmError(
                                    sprintf('Attempt to assign property "%s" on resource', $name),
                                    $frame
                                );
                            }
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }

                            return self::EXCEPTION;
                        }
                        if ($op->propertyHookCoalesceRead) {
                            $result->null();
                            break;
                        }
                        $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
                        $this->context->errors->propertyReadOnNonObject(
                            $name,
                            'resource',
                            $this->context,
                            $frame,
                            $scriptFile
                        );
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
                            // Magic __get supplies the value — no Undefined property (#31992, zend_object_handlers.c).
                            $warnUndefAfterRw = $this->propertyFetchDestUsedAsIncDec($frame, $op)
                                && $this->objectPropertySlotIsUndefinedForRwWarn($propertyObject, $name, $frame)
                                && !$this->propertyReadUsesMagicGet($propertyObject, $name, $frame);
                            $writeLvalue = $this->fetchObjectPropertyWriteLvalue($propertyObject, $name, $frame, $op);
                            if ($this->propertyFetchDestUsedAsLiveRefBinding($frame, $op)) {
                                VM\TypedPropertyCheck::prepareWritableByReference($writeLvalue);
                            }
                            $result->indirect($writeLvalue);
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
                            $domStaleMsg = VM\DomVmRuntimeSupport::fetchableNodeErrorMessage($propertyObject);
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
                            // Dim-write (`$o->a[0]=` / `$o->a[]=`) is BP_VAR_W: uninitialized typed
                            // array slots auto-init to []; other types TypeError (zend_try_array_init,
                            // #31770 / #31819). Dim RW (`$o->a[0]++` / `+=`) is BP_VAR_RW Error (#31784).
                            // foreach ($o->a as &$v) is FE_RESET_RW / get_property_ptr_ptr — same by-ref
                            // uninitialized Error as `$r = &$o->a` (#31836), not the bare-read wording.
                            if ($this->propertyFetchAllowsTypedArrayDimAutoInit($frame, $op)) {
                                VM\TypedPropertyCheck::tryInitEmptyArrayForDimWrite($propSlot);
                            } elseif ($this->propertyFetchDestUsedAsByRefForeachIterable($frame, $op)) {
                                VM\TypedPropertyCheck::prepareWritableByReference($propSlot);
                            } else {
                                VM\TypedPropertyCheck::assertReadable($propSlot);
                            }
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
                                // Live HT for FE_RESET_RW (nullable uninit was null-inited above).
                                $result->indirect($propSlot);
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
                        // Magic __get supplies the value — no Undefined property (#31992, zend_object_handlers.c).
                        $warnUndefAfterRw = $this->propertyFetchDestUsedAsIncDec($frame, $op)
                            && !$this->propertyReadUsesMagicGet($propertyObject, $name, $frame);
                        $writeLvalue = $this->fetchObjectPropertyWriteLvalue($propertyObject, $name, $frame, $op);
                        if ($this->propertyFetchDestUsedAsLiveRefBinding($frame, $op)) {
                            VM\TypedPropertyCheck::prepareWritableByReference($writeLvalue);
                        }
                        $result->indirect($writeLvalue);
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
                    if (VM\SplArraySupport::hasArrayAsProps($propertyObject)) {
                        $key = new Variable(Variable::TYPE_STRING);
                        $key->string($name);
                        // php-src spl_array_read_property — Undefined array key (not property) (#28820).
                        $result->copyFrom(VM\SplArraySupport::offsetGet($propertyObject, $key, $frame));
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
                                && null !== ($dimHandler = $this->context->findObjectDimensionHandler($object))
                                && null !== $dimHandler->has
                            ) {
                                // isset($list[$i]) via extension has_dimension (php-src php_dom.c; #20311 / #36204).
                                // TokenList illegal offsets TypeError (token_list.c; #23006).
                                try {
                                    $dst->bool(($dimHandler->has)(
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
                                // Resource as array subject — isset soft-false like scalars (zend_execute.c, #30028).
                                if (VM\ResourceSupport::isResourceObject($object)) {
                                    $dst->bool(false);
                                    break;
                                }
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
                        // Zend two-step: stream Warning, then Failed opening Warning (include)
                        // or Error (require) with include_path (#30029; fopen_wrappers.c).
                        $keyword = VM\VmInclude::kindKeyword($kind);
                        $includePath = \PHPCompiler\ext\standard\VmIncludePath::get();
                        $scriptFile = '' !== $frame->scriptPath ? $frame->scriptPath : null;
                        $this->context->errors->triggerError(
                            VM\VmInclude::failedToOpenStreamMessage($keyword, $file),
                            VM\ErrorReporter::E_WARNING,
                            $scriptFile,
                            $this->context,
                            $frame
                        );
                        if ($isRequire) {
                            $catchFrame = $this->dispatchEngineThrow(
                                $frame,
                                $this->makeEngineError(
                                    VM\VmInclude::failedOpeningRequiredMessage($file, $includePath),
                                    'Error'
                                )
                            );
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        }
                        $this->context->errors->triggerError(
                            VM\VmInclude::failedOpeningForInclusionMessage($keyword, $file, $includePath),
                            VM\ErrorReporter::E_WARNING,
                            $scriptFile,
                            $this->context,
                            $frame
                        );
                        if (null !== $op->arg2 && isset($frame->scope[$op->arg2])) {
                            $frame->scope[$op->arg2]->bool(false);
                        }
                        break;
                    }

                    // Project builds refuse includes outside the compile-unit file map (#36382).
                    $allow = $this->context->runtime->aotIncludeAllowlist ?? null;
                    if (is_array($allow) && [] !== $allow
                        && !VM\ProjectIncludeAllowlist::isAllowed($resolved, $allow)
                    ) {
                        $catchFrame = $this->dispatchEngineThrow(
                            $frame,
                            $this->makeEngineError(
                                VM\ProjectIncludeAllowlist::denyMessage($resolved),
                                'Error'
                            )
                        );
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
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
                    $this->context->recordIncludedFile($resolved);
                    $this->context->scriptStack->push($resolved);
                    try {
                        $parsed = $this->context->runtime->parseAndCompileFile($resolved, true);
                    } catch (\Throwable $e) {
                        $this->context->scriptStack->pop();
                        if (VM\VmInclude::isCatchableSyntaxParseThrowable($e)) {
                            $catchFrame = $this->dispatchIncludeParseError($e, $resolved, $frame);
                            if (null !== $catchFrame) {
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        }
                        throw $e;
                    }
                    if (null === $parsed) {
                        $this->context->scriptStack->pop();
                        $detail = $this->context->runtime->formatParseAndCompileNullDetail(null)
                            ?? Runtime::getLastParseFailure()
                            ?? 'syntax error';
                        $catchFrame = $this->dispatchIncludeParseError(
                            new \ParseError(VM\VmInclude::normalizeSyntaxParseMessage($detail)),
                            $resolved,
                            $frame
                        );
                        if (null !== $catchFrame) {
                            $frame = $catchFrame;
                            goto restart;
                        }
                        break;
                    }
                    $new = $parsed->getFrame($this->context, $frame);
                    $new->ephemeral = true;
                    // ZEND_INCLUDE_OR_EVAL copies EX(This) into the included op_array (#31903).
                    $this->inheritIncludeThis($new, $frame);
                    // …and called_scope for self/static/parent in the included unit (#31913).
                    $this->inheritIncludeClassScope($new, $frame);
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
                        try {
                            $innerAdvanced = $this->advanceGeneratorIteration($inner);
                        } catch (VM\GeneratorUncaughtThrow $e) {
                            // Zend: inner throw at yield-from is catchable in the outer generator (#32102).
                            $gen->yieldFromActive = false;
                            $gen->yieldFromIteratorAdvance = false;
                            $catchFrame = $this->dispatchEngineThrow($frame, $e->thrown);
                            if (null !== $catchFrame) {
                                $catchFrame->generatorState = $gen;
                                $gen->frame = $catchFrame;
                                $frame = $catchFrame;
                                goto restart;
                            }
                            break;
                        }
                        if ($innerAdvanced) {
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
                            if (VM\SplArraySupport::allowsForeachByRef($iterObj)) {
                                $byRef = VM\SplArraySupport::foreachCurrentByRef($iterObj);
                                if (null !== $byRef) {
                                    $frame->scope[$op->arg1]->indirect($byRef);
                                    $this->markScopeSlotInitialized($frame, (int) $op->arg1);
                                    $this->context->foreachObjectAdvance[$op->arg2] = true;
                                    break;
                                }
                            }
                            if (VM\SplArraySupport::allowsRecursiveArrayIteratorForeachByRef($iterObj)) {
                                $byRef = VM\SplArraySupport::recursiveArrayIteratorForeachCurrentByRef($iterObj);
                                if (null !== $byRef) {
                                    $frame->scope[$op->arg1]->indirect($byRef);
                                    $this->markScopeSlotInitialized($frame, (int) $op->arg1);
                                    $this->context->foreachObjectAdvance[$op->arg2] = true;
                                    break;
                                }
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
                            $frame->scope[$op->arg1]->indirectAsPhpReference(
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
                                $frame->scope[$op->arg1]->indirectAsPhpReference($iter->currentValue(true));
                                $this->markScopeSlotInitialized($frame, (int) $op->arg1);
                            } else {
                                $frame->scope[$op->arg1]->assignForeachByValue($iter->currentValue(false));
                            }
                            break;
                        }
                        if ($byRef) {
                            try {
                                $frame->scope[$op->arg1]->indirectAsPhpReference(
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
                        $frame->scope[$op->arg1]->indirectAsPhpReference(
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

        // Cross-block loop back-edges must reuse the header frame — otherwise every iteration
        // chains a new parent Frame and getFrame walks grow without bound (#1228, #15906, #36148).
        // Recursive re-entry shares Block objects across activations — only reuse frames within
        // the current call (#23472 g06_nested_recursion OOM).
        $targetFunc = $target->func;
        $activationEntry = $this->activationEntryFrame($frame, $targetFunc);

        if (
            null !== $frame->parent
            && $frame->parent->block === $target
            && (null === $activationEntry || $this->frameInActivation($activationEntry, $frame->parent))
        ) {
            $frame->parent->pos = 0;

            return $frame->parent;
        }

        for ($ancestor = $frame->parent; null !== $ancestor; $ancestor = $ancestor->parent) {
            if (null !== $activationEntry && !$this->frameInActivation($activationEntry, $ancestor)) {
                break;
            }
            if ($ancestor->block === $target) {
                $ancestor->pos = 0;

                return $ancestor;
            }
            if (
                null !== $targetFunc
                && null !== $ancestor->block
                && null !== $ancestor->block->func
                && $ancestor->block->func !== $targetFunc
            ) {
                break;
            }
        }

        return $target->getFrame($this->context, $frame);
    }

    /** Innermost entry-block frame for the active call of $func, if any. */
    private function activationEntryFrame(Frame $frame, ?\PHPCfg\Func $func): ?Frame
    {
        if (null === $func || null === $func->cfg) {
            return null;
        }
        for ($f = $frame; null !== $f; $f = $f->parent) {
            if (null === $f->block || $f->block->func !== $func) {
                break;
            }
            if (null !== $f->block->orig && $f->block->orig === $func->cfg) {
                return $f;
            }
        }

        return null;
    }

    /** True when $candidate is $activationEntry or a descendant frame in the same activation. */
    private function frameInActivation(Frame $activationEntry, Frame $candidate): bool
    {
        for ($f = $candidate; null !== $f; $f = $f->parent) {
            if ($f === $activationEntry) {
                return true;
            }
        }

        return false;
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
     * File scope ({main}), plain functions, and static methods: $this is never bound (#31728).
     */
    private function isUnboundThisSlot(Frame $frame, int $slot): bool
    {
        $thisIdx = $frame->block->slotIndexForVariableName('this');
        if (null === $thisIdx || $thisIdx !== $slot) {
            return false;
        }
        $func = $frame->block->func;
        // No frame function (eval/include {main}) — bound only when EX(This) was inherited (#31902).
        if (null === $func) {
            if (isset($frame->scope[$thisIdx])) {
                $var = $frame->scope[$thisIdx]->resolveIndirect();
                if (Variable::TYPE_OBJECT === $var->type) {
                    return false;
                }
            }

            return true;
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
        // Instance method: unbound when $this was never installed in scope.
        if (null !== $func->class) {
            return !isset($frame->scope[$thisIdx]);
        }
        // {main} / plain function — $this is never in object context (php-src FETCH_THIS)
        // unless eval/include inherited EX(This) from an instance caller (#31902, #31903).
        if (isset($frame->scope[$thisIdx])) {
            $var = $frame->scope[$thisIdx]->resolveIndirect();
            if (Variable::TYPE_OBJECT === $var->type) {
                return false;
            }
        }

        return true;
    }

    /**
     * ZEND_INCLUDE_OR_EVAL copies EX(This) into the included/eval {main} frame (#31902, #31903).
     *
     * php-src: Zend/zend_execute.c ZEND_INCLUDE_OR_EVAL; Zend/zend_vm_def.h inherits EX(This).
     */
    private function inheritIncludeThis(Frame $included, Frame $caller): void
    {
        $thisIdx = $included->block->slotIndexForVariableName('this');
        if (null === $thisIdx) {
            return;
        }
        $inherited = self::callerThisIfBound($caller);
        if (null === $inherited) {
            return;
        }
        if (!isset($included->scope[$thisIdx])) {
            $included->scope[$thisIdx] = new Variable();
        }
        $included->scope[$thisIdx]->copyFrom($inherited);
    }

    /**
     * zend_eval_string copies func->common.scope (self) and called_scope (static) (#31912).
     *
     * php-src: Zend/zend_execute_API.c zend_eval_string; Zend/zend_execute.c ZEND_INCLUDE_OR_EVAL.
     */
    private function inheritEvalClassScope(Frame $eval, Frame $caller): void
    {
        $declaring = VmEval::declaringClassFromFrame($caller);
        if (null !== $declaring && '' !== $declaring) {
            $eval->scopeClass = $declaring;
        }
        if (null === $eval->calledClass || '' === $eval->calledClass) {
            if (null !== $caller->calledClass && '' !== $caller->calledClass) {
                $eval->calledClass = $caller->calledClass;
            } elseif (null !== $declaring && '' !== $declaring) {
                $eval->calledClass = $declaring;
            }
        }
    }

    /**
     * ZEND_INCLUDE_OR_EVAL copies caller called_scope into the included {main} frame (#31913).
     *
     * php-src: Zend/zend_execute.c / zend_vm_def.h — self/static/parent in an included file
     * bind to the runtime caller class, not a compile-time global-scope reject.
     */
    private function inheritIncludeClassScope(Frame $included, Frame $caller): void
    {
        if (null !== $included->calledClass && '' !== $included->calledClass) {
            return;
        }
        $scope = self::includeCallerClassScopeLc($caller);
        if (null !== $scope) {
            $included->calledClass = $scope;
        }
    }

    /**
     * Late-static / self scope class (lowercase) of an include/require caller frame.
     */
    private static function includeCallerClassScopeLc(Frame $caller): ?string
    {
        if (null !== $caller->calledClass && '' !== $caller->calledClass) {
            return $caller->calledClass;
        }
        if (null !== $caller->block && null !== $caller->block->func && null !== $caller->block->func->class) {
            return strtolower(ltrim($caller->block->func->class->value, '\\'));
        }
        $boundThis = self::callerThisIfBound($caller);
        if (null !== $boundThis && Variable::TYPE_OBJECT === $boundThis->type) {
            return strtolower($boundThis->toObject()->class->name);
        }

        return null;
    }
    private static function callerThisIfBound(Frame $caller): ?Variable
    {
        $func = null !== $caller->block ? $caller->block->func : null;
        if (null !== $func && (($func->flags ?? 0) & \PHPCfg\Func::FLAG_STATIC) !== 0) {
            return null;
        }
        if (null !== $caller->pendingClosureInvoke && null !== $caller->pendingClosureInvoke->boundThis) {
            $bound = $caller->pendingClosureInvoke->boundThis->resolveIndirect();
            if (Variable::TYPE_OBJECT === $bound->type) {
                return $bound;
            }
        }
        if (null !== $caller->closureCall && null !== $caller->closureCall->boundThis) {
            $bound = $caller->closureCall->boundThis->resolveIndirect();
            if (Variable::TYPE_OBJECT === $bound->type) {
                return $bound;
            }
        }
        if (null !== $caller->block) {
            $idx = $caller->block->slotIndexForVariableName('this');
            if (null !== $idx && isset($caller->scope[$idx])) {
                $var = $caller->scope[$idx]->resolveIndirect();
                if (Variable::TYPE_OBJECT === $var->type) {
                    return $var;
                }
            }
        }
        // Instance method whose body never mentioned $this — receiver is calledArgs[0].
        if (
            null !== $func
            && null !== $func->class
            && isset($caller->calledArgs[0])
        ) {
            $arg0 = $caller->calledArgs[0]->resolveIndirect();
            if (Variable::TYPE_OBJECT === $arg0->type) {
                return $arg0;
            }
        }

        return null;
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
     * ++/-- on ArrayAccess when offsetGet returns by value — Notice, no offsetSet (#32015, zend_vm_def.h).
     */

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

    /**
     * Zend {@code zend_zval_value_name()} labels for method-on-non-object Errors (#4241, #30054).
     *
     * Booleans use {@code true}/{@code false}, not {@code bool} (distinct from TypeError
     * {@code zend_zval_type_name} spelling gated in {@see VM\EnumCaseSupport::typeNameForTypeErrorActual()}).
     */
    private function valueDebugTypeLabel(Variable $value): string
    {
        if (Variable::TYPE_OBJECT === $value->type || Variable::TYPE_ENUM_CASE === $value->type) {
            return 'object';
        }

        return VM\EnumCaseSupport::typeNameForZvalValueName($value);
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
        } catch (VM\ExtSimdJsonValueError $e) {
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
        } catch (VM\ExtSimdJsonException $e) {
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
        } catch (\OutOfRangeException $e) {
            // SplDoublyLinkedList OOB — before LogicException (parent) (#31553).
            return $this->dispatchVmOutOfRangeException($e, $callerFrame);
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

    /** ASSIGN to ArrayAccess lvalue — dispatch deferred offsetSet TypeError (#8949). */
    private function assignCopyFrom(Variable $dst, Variable $src, Frame $frame): ?Frame
    {
        try {
            $resolved = $dst->resolveIndirect();
            if (null !== $resolved->objectPropertyOwner && null !== $resolved->objectPropertyName) {
                try {
                    VM\ObjectComputedPropertySupport::rejectReadOnlyPropertyWrite(
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
                if (VM\ObjectComputedPropertySupport::tryAssign(
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
        } catch (\OutOfRangeException $e) {
            // SplDoublyLinkedList OOB dim — before LogicException (parent) (#31553).
            $resolved = $dst->resolveIndirect();
            if ($resolved->isArrayAccessOffset()) {
                $dst->null();
            }

            return $this->dispatchVmOutOfRangeException($e, $frame);
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

    /**
     * Reject unset($scalar[$key]) — Zend ZEND_UNSET_DIM on non-array/string (#4880, zend_execute.c).
     *
     * Route through {@see dispatchVmError} so getFile()/getLine() stamp the user unset site
     * (#31883, re-#31859).
     *
     * @return Frame|null catch frame when try/catch (Error) handles the throw
     */
    private function dispatchUnsetDimNonContainerError(Frame $frame, string $message): ?Frame
    {
        return $this->dispatchVmError($message, $frame);
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
     *
     * Route through {@see dispatchVmError} so getFile()/getLine() stamp the user unset site
     * (#31859, zend_object_handlers.c).
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

        return $this->dispatchVmError(
            sprintf('Attempt to unset static property %s::$%s', $className, $propNameRaw),
            $frame
        );
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

    /**
     * zend_should_call_hook is false for uninitialized same-name backed hooks (#30739).
     * Virtual / distinct-backing properties still invoke get.
     */
    private function skipHookedGetForUninitializedSameNameBacking(ObjectEntry $object, string $propName): bool
    {
        if (!$this->instancePropertyHasGetHook($object, $propName)) {
            return false;
        }
        if ($this->instancePropertyIsVirtualHook($object, $propName)) {
            return false;
        }
        if ($this->hookedPropertyUsesDistinctBacking($object, $propName)) {
            return false;
        }
        $backing = $this->hookedPropertyBackingValue($object, $propName);
        if (false === $backing) {
            return false;
        }

        return $backing->isUndefined() || VM\TypedPropertyCheck::isUninitialized($backing);
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
        $live = $frame->scope[$slot];
        // ITER_RESET always addRef's a snapshot wrapper (by-value COW). Switching to the
        // live CV must drop that extra HT ref — otherwise `$a[]` during foreach-by-ref
        // zend_array_dup's and leaves IS_REFERENCE aliases on the iterated slot (#32128).
        $old = $frame->iterators[$slot] ?? ($this->context->foreachIterators[$slot] ?? null);
        if (null !== $old && $old !== $live) {
            $resolved = $old->resolveIndirect();
            if (Variable::TYPE_ARRAY === $resolved->type) {
                $resolved->toArray()->delRef();
            }
        }
        // Zend FE_RESET_RW SEPARATE_ARRAY: property defaults / other shared tables must
        // become unique before ZVAL_MAKE_REF, or `$o->items[]` dups and shares IS_REFERENCE
        // with the class default (#32128).
        $live->separateArrayForWrite();
        $frame->iterators[$slot] = $live;
        $this->context->foreachIterators[$slot] = $live;
        // ITER_RESET stores the by-value snapshot on the header frame; CFG edges copy
        // frame->iterators to children (#36354). Rebind must replace that snapshot on
        // every ancestor in this activation — otherwise ITER_VALID on the reused header
        // restores context->foreachIterators to the snapshot and the next FE_FETCH_RW
        // delRefs it again (rc 1→0), destroying the live HT mid-foreach (i11 / #24010).
        $func = $frame->block->func ?? null;
        for ($ancestor = $frame->parent; null !== $ancestor; $ancestor = $ancestor->parent) {
            if (
                null !== $func
                && null !== $ancestor->block
                && null !== $ancestor->block->func
                && $ancestor->block->func !== $func
            ) {
                break;
            }
            if (isset($ancestor->iterators[$slot])) {
                $ancestor->iterators[$slot] = $live;
            }
        }
    }

    private function resolveForeachContainer(Frame $frame, int $slot): Variable
    {
        // Prefer the per-frame cache. context->foreachIterators is keyed only by operand
        // slot index, so a nested/recursive call that reuses the same slot number would
        // otherwise leave the caller's ITER_VALID reading the callee's exhausted HT
        // (recursive flatten / foreach-in-function calling foreach — #36354; Zend keeps
        // FE state on execute_data, see zend_execute.c ZEND_FE_RESET / FE_FETCH).
        if (isset($frame->iterators[$slot])) {
            $this->context->foreachIterators[$slot] = $frame->iterators[$slot];

            return $frame->iterators[$slot]->resolveIndirect();
        }
        if (isset($this->context->foreachIterators[$slot])) {
            return $this->context->foreachIterators[$slot]->resolveIndirect();
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
        $frame->pendingOutboundCallRestore[] = [
            'call' => $frame->call,
            'callArgs' => $frame->callArgs,
            'callArgEntries' => $frame->callArgEntries,
            'callSiteLine' => $frame->callSiteLine,
            'builtinCalleeQualifiedMethod' => $frame->builtinCalleeQualifiedMethod,
        ];
    }

    private function restorePendingOutboundCallAfterInlineNew(Frame $frame): void
    {
        if ([] === $frame->pendingOutboundCallRestore) {
            return;
        }
        $saved = array_pop($frame->pendingOutboundCallRestore);
        $frame->call = $saved['call'];
        $frame->callArgs = $saved['callArgs'];
        $frame->callArgEntries = $saved['callArgEntries'];
        $frame->callSiteLine = $saved['callSiteLine'];
        $frame->builtinCalleeQualifiedMethod = $saved['builtinCalleeQualifiedMethod'];
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
            // Callee LSB is applied in applyClosureBinding — do not write the FCC class onto
            // the caller frame (that poisoned later static:: / : static in the unit, #32083).
            $this->initMethodCall($frame, $state->methodReceiver, $state->methodName);
            $frame->closureCall = null;
            $frame->pendingClosureInvoke = $state;

            return;
        }
        // Static magic fake closure: methodName + __callStatic, no receiver (#25757).
        if (
            null !== $state->methodName
            && '' !== $state->methodName
            && null !== $state->wrappedFunc
            && null === $state->methodReceiver
        ) {
            $frame->magicCallMethodName = $state->methodName;
            $frame->call = $state->wrappedFunc;
            $frame->closureCall = null;
            $frame->pendingClosureInvoke = $state;
            $frame->callArgs = [];
            $frame->callArgEntries = [];
            $frame->builtinCalleeQualifiedMethod = null;

            return;
        }
        if (null !== $state->wrappedFunc) {
            $frame->call = $state->wrappedFunc;
            $frame->closureCall = null;
            $frame->pendingClosureInvoke = $state;
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

    /**
     * Late-bind trait `parent` param/return typehints to the composing class parent (#31747).
     *
     * Trait methods keep the lexical keyword on the shared Block (Reflection); type checks
     * resolve against the using class like zend_inheritance.c trait method copy.
     */
    public function resolveParentTypeHintClassLc(): ?string
    {
        $frame = $this->executingFrame;
        if (null === $frame) {
            return null;
        }
        try {
            return $this->resolveClassScopeName('parent', $frame);
        } catch (\Throwable) {
            return null;
        }
    }

    /** Display name for TypeError expected type when resolving trait `parent` (#31747). */
    public function resolveParentTypeHintClassName(): ?string
    {
        $parentLc = $this->resolveParentTypeHintClassLc();
        if (null === $parentLc || '' === $parentLc) {
            return null;
        }
        $entry = $this->context->classes[$parentLc] ?? null;

        return null !== $entry && '' !== $entry->name ? $entry->name : $parentLc;
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
        // eval/include {main}: declaring class copied from the caller (#31912).
        if (null !== $frame->scopeClass && '' !== $frame->scopeClass) {
            return strtolower($frame->scopeClass);
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
        $entry->pendingConstMaterialization = null;

        return [];
    }

    /**
     * Lazy-evaluate a forward-declared class constant (zend_get_class_constant_ex / #31837).
     *
     * {@code $markVisited} mirrors IS_CONSTANT_VISITED: nested fetches mark; the outer
     * FETCH_CLASS_CONSTANT path does not (so mutual cycles report the peer name).
     */
    public function materializePendingClassConstant(
        ClassEntry $entry,
        string $constName,
        bool $markVisited = true,
        string $fetchClassName = 'self'
    ): void {
        if (isset($entry->constants[$constName])) {
            return;
        }
        if ($markVisited && isset($entry->visitedConstNames[$constName])) {
            throw new \Error(
                VM\ClassConstExpr::selfReferencingConstantMessage($entry, $constName, $fetchClassName)
            );
        }
        $pending = $entry->pendingConstMaterialization;
        if (null === $pending || !isset($pending['segments'][$constName])) {
            $this->hydratePendingConstMaterializationFromDeferred($entry);
            $pending = $entry->pendingConstMaterialization;
        }
        if (null === $pending || !isset($pending['segments'][$constName])) {
            return;
        }
        if ($markVisited) {
            $entry->visitedConstNames[$constName] = true;
        }
        $prevLazy = $entry->lazyConstMaterialize;
        $entry->lazyConstMaterialize = true;
        try {
            $this->evaluateDeferredClassConstSegment(
                $entry,
                $pending['block'],
                $pending['frame'],
                $pending['classBodyOps'],
                $pending['segments'][$constName]
            );
            unset($entry->forwardDeclaredConstNames[$constName]);
            if (null !== $entry->forwardDeclaredConstNames && [] === $entry->forwardDeclaredConstNames) {
                $entry->forwardDeclaredConstNames = null;
            }
            unset($entry->pendingConstMaterialization['segments'][$constName]);
            if (
                null !== $entry->pendingConstMaterialization
                && [] === $entry->pendingConstMaterialization['segments']
            ) {
                $entry->pendingConstMaterialization = null;
            }
            $this->removeDeferredClassConstSegment($entry, $constName);
        } finally {
            $entry->lazyConstMaterialize = $prevLazy;
            if ($markVisited) {
                unset($entry->visitedConstNames[$constName]);
            }
        }
    }

    private function hydratePendingConstMaterializationFromDeferred(ClassEntry $entry): void
    {
        foreach ($this->context->deferredClassConstants as $deferred) {
            if ($deferred['entry'] !== $entry) {
                continue;
            }
            $entry->pendingConstMaterialization = [
                'block' => $deferred['block'],
                'frame' => $deferred['frame'],
                'classBodyOps' => $deferred['classBodyOps'],
                'segments' => $deferred['segments'],
            ];

            return;
        }
    }

    private function removeDeferredClassConstSegment(ClassEntry $entry, string $constName): void
    {
        $remaining = [];
        foreach ($this->context->deferredClassConstants as $deferred) {
            if ($deferred['entry'] !== $entry) {
                $remaining[] = $deferred;
                continue;
            }
            unset($deferred['segments'][$constName]);
            if ([] !== $deferred['segments']) {
                $remaining[] = $deferred;
            }
        }
        $this->context->deferredClassConstants = $remaining;
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
                // Same classname operand rules as runtime TYPE_NEW (#30058).
                $name = VM\InstanceOfClassName::resolveClassNamePreservingCase(
                    $frame->scope[$op->arg2]
                );
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
                $rich = ClosureRichDisplayName::preferFromOp($op, $op->block1);
                if (null !== $rich && '' !== $rich) {
                    $state->richDisplayName = $rich;
                }
                if (
                    (null === $state->boundScopeClass || '' === $state->boundScopeClass)
                    && null !== $op->closureDeclaringClass
                    && '' !== $op->closureDeclaringClass
                ) {
                    $state->boundScopeClass = $op->closureDeclaringClass;
                }
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

    /** True when ArrayAccess::offsetGet is declared `&offsetGet` (zend_vm_def.h ZEND_PRE/POST_INC). */
    public function arrayAccessOffsetGetReturnsByRef(ObjectEntry $object): bool
    {
        return $this->instanceMethodReturnsByRef($object, 'offsetGet');
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
            // Zend-shaped callable label ({closure}), not php-cfg {anonymous}#N (#30020).
            TypeCheck::assertNeverReturn($this->returnTypeCallableName($frame));

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
                $this->returnTypeCallableName($frame),
                $expected
            );
        }
        if ($block->returnTypeStatic) {
            TypeCheck::assertStaticReturn(
                $value,
                $this->lateStaticClassLc($frame),
                $this->context,
                $this->returnTypeCallableName($frame)
            );

            return;
        }
        if (null !== $block->returnDnfConstraints && null !== $value) {
            // `: iterable` as Traversable|array DNF on generators — wrapper type only (#26468 / #29888).
            if ($this->generatorHasTraversableReturnTypeLabel($block)) {
                return;
            }
            DnfCheck::assertMatches(
                $value,
                $block->returnDnfConstraints,
                $this->context,
                'Return value',
                null,
                $block->strictTypes,
                $this->returnTypeCallableName($frame)
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
                $this->returnTypeCallableName($frame)
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
            $this->returnTypeCallableName($frame)
        );
    }

    private function generatorHasTraversableReturnTypeLabel(Block $block): bool
    {
        if (!$block->isGenerator) {
            return false;
        }
        $returnLabel = ltrim(
            $block->returnDeclaredTypeLabel ?? $block->returnClassConstraint ?? '',
            '\\'
        );
        if ('' === $returnLabel) {
            return false;
        }

        // Zend: these declare the Generator wrapper at invoke, not getReturn() (#16141, #26468).
        // Bare `: iterable` keeps returnDeclaredTypeLabel=iterable with Traversable|array DNF (#29888).
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
        if ($this->generatorHasTraversableReturnTypeLabel($block)) {
            return false;
        }
        if (null !== $block->returnDnfConstraints) {
            return true;
        }
        if (null !== $block->returnClassConstraint) {
            return true;
        }
        if (null !== $block->returnTypeConstraint) {
            return true;
        }

        return false;
    }

    private function returnTypeCallableName(Frame $frame): ?string
    {
        $block = $frame->block;
        // Prefer ClosureState / rich Block name so return TypeErrors match Zend 8.4 (#30076).
        if (null !== $frame->closureCall || null !== $frame->pendingClosureInvoke
            || (null !== $block && null !== $block->closureRichDisplayName && '' !== $block->closureRichDisplayName)
        ) {
            return VM\ParamArgumentCountError::formatUserFunctionName(
                VM\ParamArgumentCountError::resolveFunctionName($frame)
            );
        }
        $func = $block->func ?? null;
        if (null === $func) {
            return null;
        }

        return VM\ParamArgumentCountError::typeErrorDisplayNameForCfgFunc($func, null, $block);
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
        if (
            !isset($classEntry->constants[$memberKey])
            && null !== $classEntry->forwardDeclaredConstNames
            && isset($classEntry->forwardDeclaredConstNames[$memberKey])
        ) {
            // Outer FETCH_CLASS_CONSTANT does not mark visited (zend_vm_def.h); nested
            // sibling fetches do via materializePendingClassConstant(mark=true) (#31837).
            $this->materializePendingClassConstant($classEntry, $memberKey, false, $classEntry->name);
        }
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
        $ephemeral = $frame->block->ephemeralScopeSlotIndexes();
        if ([] === $ephemeral) {
            return;
        }
        foreach ($ephemeral as $slot) {
            if ($slot === $keepSlot || !isset($frame->scope[$slot])) {
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
     * True when a scope cell is (or aliases) context-owned long-lived storage (#28039, #28040, #31937).
     *
     * DECLARE_FUNCTION_STATIC / global / class-static install an INDIRECT into a persistent cell;
     * FETCH_DIM_W into those arrays (and instance-property arrays) aliases a HashTable bucket.
     * Releasing through that alias on frame exit destroys Closures and wipes object properties
     * the persistent table still holds (destroyForGc while the cell pointer survives).
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
            if ($cell->persistentHashTableBucket) {
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

    /**
     * Mark a HashTable bucket that lives in persistent array storage (#31937).
     *
     * Class-static / function-static / global cells are already skipped by
     * {@see variableAliasesFunctionStaticCell}; the bucket Variable is a different
     * identity, so FETCH_DIM_W aliases must be tagged separately.
     */
    private function markPersistentHashTableBucketIfNeeded(Variable $containerSlot, Variable $bucket): void
    {
        if ($this->variableAliasesFunctionStaticCell($containerSlot)
            || $this->variableAliasesObjectPropertyCell($containerSlot)
        ) {
            $bucket->persistentHashTableBucket = true;

            return;
        }
        $resolved = $containerSlot->resolveIndirect();
        if ($resolved->persistentHashTableBucket) {
            $bucket->persistentHashTableBucket = true;
        }
    }

    /** Generator yield key/value cells must survive fcall temp release (#18184). */
    private function variableIsGeneratorYieldStorage(Variable $var): bool
    {
        if ($var->generatorYieldStorage) {
            return true;
        }

        return $var->resolveIndirect()->generatorYieldStorage;
    }

}
