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
require_once __DIR__.'/VM/Concern/ObjectPropertyFetchDispatch.php';
require_once __DIR__.'/VM/Concern/ArrayDimFetchDispatch.php';
require_once __DIR__.'/VM/Concern/StaticPropertyFetchDispatch.php';
require_once __DIR__.'/VM/Concern/UnsetDispatch.php';
require_once __DIR__.'/VM/Concern/AssignDispatch.php';
require_once __DIR__.'/VM/Concern/FuncCallExecDispatch.php';
require_once __DIR__.'/VM/Concern/ArgRecvDispatch.php';
require_once __DIR__.'/VM/Concern/ScalarCastCompareArithConcatDispatch.php';
require_once __DIR__.'/VM/Concern/ClassConstFetchDispatch.php';
require_once __DIR__.'/VM/Concern/IssetDispatch.php';
require_once __DIR__.'/VM/Concern/IncludeDispatch.php';
require_once __DIR__.'/VM/Concern/FuncCallInitDispatch.php';
require_once __DIR__.'/VM/Concern/NewDispatch.php';

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
    use ObjectPropertyFetchDispatch;
    use ArrayDimFetchDispatch;
    use StaticPropertyFetchDispatch;
    use UnsetDispatch;
    use AssignDispatch;
    use FuncCallExecDispatch;
    use ArgRecvDispatch;
    use ScalarCastCompareArithConcatDispatch;
    use ClassConstFetchDispatch;
    use IssetDispatch;
    use IncludeDispatch;
    use FuncCallInitDispatch;
    use NewDispatch;
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
                    $assignOutcome = $this->executeAssignDispatch($frame, $op);
                    if ($assignOutcome instanceof Frame) {
                        $frame = $assignOutcome;
                        goto restart;
                    }
                    if (is_int($assignOutcome)) {
                        return $assignOutcome;
                    }
                    break;
                case OpCode::TYPE_ASSIGN_REF:
                    $assignRefOutcome = $this->executeAssignRefDispatch($frame, $op);
                    if ($assignRefOutcome instanceof Frame) {
                        $frame = $assignRefOutcome;
                        goto restart;
                    }
                    if (is_int($assignRefOutcome)) {
                        return $assignRefOutcome;
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
                    $dimFetchOutcome = $this->executeArrayDimFetchDispatch($frame, $op);
                    if ($dimFetchOutcome instanceof Frame) {
                        $frame = $dimFetchOutcome;
                        goto restart;
                    }
                    break;
                case OpCode::TYPE_CAST_BOOL:
                case OpCode::TYPE_CAST_INT:
                case OpCode::TYPE_CAST_FLOAT:
                case OpCode::TYPE_CAST_STRING:
                case OpCode::TYPE_CAST_ARRAY:
                case OpCode::TYPE_CAST_OBJECT:
                case OpCode::TYPE_CAST_UNSET:
                case OpCode::TYPE_CAST_VOID:
                case OpCode::TYPE_IDENTICAL:
                case OpCode::TYPE_NOT_IDENTICAL:
                case OpCode::TYPE_EQUAL:
                case OpCode::TYPE_NOT_EQUAL:
                case OpCode::TYPE_LOGICAL_XOR:
                case OpCode::TYPE_SMALLER:
                case OpCode::TYPE_GREATER:
                case OpCode::TYPE_SMALLER_OR_EQUAL:
                case OpCode::TYPE_GREATER_OR_EQUAL:
                case OpCode::TYPE_SPACESHIP:
                case OpCode::TYPE_POST_INC:
                case OpCode::TYPE_PRE_INC:
                case OpCode::TYPE_POST_DEC:
                case OpCode::TYPE_PRE_DEC:
                case OpCode::TYPE_PLUS:
                case OpCode::TYPE_MINUS:
                case OpCode::TYPE_MUL:
                case OpCode::TYPE_DIV:
                case OpCode::TYPE_MODULO:
                case OpCode::TYPE_POW:
                case OpCode::TYPE_BITWISE_AND:
                case OpCode::TYPE_BITWISE_OR:
                case OpCode::TYPE_BITWISE_XOR:
                case OpCode::TYPE_SHIFT_LEFT:
                case OpCode::TYPE_SHIFT_RIGHT:
                case OpCode::TYPE_UNARY_MINUS:
                case OpCode::TYPE_UNARY_PLUS:
                case OpCode::TYPE_BITWISE_NOT:
                case OpCode::TYPE_CONCAT:
                    $scalarOutcome = $this->executeScalarCastCompareArithConcatDispatch($frame, $op);
                    if ($scalarOutcome instanceof Frame) {
                        $frame = $scalarOutcome;
                        goto restart;
                    }
                    if (is_int($scalarOutcome)) {
                        return $scalarOutcome;
                    }
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
                    $classConstFetchOutcome = $this->executeClassConstFetchDispatch($frame, $op);
                    if ($classConstFetchOutcome instanceof Frame) {
                        $frame = $classConstFetchOutcome;
                        goto restart;
                    }
                    if (is_int($classConstFetchOutcome)) {
                        return $classConstFetchOutcome;
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
                    $staticPropFetchOutcome = $this->executeStaticPropertyFetchDispatch($frame, $op);
                    if ($staticPropFetchOutcome instanceof Frame) {
                        $frame = $staticPropFetchOutcome;
                        goto restart;
                    }
                    if (is_int($staticPropFetchOutcome)) {
                        return $staticPropFetchOutcome;
                    }
                    break;
                case OpCode::TYPE_STATIC_PROPERTY_UNSET:
                    $staticPropUnsetOutcome = $this->executeStaticPropertyUnsetDispatch($frame, $op);
                    if ($staticPropUnsetOutcome instanceof Frame) {
                        $frame = $staticPropUnsetOutcome;
                        goto restart;
                    }
                    if (is_int($staticPropUnsetOutcome)) {
                        return $staticPropUnsetOutcome;
                    }
                    break;
                case OpCode::TYPE_UNSET:
                    $unsetOutcome = $this->executeUnsetDispatch($frame, $op);
                    if ($unsetOutcome instanceof Frame) {
                        $frame = $unsetOutcome;
                        goto restart;
                    }
                    if (is_int($unsetOutcome)) {
                        return $unsetOutcome;
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
                    $funcCallInitOutcome = $this->executeFuncCallInitDispatch($frame, $op);
                    if ($funcCallInitOutcome instanceof Frame) {
                        $frame = $funcCallInitOutcome;
                        goto restart;
                    }
                    if (is_int($funcCallInitOutcome)) {
                        return $funcCallInitOutcome;
                    }
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
                    $funcCallExecOutcome = $this->executeFuncCallExecDispatch($frame, $op);
                    if ($funcCallExecOutcome instanceof Frame) {
                        $frame = $funcCallExecOutcome;
                        goto restart;
                    }
                    if (is_int($funcCallExecOutcome)) {
                        return $funcCallExecOutcome;
                    }
                    break;
                case OpCode::TYPE_ARG_RECV:
                    $argRecvOutcome = $this->executeArgRecvDispatch($frame, $op);
                    if ($argRecvOutcome instanceof Frame) {
                        $frame = $argRecvOutcome;
                        goto restart;
                    }
                    if (is_int($argRecvOutcome)) {
                        return $argRecvOutcome;
                    }
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
                    $newOutcome = $this->executeNewDispatch($frame, $op);
                    if ($newOutcome instanceof Frame) {
                        $frame = $newOutcome;
                        goto restart;
                    }
                    if (is_int($newOutcome)) {
                        return $newOutcome;
                    }
                    break;
                case OpCode::TYPE_PROPERTY_FETCH:
                case OpCode::TYPE_PROPERTY_FETCH_WRITE:
                    $propFetchOutcome = $this->executePropertyFetchDispatch($frame, $op);
                    if ($propFetchOutcome instanceof Frame) {
                        $frame = $propFetchOutcome;
                        goto restart;
                    }
                    if (is_int($propFetchOutcome)) {
                        return $propFetchOutcome;
                    }
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
                    $issetOutcome = $this->executeIssetDispatch($frame, $op);
                    if ($issetOutcome instanceof Frame) {
                        $frame = $issetOutcome;
                        goto restart;
                    }
                    if (is_int($issetOutcome)) {
                        return $issetOutcome;
                    }
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
                    $includeOutcome = $this->executeIncludeDispatch($frame, $op);
                    if ($includeOutcome instanceof Frame) {
                        $frame = $includeOutcome;
                        goto restart;
                    }
                    if (is_int($includeOutcome)) {
                        return $includeOutcome;
                    }
                    break;
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
