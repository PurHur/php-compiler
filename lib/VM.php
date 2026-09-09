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
require_once __DIR__.'/VM/Concern/ScalarCompareDispatch.php';
require_once __DIR__.'/VM/Concern/ScalarArithBitwiseUnaryDispatch.php';
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
require_once __DIR__.'/VM/Concern/TryCatchThrowDispatch.php';
require_once __DIR__.'/VM/Concern/CloneDispatch.php';
require_once __DIR__.'/VM/Concern/FuncDefAndGlobalConstDispatch.php';
require_once __DIR__.'/VM/Concern/ScriptMagicAndTickDispatch.php';
require_once __DIR__.'/VM/Concern/ReturnDispatch.php';
require_once __DIR__.'/VM/Concern/ClassInstanceAndStaticCallSupport.php';
require_once __DIR__.'/VM/Concern/RunFramesInner.php';

use PHPCompiler\BuiltinByRefParams;
use PHPCompiler\Compiler\AttributeNames;
use PHPCompiler\Compiler\NoDiscardMetadata;
use PHPCompiler\Compiler\SourceLocation;
use PHPCompiler\Func;
use PHPCompiler\ext\standard\VmForwardStaticCall;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\VM\ForeachIterator;
use PHPCompiler\VM\Context;
use PHPCompiler\VM\CastSupport;
use PHPCompiler\VM\DnfCheck;
use PHPCompiler\VM\CallableCheck;
use PHPCompiler\VM\CycleCollector;
use PHPCompiler\VM\EnumCaseSupport;
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
    use ScalarCompareDispatch;
    use ScalarArithBitwiseUnaryDispatch;
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
    use TryCatchThrowDispatch;
    use CloneDispatch;
    use FuncDefAndGlobalConstDispatch;
    use ScriptMagicAndTickDispatch;
    use ReturnDispatch;
    use ClassInstanceAndStaticCallSupport;
    use RunFramesInner;
    const SUCCESS = 1;
    const FAILURE = 2;
    /** Sentinel from {@see ReturnDispatch}: caller must `goto nextframe`. */
    const RETURN_NEXTFRAME = 5;

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

    protected function scopeSlot(Frame $frame, int $slot): Variable
    {
        if (!isset($frame->scope[$slot])) {
            $frame->scope[$slot] = new Variable();
        }

        return $frame->scope[$slot];
    }

}
