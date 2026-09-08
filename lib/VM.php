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
require_once __DIR__.'/VM/Concern/FrameObjectRefAndDeadTempRelease.php';
require_once __DIR__.'/VM/Concern/GeneratorForeachAndYieldFrom.php';
require_once __DIR__.'/VM/Concern/FiberStartResumeAndThrow.php';
require_once __DIR__.'/VM/Concern/FrameActivationThisAndIncludeScope.php';
require_once __DIR__.'/VM/Concern/DeprecationNoticeEmit.php';
require_once __DIR__.'/VM/Concern/ReturnTypeEnforce.php';
require_once __DIR__.'/VM/Concern/VirtualPropertyHookEnforce.php';
require_once __DIR__.'/VM/Concern/MethodCallAndStaticCallableInit.php';
require_once __DIR__.'/VM/Concern/InheritanceFinalVarianceAndConstFetch.php';
require_once __DIR__.'/VM/Concern/ClassConstAndPropertyDefaultMaterialize.php';
require_once __DIR__.'/VM/Concern/OutgoingCallArgResolve.php';
require_once __DIR__.'/VM/Concern/InternalHandlerExecuteAndAssignCopy.php';
require_once __DIR__.'/VM/Concern/ClosureBindAndFunctionStatic.php';
require_once __DIR__.'/VM/Concern/ClassScopeAndStaticPropertyResolve.php';
require_once __DIR__.'/VM/Concern/IncludePathAndClassPseudoConst.php';
require_once __DIR__.'/VM/Concern/IteratorToArrayConvert.php';
require_once __DIR__.'/VM/Concern/PropertyHookFrameAndStaticLink.php';
require_once __DIR__.'/VM/Concern/ConstructMarkAndPendingOutboundCall.php';

use PHPCompiler\BuiltinByRefParams;
use PHPCompiler\Compiler\AttributeNames;
use PHPCompiler\Compiler\NoDiscardMetadata;
use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\Func;
use PHPCompiler\ext\standard\VmEval;
use PHPCompiler\ext\standard\VmForwardStaticCall;
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
    use FrameObjectRefAndDeadTempRelease;
    use GeneratorForeachAndYieldFrom;
    use FiberStartResumeAndThrow;
    use FrameActivationThisAndIncludeScope;
    use DeprecationNoticeEmit;
    use ReturnTypeEnforce;
    use VirtualPropertyHookEnforce;
    use MethodCallAndStaticCallableInit;
    use InheritanceFinalVarianceAndConstFetch;
    use ClassConstAndPropertyDefaultMaterialize;
    use OutgoingCallArgResolve;
    use InternalHandlerExecuteAndAssignCopy;
    use ClosureBindAndFunctionStatic;
    use ClassScopeAndStaticPropertyResolve;
    use IncludePathAndClassPseudoConst;
    use IteratorToArrayConvert;
    use PropertyHookFrameAndStaticLink;
    use ConstructMarkAndPendingOutboundCall;
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
                    $assignDestSlot = (int) $op->arg2;
                    $keepWriteThrough = $this->assignDestKeptAsWriteThrough($frame, $assignDestSlot);
                    if (
                        $arg2->isIndirect()
                        && !$arg2->phpReference
                        && !$arg2->propertyAssignLvalue
                        && !$keepWriteThrough
                    ) {
                        $arg2->reset();
                    }
                    // Hash-table bucket cells must never be the ASSIGN destination Variable object
                    // itself unless this is a real FETCH_DIM_W lvalue — multi-arg nested isset
                    // recycled the `$m[0] = …` write cell as the isset result slot and turned
                    // `$m[0]` into bool (#36398).
                    if ($arg2->hashTableBucketCell && !$keepWriteThrough) {
                        $fresh = new Variable();
                        $frame->scope[$assignDestSlot] = $fresh;
                        if ((int) $op->arg1 === $assignDestSlot) {
                            $arg1 = $fresh;
                        }
                        $arg2 = $fresh;
                    }
                    // Boolean/null sources are never dim write-backs: always break stale
                    // indirection before copyFrom write-through (#36398 isset result).
                    if (null !== $op->arg3) {
                        $srcPeek = isset($frame->block->constants[$op->arg3])
                            ? $frame->block->constants[$op->arg3]
                            : $frame->scope[(int) $op->arg3];
                        $srcPeek = $srcPeek->resolveIndirect();
                        if (
                            (Variable::TYPE_BOOLEAN === $srcPeek->type || Variable::TYPE_NULL === $srcPeek->type)
                            && $arg2->isIndirect()
                            && !$arg2->phpReference
                            && !$arg2->propertyAssignLvalue
                        ) {
                            $fresh = new Variable();
                            $frame->scope[$assignDestSlot] = $fresh;
                            if ((int) $op->arg1 === $assignDestSlot) {
                                $arg1 = $fresh;
                            }
                            $arg2 = $fresh;
                        }
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
                    // Never mutate a hash-table bucket (or a stale FETCH_DIM read indirect)
                    // in place when materialising the isset bool — slot reuse after
                    // FETCH_DIM_IS left `$m[0]` as bool true for multi-arg nested isset (#36398 /
                    // same class as #36380 Parsedown).
                    $issetDstSlot = (int) $op->arg1;
                    $dst = $frame->scope[$issetDstSlot];
                    if (
                        $dst->hashTableBucketCell
                        || ($dst->isIndirect() && !$dst->phpReference && !$dst->propertyAssignLvalue)
                    ) {
                        $fresh = new Variable();
                        $frame->scope[$issetDstSlot] = $fresh;
                        $dst = $fresh;
                    }
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
     * ++/-- on ArrayAccess when offsetGet returns by value — Notice, no offsetSet (#32015, zend_vm_def.h).
     */

    protected function raise(string $message, Frame $frame): int
    {
        $where = '' !== $frame->scriptPath ? $frame->scriptPath : 'script';
        throw new \LogicException($message.' in '.$where);
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

    protected function scopeSlot(Frame $frame, int $slot): Variable
    {
        if (!isset($frame->scope[$slot])) {
            $frame->scope[$slot] = new Variable();
        }

        return $frame->scope[$slot];
    }

}
