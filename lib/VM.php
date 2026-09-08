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
require_once __DIR__.'/VM/Concern/ArrayInitSpreadDispatch.php';
require_once __DIR__.'/VM/Concern/StaticPropertyFetchDispatch.php';
require_once __DIR__.'/VM/Concern/UnsetDispatch.php';
require_once __DIR__.'/VM/Concern/AssignDispatch.php';
require_once __DIR__.'/VM/Concern/FuncCallExecDispatch.php';
require_once __DIR__.'/VM/Concern/ArgRecvDispatch.php';
require_once __DIR__.'/VM/Concern/ScalarCastDispatch.php';
require_once __DIR__.'/VM/Concern/ScalarCastCompareArithConcatDispatch.php';
require_once __DIR__.'/VM/Concern/ClassConstFetchDispatch.php';
require_once __DIR__.'/VM/Concern/IssetDispatch.php';
require_once __DIR__.'/VM/Concern/IncludeDispatch.php';
require_once __DIR__.'/VM/Concern/FuncCallInitDispatch.php';
require_once __DIR__.'/VM/Concern/NewDispatch.php';
require_once __DIR__.'/VM/Concern/MethodCallInitDispatch.php';
require_once __DIR__.'/VM/Concern/EchoPrintEvalDispatch.php';
require_once __DIR__.'/VM/Concern/ForeachIterDispatch.php';
require_once __DIR__.'/VM/Concern/CoalesceNullsafeSilenceExitDispatch.php';
require_once __DIR__.'/VM/Concern/JumpCaseDispatch.php';
require_once __DIR__.'/VM/Concern/ConstFetchStaticCallInstanceofDispatch.php';
require_once __DIR__.'/VM/Concern/DeclareClassLikeDispatch.php';
require_once __DIR__.'/VM/Concern/ArgSendDispatch.php';
require_once __DIR__.'/VM/Concern/VarFetchGlobalAndFunctionStaticDispatch.php';
require_once __DIR__.'/VM/Concern/ListUnpackAndSpreadAssignDispatch.php';
require_once __DIR__.'/VM/Concern/FromCallableAndClosureDispatch.php';
require_once __DIR__.'/VM/Concern/EmptyAndBooleanNotDispatch.php';
require_once __DIR__.'/VM/Concern/YieldAndYieldFromDispatch.php';

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
use PHPCompiler\VM\CallableCheck;
use PHPCompiler\VM\CycleCollector;
use PHPCompiler\VM\EnumCaseSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\FiberState;
use PHPCompiler\VM\GeneratorState;
use PHPCompiler\VM\HashTable;
use PHPCompiler\VM\IterableCheck;
use PHPCompiler\VM\NamedArgs;
use PHPCompiler\VM\ObjectEntry;
use PHPCompiler\VM\ObjectLifetime;
use PHPCompiler\VM\ReferencableCheck;
use PHPCompiler\VM\ReflectionPropertyHookSupport;
use PHPCompiler\VM\ScriptExit;
use PHPCompiler\VM\TypeCheck;
use PHPCompiler\VM\TraitCompositionConflictMessage;
use PHPCompiler\VM\TypedPropertyReadSignal;
use PHPCompiler\VM\VmIncDec;
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
    use ArrayInitSpreadDispatch;
    use StaticPropertyFetchDispatch;
    use UnsetDispatch;
    use AssignDispatch;
    use FuncCallExecDispatch;
    use ArgRecvDispatch;
    use ScalarCastDispatch;
    use ScalarCastCompareArithConcatDispatch;
    use ClassConstFetchDispatch;
    use IssetDispatch;
    use IncludeDispatch;
    use FuncCallInitDispatch;
    use NewDispatch;
    use MethodCallInitDispatch;
    use EchoPrintEvalDispatch;
    use ForeachIterDispatch;
    use CoalesceNullsafeSilenceExitDispatch;
    use JumpCaseDispatch;
    use ConstFetchStaticCallInstanceofDispatch;
    use DeclareClassLikeDispatch;
    use ArgSendDispatch;
    use VarFetchGlobalAndFunctionStaticDispatch;
    use ListUnpackAndSpreadAssignDispatch;
    use FromCallableAndClosureDispatch;
    use EmptyAndBooleanNotDispatch;
    use YieldAndYieldFromDispatch;
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
                case OpCode::TYPE_DECLARE_GLOBAL:
                case OpCode::TYPE_DECLARE_FUNCTION_STATIC:
                case OpCode::TYPE_JUMPIF_FUNCTION_STATIC_INITIALIZED:
                case OpCode::TYPE_FUNCTION_STATIC_INIT_STORE:
                    $varFetchGlobalStaticOutcome = $this->executeVarFetchGlobalAndFunctionStaticDispatch($frame, $op);
                    if ($varFetchGlobalStaticOutcome instanceof Frame) {
                        $frame = $varFetchGlobalStaticOutcome;
                        goto restart;
                    }
                    if (is_int($varFetchGlobalStaticOutcome)) {
                        return $varFetchGlobalStaticOutcome;
                    }
                    break;
                case OpCode::TYPE_LIST_UNPACK_CHECK:
                case OpCode::TYPE_LIST_SPREAD_ASSIGN:
                    $listUnpackSpreadOutcome = $this->executeListUnpackAndSpreadAssignDispatch($frame, $op);
                    if ($listUnpackSpreadOutcome instanceof Frame) {
                        $frame = $listUnpackSpreadOutcome;
                        goto restart;
                    }
                    if (is_int($listUnpackSpreadOutcome)) {
                        return $listUnpackSpreadOutcome;
                    }
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
                    $castOutcome = $this->executeScalarCastDispatch($frame, $op);
                    if ($castOutcome instanceof Frame) {
                        $frame = $castOutcome;
                        goto restart;
                    }
                    if (is_int($castOutcome)) {
                        return $castOutcome;
                    }
                    break;
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
                    $echoOutcome = $this->executeEchoDispatch($frame, $op);
                    if ($echoOutcome instanceof Frame) {
                        $frame = $echoOutcome;
                        goto restart;
                    }
                    if (is_int($echoOutcome)) {
                        return $echoOutcome;
                    }
                    break;
                case OpCode::TYPE_PRINT:
                    $printOutcome = $this->executePrintDispatch($frame, $op);
                    if ($printOutcome instanceof Frame) {
                        $frame = $printOutcome;
                        goto restart;
                    }
                    if (is_int($printOutcome)) {
                        return $printOutcome;
                    }
                    break;
                case OpCode::TYPE_EVAL:
                    $evalOutcome = $this->executeEvalDispatch($frame, $op);
                    if ($evalOutcome instanceof Frame) {
                        $frame = $evalOutcome;
                        goto restart;
                    }
                    if (is_int($evalOutcome)) {
                        return $evalOutcome;
                    }
                    break;
                case OpCode::TYPE_COALESCE:
                    $coalesceOutcome = $this->executeCoalesceDispatch($frame, $op);
                    if ($coalesceOutcome instanceof Frame) {
                        $frame = $coalesceOutcome;
                        goto restart;
                    }
                    if (is_int($coalesceOutcome)) {
                        return $coalesceOutcome;
                    }
                    break;
                case OpCode::TYPE_NULLSAFE:
                    $nullsafeOutcome = $this->executeNullsafeDispatch($frame, $op);
                    if ($nullsafeOutcome instanceof Frame) {
                        $frame = $nullsafeOutcome;
                        goto restart;
                    }
                    if (is_int($nullsafeOutcome)) {
                        return $nullsafeOutcome;
                    }
                    break;
                case OpCode::TYPE_BEGIN_SILENCE:
                    $this->executeBeginSilenceDispatch($frame, $op);
                    break;
                case OpCode::TYPE_END_SILENCE:
                    $this->executeEndSilenceDispatch($frame, $op);
                    break;
                case OpCode::TYPE_EXIT:
                    $exitOutcome = $this->executeExitDispatch($frame, $op);
                    if ($exitOutcome instanceof Frame) {
                        $frame = $exitOutcome;
                        goto restart;
                    }
                    if (is_int($exitOutcome)) {
                        return $exitOutcome;
                    }
                    break;
                case OpCode::TYPE_JUMP:
                    $jumpOutcome = $this->executeJumpDispatch($frame, $op);
                    if ($jumpOutcome instanceof Frame) {
                        $frame = $jumpOutcome;
                        goto restart;
                    }
                    if (is_int($jumpOutcome)) {
                        return $jumpOutcome;
                    }
                    break;
                case OpCode::TYPE_JUMPIF:
                    $jumpIfOutcome = $this->executeJumpIfDispatch($frame, $op);
                    if ($jumpIfOutcome instanceof Frame) {
                        $frame = $jumpIfOutcome;
                        goto restart;
                    }
                    if (is_int($jumpIfOutcome)) {
                        return $jumpIfOutcome;
                    }
                    break;
                case OpCode::TYPE_CASE:
                    $caseOutcome = $this->executeCaseDispatch($frame, $op);
                    if ($caseOutcome instanceof Frame) {
                        $frame = $caseOutcome;
                        goto restart;
                    }
                    if (is_int($caseOutcome)) {
                        return $caseOutcome;
                    }
                    break;
                case OpCode::TYPE_CONST_FETCH:
                    $constFetchOutcome = $this->executeConstFetchDispatch($frame, $op);
                    if ($constFetchOutcome instanceof Frame) {
                        $frame = $constFetchOutcome;
                        goto restart;
                    }
                    if (is_int($constFetchOutcome)) {
                        return $constFetchOutcome;
                    }
                    break;
                case OpCode::TYPE_STATICCALL_INIT:
                    $staticCallInitOutcome = $this->executeStaticCallInitDispatch($frame, $op);
                    if ($staticCallInitOutcome instanceof Frame) {
                        $frame = $staticCallInitOutcome;
                        goto restart;
                    }
                    if (is_int($staticCallInitOutcome)) {
                        return $staticCallInitOutcome;
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
                    $instanceofOutcome = $this->executeInstanceofDispatch($frame, $op);
                    if ($instanceofOutcome instanceof Frame) {
                        $frame = $instanceofOutcome;
                        goto restart;
                    }
                    if (is_int($instanceofOutcome)) {
                        return $instanceofOutcome;
                    }
                    break;
                case OpCode::TYPE_IN:
                    $inOutcome = $this->executeInDispatch($frame, $op);
                    if ($inOutcome instanceof Frame) {
                        $frame = $inOutcome;
                        goto restart;
                    }
                    if (is_int($inOutcome)) {
                        return $inOutcome;
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
                case OpCode::TYPE_CLOSURE:
                    $fromCallableClosureOutcome = $this->executeFromCallableAndClosureDispatch($frame, $op);
                    if ($fromCallableClosureOutcome instanceof Frame) {
                        $frame = $fromCallableClosureOutcome;
                        goto restart;
                    }
                    if (is_int($fromCallableClosureOutcome)) {
                        return $fromCallableClosureOutcome;
                    }
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
                    $methodCallInitOutcome = $this->executeMethodCallInitDispatch($frame, $op);
                    if ($methodCallInitOutcome instanceof Frame) {
                        $frame = $methodCallInitOutcome;
                        goto restart;
                    }
                    if (is_int($methodCallInitOutcome)) {
                        return $methodCallInitOutcome;
                    }
                    break;
                case OpCode::TYPE_ARG_SEND:
                    $argSendOutcome = $this->executeArgSendDispatch($frame, $op);
                    if ($argSendOutcome instanceof Frame) {
                        $frame = $argSendOutcome;
                        goto restart;
                    }
                    if (is_int($argSendOutcome)) {
                        return $argSendOutcome;
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
                    $declareIfaceOutcome = $this->executeDeclareInterfaceDispatch($frame, $op);
                    if ($declareIfaceOutcome instanceof Frame) {
                        $frame = $declareIfaceOutcome;
                        goto restart;
                    }
                    if (is_int($declareIfaceOutcome)) {
                        return $declareIfaceOutcome;
                    }
                    break;
                case OpCode::TYPE_DECLARE_TRAIT:
                    $declareTraitOutcome = $this->executeDeclareTraitDispatch($frame, $op);
                    if ($declareTraitOutcome instanceof Frame) {
                        $frame = $declareTraitOutcome;
                        goto restart;
                    }
                    if (is_int($declareTraitOutcome)) {
                        return $declareTraitOutcome;
                    }
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
                    $declareEnumOutcome = $this->executeDeclareEnumDispatch($frame, $op);
                    if ($declareEnumOutcome instanceof Frame) {
                        $frame = $declareEnumOutcome;
                        goto restart;
                    }
                    if (is_int($declareEnumOutcome)) {
                        return $declareEnumOutcome;
                    }
                    break;
                case OpCode::TYPE_DECLARE_CLASS:
                    $declareClassOutcome = $this->executeDeclareClassDispatch($frame, $op);
                    if ($declareClassOutcome instanceof Frame) {
                        $frame = $declareClassOutcome;
                        goto restart;
                    }
                    if (is_int($declareClassOutcome)) {
                        return $declareClassOutcome;
                    }
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
                case OpCode::TYPE_ADD_ARRAY_ELEMENT:
                case OpCode::TYPE_ARRAY_SPREAD:
                    $arrayInitSpreadOutcome = $this->executeArrayInitSpreadDispatch($frame, $op);
                    if ($arrayInitSpreadOutcome instanceof Frame) {
                        $frame = $arrayInitSpreadOutcome;
                        goto restart;
                    }
                    if (is_int($arrayInitSpreadOutcome)) {
                        return $arrayInitSpreadOutcome;
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
                case OpCode::TYPE_EMPTY:
                case OpCode::TYPE_EMPTY_OBJECT_PROPERTY:
                case OpCode::TYPE_EMPTY_STATIC_PROPERTY:
                case OpCode::TYPE_EMPTY_DIMENSION:
                    $emptyBoolOutcome = $this->executeEmptyAndBooleanNotDispatch($frame, $op);
                    if ($emptyBoolOutcome instanceof Frame) {
                        $frame = $emptyBoolOutcome;
                        goto restart;
                    }
                    if (is_int($emptyBoolOutcome)) {
                        return $emptyBoolOutcome;
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
                case OpCode::TYPE_YIELD_FROM:
                    $yieldOutcome = $this->executeYieldAndYieldFromDispatch($frame, $op);
                    if ($yieldOutcome instanceof Frame) {
                        $frame = $yieldOutcome;
                        goto restart;
                    }
                    if (is_int($yieldOutcome)) {
                        return $yieldOutcome;
                    }
                    break;
                case OpCode::TYPE_ITER_RESET:
                    $iterResetOutcome = $this->executeIterResetDispatch($frame, $op);
                    if ($iterResetOutcome instanceof Frame) {
                        $frame = $iterResetOutcome;
                        goto restart;
                    }
                    if (is_int($iterResetOutcome)) {
                        return $iterResetOutcome;
                    }
                    break;
                case OpCode::TYPE_ITER_VALID:
                    $iterValidOutcome = $this->executeIterValidDispatch($frame, $op);
                    if ($iterValidOutcome instanceof Frame) {
                        $frame = $iterValidOutcome;
                        goto restart;
                    }
                    if (is_int($iterValidOutcome)) {
                        return $iterValidOutcome;
                    }
                    break;
                case OpCode::TYPE_ITER_KEY:
                    $iterKeyOutcome = $this->executeIterKeyDispatch($frame, $op);
                    if ($iterKeyOutcome instanceof Frame) {
                        $frame = $iterKeyOutcome;
                        goto restart;
                    }
                    if (is_int($iterKeyOutcome)) {
                        return $iterKeyOutcome;
                    }
                    break;
                case OpCode::TYPE_ITER_VALUE:
                    $iterValueOutcome = $this->executeIterValueDispatch($frame, $op);
                    if ($iterValueOutcome instanceof Frame) {
                        $frame = $iterValueOutcome;
                        goto restart;
                    }
                    if (is_int($iterValueOutcome)) {
                        return $iterValueOutcome;
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
