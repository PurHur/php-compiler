<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\BasicBlock;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * LLVM proc_open()/proc_close() helpers (php-src ext/standard/proc_open.c; #6904).
 *
 * Process handles use PROCESS_HANDLE_BASE + slot; pids stored in phpc_process_pids.
 * Pipe ends register in the shared phpc_stream_handles table (StreamIoJit).
 */
final class ProcessOpenJit
{
    public const PROCESS_HANDLE_BASE = 0x20000000;

    private const MAX_PROCESS_HANDLES = 64;

    private const MAX_STREAM_HANDLES = 256;

    private const STREAM_HANDLE_BASE = 3;

    private const GLOBAL_PIDS = 'phpc_process_pids';

    private const GLOBAL_ACTIVE = 'phpc_process_active';

    private const GLOBAL_STREAM_HANDLES = 'phpc_stream_handles';

    private const GLOBAL_STREAM_PATHS = 'phpc_stream_paths';

    private const GLOBAL_STREAM_CHUNK = 'phpc_stream_chunk_size';

    private const GLOBAL_STREAM_WBUF = 'phpc_stream_write_buffer';

    private const GLOBAL_STREAM_RBUF = 'phpc_stream_read_buffer';

    private const GLOBAL_STREAM_WAS_USED = 'phpc_stream_was_used';

    private const DEFAULT_CHUNK = 8192;

    private const EXIT_127 = 127;

    private static int $blockSuffix = 0;

    /** @var list<string> */
    private const RUNTIME_FUNCTIONS = [
        '__compiler_is_process_resource',
        '__compiler_proc_close',
        '__compiler_proc_open',
    ];

    public static function implement(Context $context): void
    {
        $restore = self::captureInsertBlock($context);

        $probe = $context->module->getNamedFunction('__compiler_proc_close');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);
            self::restoreInsertBlock($context, $restore);

            return;
        }

        self::$blockSuffix = 0;
        self::ensureGlobals($context);
        self::ensureLibc($context);
        self::ensureRuntimeHelpers($context);

        self::implementIfMissing($context, '__compiler_is_process_resource', self::emitIsProcessResource(...));
        self::implementIfMissing($context, '__compiler_proc_close', self::emitProcClose(...));
        self::implementIfMissing($context, '__compiler_proc_open', self::emitProcOpen(...));

        self::registerLinkedRuntime($context);
        self::restoreInsertBlock($context, $restore);
    }

    /**
     * @param callable(Context, LlvmFunction): void $emit
     */
    private static function implementIfMissing(Context $context, string $name, callable $emit): void
    {
        $probe = $context->module->getNamedFunction($name);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($name, $probe);

            return;
        }

        $fn = self::declareFunction($context, $name);
        $emit($context, $fn);
        $context->registerFunction($name, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function declareFunction(Context $context, string $name): LlvmFunction
    {
        try {
            return $context->lookupFunction($name);
        } catch (\Throwable) {
            // fall through
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');

        $fn = match ($name) {
            '__compiler_is_process_resource' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i64)
            ),
            '__compiler_proc_close' => $context->module->addFunction(
                $name,
                $context->context->functionType($i32, false, $i64)
            ),
            '__compiler_proc_open' => $context->module->addFunction(
                $name,
                $context->context->functionType($i64, false, $strPtr, $htPtr)
            ),
            default => throw new \LogicException('ProcessOpenJit: unknown '.$name),
        };
        $context->registerFunction($name, $fn);

        return $fn;
    }

    private static function emitIsProcessResource(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('po_is_res_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $base = $i64->constInt(self::PROCESS_HANDLE_BASE, false);
        $max = $i64->constInt(self::PROCESS_HANDLE_BASE + self::MAX_PROCESS_HANDLES, false);
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);

        $inRange = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGE, $handle, $base),
            $context->builder->icmp(Builder::INT_SLT, $handle, $max)
        );
        $falseBb = $fn->appendBasicBlock('po_is_res_false');
        $checkBb = $fn->appendBasicBlock('po_is_res_check');
        $context->builder->branchIf($inRange, $checkBb, $falseBb);

        $context->builder->positionAtEnd($checkBb);
        $slot = self::processSlotIndex($context, $handle);
        $active = self::loadActiveFlag($context, $slot);
        $isActive = $context->builder->icmp(Builder::INT_NE, $active, $i8->constInt(0, false));
        $context->builder->returnValue($context->builder->select($isActive, $oneI32, $zeroI32));

        $context->builder->positionAtEnd($falseBb);
        $context->builder->returnValue($zeroI32);
    }

    private static function emitProcClose(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('po_close_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $minusOne = $i32->constInt(-1, true);

        $isProc = $context->builder->call($context->lookupFunction('__compiler_is_process_resource'), $handle);
        $failBb = $fn->appendBasicBlock('po_close_fail');
        $workBb = $fn->appendBasicBlock('po_close_work');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $isProc, $i32->constInt(0, false)),
            $failBb,
            $workBb
        );

        $context->builder->positionAtEnd($workBb);
        $slot = self::processSlotIndex($context, $handle);
        $pid = self::loadPid($context, $slot);
        self::storeActiveFlag($context, $slot, $i8->constInt(0, false));
        self::storePid($context, $slot, $i32->constInt(0, false));

        $statusSlot = $context->builder->alloca($i32, 1, 'po_close_status');
        $waitRc = $context->builder->call(
            $context->lookupFunction('waitpid'),
            $pid,
            $statusSlot,
            $i32->constInt(0, false)
        );
        $waitFail = $context->builder->icmp(Builder::INT_EQ, $waitRc, $i32->constInt(-1, true));
        $waitFailBb = $fn->appendBasicBlock('po_close_wait_fail');
        $waitOkBb = $fn->appendBasicBlock('po_close_wait_ok');
        $context->builder->branchIf($waitFail, $waitFailBb, $waitOkBb);

        $context->builder->positionAtEnd($waitFailBb);
        $context->builder->returnValue($minusOne);

        $context->builder->positionAtEnd($waitOkBb);
        $status = $context->builder->load($statusSlot);
        $exited = $context->builder->icmp(
            Builder::INT_EQ,
            $context->builder->and($status, $i32->constInt(0xff, false)),
            $i32->constInt(0, false)
        );
        $exitOkBb = $fn->appendBasicBlock('po_close_exit_ok');
        $exitBadBb = $fn->appendBasicBlock('po_close_exit_bad');
        $context->builder->branchIf($exited, $exitOkBb, $exitBadBb);

        $context->builder->positionAtEnd($exitOkBb);
        $context->builder->returnValue(
            $context->builder->and(
                $context->builder->lShr($status, $i32->constInt(8, false)),
                $i32->constInt(0xff, false)
            )
        );

        $context->builder->positionAtEnd($exitBadBb);
        $context->builder->returnValue($i32->constInt(self::EXIT_127, false));

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($minusOne);
    }

    private static function emitProcOpen(Context $context, LlvmFunction $fn): void
    {
        $entry = $fn->appendBasicBlock('po_open_entry');
        $context->builder->positionAtEnd($entry);

        $command = $fn->getParam(0);
        $pipesHt = $fn->getParam(1);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $sizeT = $context->getTypeFromString('size_t');
        $minusOne = $i64->constInt(-1, true);
        $nullStr = $strPtr->constNull();
        $nullHt = $htPtr->constNull();

        $badArgs = $context->builder->or(
            $context->builder->icmp(Builder::INT_EQ, $command, $nullStr),
            $context->builder->icmp(Builder::INT_EQ, $pipesHt, $nullHt)
        );
        $failBb = $fn->appendBasicBlock('po_open_fail');
        $pipeBb = $fn->appendBasicBlock('po_open_pipe');
        $context->builder->branchIf($badArgs, $failBb, $pipeBb);

        $context->builder->positionAtEnd($pipeBb);
        $stdinPipe = $context->builder->alloca($i32, 2, 'po_stdin_pipe');
        $stdoutPipe = $context->builder->alloca($i32, 2, 'po_stdout_pipe');
        $stderrPipe = $context->builder->alloca($i32, 2, 'po_stderr_pipe');
        $pipeFail = $context->builder->or(
            $context->builder->icmp(Builder::INT_NE, $context->builder->call($context->lookupFunction('pipe'), $stdinPipe), $i32->constInt(0, false)),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_NE, $context->builder->call($context->lookupFunction('pipe'), $stdoutPipe), $i32->constInt(0, false)),
                $context->builder->icmp(Builder::INT_NE, $context->builder->call($context->lookupFunction('pipe'), $stderrPipe), $i32->constInt(0, false))
            )
        );
        $forkBb = $fn->appendBasicBlock('po_open_fork');
        $context->builder->branchIf($pipeFail, $failBb, $forkBb);

        $context->builder->positionAtEnd($forkBb);
        $pid = $context->builder->call($context->lookupFunction('fork'));
        $forkFail = $context->builder->icmp(Builder::INT_EQ, $pid, $i32->constInt(-1, true));
        $forkCleanup = $fn->appendBasicBlock('po_open_fork_cleanup');
        $childBb = $fn->appendBasicBlock('po_open_child');
        $parentBb = $fn->appendBasicBlock('po_open_parent');
        $forkRole = $fn->appendBasicBlock('po_open_fork_role');
        $context->builder->branchIf($forkFail, $forkCleanup, $forkRole);

        $context->builder->positionAtEnd($forkRole);
        $isChild = $context->builder->icmp(Builder::INT_EQ, $pid, $i32->constInt(0, false));
        $context->builder->branchIf($isChild, $childBb, $parentBb);

        $context->builder->positionAtEnd($forkCleanup);
        self::closePipePair($context, $stdinPipe);
        self::closePipePair($context, $stdoutPipe);
        self::closePipePair($context, $stderrPipe);
        $context->builder->branch($failBb);

        $context->builder->positionAtEnd($childBb);
        self::dupPipeEnd($context, $stdinPipe, false, 0);
        self::dupPipeEnd($context, $stdoutPipe, true, 1);
        self::dupPipeEnd($context, $stderrPipe, true, 2);
        self::closePipePair($context, $stdinPipe);
        self::closePipePair($context, $stdoutPipe);
        self::closePipePair($context, $stderrPipe);
        $context->builder->call(
            $context->lookupFunction('execl'),
            self::literalCstr($context, '/bin/sh'),
            self::literalCstr($context, 'sh'),
            self::literalCstr($context, '-c'),
            self::stringData($context, $command),
            $i8p->constNull()
        );
        $context->builder->call($context->lookupFunction('_exit'), $i32->constInt(self::EXIT_127, false));

        $context->builder->positionAtEnd($parentBb);
        self::closePipeRead($context, $stdinPipe);
        self::closePipeWrite($context, $stdoutPipe);
        self::closePipeWrite($context, $stderrPipe);

        $stdinFp = $context->builder->call(
            $context->lookupFunction('fdopen'),
            self::pipeFd($context, $stdinPipe, true),
            self::literalCstr($context, 'w')
        );
        $stdoutFp = $context->builder->call(
            $context->lookupFunction('fdopen'),
            self::pipeFd($context, $stdoutPipe, false),
            self::literalCstr($context, 'r')
        );
        $stderrFp = $context->builder->call(
            $context->lookupFunction('fdopen'),
            self::pipeFd($context, $stderrPipe, false),
            self::literalCstr($context, 'r')
        );

        $procSlotSlot = $context->builder->alloca($i64, 1, 'po_proc_slot');
        $slotLoopInit = $fn->appendBasicBlock('po_slot_loop_init');
        $slotLoopCheck = $fn->appendBasicBlock('po_slot_loop_check');
        $slotLoopBody = $fn->appendBasicBlock('po_slot_loop_body');
        $slotLoopInc = $fn->appendBasicBlock('po_slot_loop_inc');
        $slotFail = $fn->appendBasicBlock('po_open_slot_fail');
        $registerBb = $fn->appendBasicBlock('po_open_register');
        $context->builder->branch($slotLoopInit);

        $context->builder->positionAtEnd($slotLoopInit);
        $context->builder->store($i64->constInt(0, false), $procSlotSlot);
        $context->builder->branch($slotLoopCheck);

        $i8 = $context->getTypeFromString('int8');
        $context->builder->positionAtEnd($slotLoopCheck);
        $slotIdx = $context->builder->load($procSlotSlot);
        $slotAtEnd = $context->builder->icmp(
            Builder::INT_SGE,
            $slotIdx,
            $i64->constInt(self::MAX_PROCESS_HANDLES, false)
        );
        $context->builder->branchIf($slotAtEnd, $slotFail, $slotLoopBody);

        $context->builder->positionAtEnd($slotLoopBody);
        $slotActive = self::loadActiveFlag($context, $slotIdx);
        $slotFree = $context->builder->icmp(Builder::INT_EQ, $slotActive, $i8->constInt(0, false));
        $slotUse = $fn->appendBasicBlock('po_slot_use');
        $context->builder->branchIf($slotFree, $slotUse, $slotLoopInc);

        $context->builder->positionAtEnd($slotUse);
        self::storeActiveFlag($context, $slotIdx, $i8->constInt(1, false));
        self::storePid($context, $slotIdx, $pid);
        $context->builder->branch($registerBb);

        $context->builder->positionAtEnd($slotLoopInc);
        $context->builder->store(
            $context->builder->add($slotIdx, $i64->constInt(1, false)),
            $procSlotSlot
        );
        $context->builder->branch($slotLoopCheck);

        $context->builder->positionAtEnd($slotFail);
        $context->builder->call($context->lookupFunction('kill'), $pid, $i32->constInt(9, false));
        $context->builder->call(
            $context->lookupFunction('waitpid'),
            $pid,
            $i32->pointerType(0)->constNull(),
            $i32->constInt(0, false)
        );
        self::fcloseIfOpen($context, $fn, $stdinFp);
        self::fcloseIfOpen($context, $fn, $stdoutFp);
        self::fcloseIfOpen($context, $fn, $stderrFp);
        $context->builder->branch($failBb);

        $context->builder->positionAtEnd($registerBb);
        $procSlot = $context->builder->load($procSlotSlot);
        $setLong = $context->lookupFunction('__hashtable__setLongAt');
        $stdinHandle = self::registerStreamFp($context, $fn, $stdinFp, $failBb);
        $stdoutHandle = self::registerStreamFp($context, $fn, $stdoutFp, $failBb);
        $stderrHandle = self::registerStreamFp($context, $fn, $stderrFp, $failBb);
        $regFailed = $context->builder->or(
            $context->builder->icmp(Builder::INT_SLT, $stdinHandle, $i64->constInt(0, false)),
            $context->builder->or(
                $context->builder->icmp(Builder::INT_SLT, $stdoutHandle, $i64->constInt(0, false)),
                $context->builder->icmp(Builder::INT_SLT, $stderrHandle, $i64->constInt(0, false))
            )
        );
        $regOk = $fn->appendBasicBlock('po_open_reg_ok');
        $context->builder->branchIf($regFailed, $slotFail, $regOk);

        $context->builder->positionAtEnd($regOk);
        $context->builder->call($setLong, $pipesHt, $sizeT->constInt(0, false), $stdinHandle);
        $context->builder->call($setLong, $pipesHt, $sizeT->constInt(1, false), $stdoutHandle);
        $context->builder->call($setLong, $pipesHt, $sizeT->constInt(2, false), $stderrHandle);
        $context->builder->returnValue(
            $context->builder->add(
                $i64->constInt(self::PROCESS_HANDLE_BASE, false),
                $procSlot
            )
        );

        $context->builder->positionAtEnd($failBb);
        $context->builder->returnValue($minusOne);
    }

    private static function registerStreamFp(
        Context $context,
        LlvmFunction $fn,
        Value $fp,
        BasicBlock $failBb
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $nullPtr = $i8p->constNull();
        $minusOne = $i64->constInt(-1, true);
        $defaultChunk = $i32->constInt(self::DEFAULT_CHUNK, false);
        $suffix = (string) ++self::$blockSuffix;

        $resultSlot = $context->builder->alloca($i64, 1, 'po_reg_result_'.$suffix);
        $doneBb = $fn->appendBasicBlock('po_reg_done_'.$suffix);

        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $nullPtr);
        $storeFail = $fn->appendBasicBlock('po_reg_null_'.$suffix);
        $loopInit = $fn->appendBasicBlock('po_reg_init_'.$suffix);
        $context->builder->branchIf($fpNull, $storeFail, $loopInit);

        $context->builder->positionAtEnd($storeFail);
        $context->builder->store($minusOne, $resultSlot);
        $context->builder->branch($doneBb);

        $loopCheck = $fn->appendBasicBlock('po_reg_check_'.$suffix);
        $loopBody = $fn->appendBasicBlock('po_reg_body_'.$suffix);
        $loopSkip = $fn->appendBasicBlock('po_reg_skip_'.$suffix);
        $loopInc = $fn->appendBasicBlock('po_reg_inc_'.$suffix);
        $exhaust = $fn->appendBasicBlock('po_reg_exhaust_'.$suffix);

        $context->builder->positionAtEnd($loopInit);
        $context->builder->branch($loopCheck);

        $context->builder->positionAtEnd($loopCheck);
        $idPhi = $context->builder->phi($i64, 'po_reg_id_'.$suffix);
        $idPhi->addIncoming($i64->constInt(self::STREAM_HANDLE_BASE, false), $loopInit);
        $atEnd = $context->builder->icmp(Builder::INT_SGE, $idPhi, $i64->constInt(self::MAX_STREAM_HANDLES, false));
        $context->builder->branchIf($atEnd, $exhaust, $loopBody);

        $context->builder->positionAtEnd($loopBody);
        $slotFp = self::loadStreamSlot($context, $idPhi);
        $slotFree = $context->builder->icmp(Builder::INT_EQ, $slotFp, $nullPtr);
        $allocBb = $fn->appendBasicBlock('po_reg_alloc_'.$suffix);
        $context->builder->branchIf($slotFree, $allocBb, $loopSkip);

        $context->builder->positionAtEnd($allocBb);
        self::storeStreamSlot($context, self::GLOBAL_STREAM_HANDLES, $idPhi, $fp);
        self::storeStreamI32Slot($context, self::GLOBAL_STREAM_CHUNK, $idPhi, $defaultChunk);
        self::storeStreamI32Slot($context, self::GLOBAL_STREAM_WBUF, $idPhi, $defaultChunk);
        self::storeStreamI32Slot($context, self::GLOBAL_STREAM_RBUF, $idPhi, $defaultChunk);
        self::storeStreamSlot($context, self::GLOBAL_STREAM_PATHS, $idPhi, $nullPtr);
        self::storeStreamI8Flag($context, self::GLOBAL_STREAM_WAS_USED, $idPhi);
        $context->builder->store($idPhi, $resultSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($loopSkip);
        $context->builder->branch($loopInc);

        $context->builder->positionAtEnd($loopInc);
        $nextId = $context->builder->add($idPhi, $i64->constInt(1, false));
        $idPhi->addIncoming($nextId, $loopInc);
        $context->builder->branch($loopCheck);

        $context->builder->positionAtEnd($exhaust);
        $context->builder->call($context->lookupFunction('fclose'), $fp);
        $context->builder->store($minusOne, $resultSlot);
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);

        return $context->builder->load($resultSlot);
    }

    private static function fcloseIfOpen(Context $context, LlvmFunction $fn, Value $fp): void
    {
        $i8p = $context->getTypeFromString('int8*');
        $fpNull = $context->builder->icmp(Builder::INT_EQ, $fp, $i8p->constNull());
        $closeBb = $fn->appendBasicBlock('po_fclose_'.(++self::$blockSuffix));
        $afterBb = $fn->appendBasicBlock('po_fclose_done_'.self::$blockSuffix);
        $context->builder->branchIf($fpNull, $afterBb, $closeBb);

        $context->builder->positionAtEnd($closeBb);
        $context->builder->call($context->lookupFunction('fclose'), $fp);
        $context->builder->branch($afterBb);

        $context->builder->positionAtEnd($afterBb);
    }

    private static function processSlotIndex(Context $context, Value $handle): Value
    {
        $i64 = $context->getTypeFromString('int64');

        return $context->builder->sub($handle, $i64->constInt(self::PROCESS_HANDLE_BASE, false));
    }

    private static function loadActiveFlag(Context $context, Value $slot): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal(self::GLOBAL_ACTIVE);
        $ptr = $context->builder->gep($global, $zero, $slot);

        return $context->builder->load($context->builder->bitcast($ptr, $i8->pointerType(0)));
    }

    private static function storeActiveFlag(Context $context, Value $slot, Value $flag): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal(self::GLOBAL_ACTIVE);
        $ptr = $context->builder->gep($global, $zero, $slot);
        $context->builder->store($flag, $context->builder->bitcast($ptr, $i8->pointerType(0)));
    }

    private static function loadPid(Context $context, Value $slot): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal(self::GLOBAL_PIDS);
        $ptr = $context->builder->gep($global, $zero, $slot);

        return $context->builder->load($context->builder->bitcast($ptr, $i32->pointerType(0)));
    }

    private static function storePid(Context $context, Value $slot, Value $pid): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal(self::GLOBAL_PIDS);
        $ptr = $context->builder->gep($global, $zero, $slot);
        $context->builder->store($pid, $context->builder->bitcast($ptr, $i32->pointerType(0)));
    }

    private static function loadStreamSlot(Context $context, Value $handle): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal(self::GLOBAL_STREAM_HANDLES);
        $slot = $context->builder->gep($global, $zero, $handle);

        return $context->builder->load($context->builder->bitcast($slot, $i8p->pointerType(0)));
    }

    private static function storeStreamSlot(Context $context, string $globalName, Value $handle, Value $value): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal($globalName);
        $slot = $context->builder->gep($global, $zero, $handle);
        $context->builder->store($value, $context->builder->bitcast($slot, $i8p->pointerType(0)));
    }

    private static function storeStreamI32Slot(Context $context, string $globalName, Value $handle, Value $value): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i32 = $context->getTypeFromString('int32');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal($globalName);
        $slot = $context->builder->gep($global, $zero, $handle);
        $context->builder->store($value, $context->builder->bitcast($slot, $i32->pointerType(0)));
    }

    private static function storeStreamI8Flag(Context $context, string $globalName, Value $handle): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zero = $i64->constInt(0, false);
        $global = $context->module->getNamedGlobal($globalName);
        $slot = $context->builder->gep($global, $zero, $handle);
        $context->builder->store($i8->constInt(1, false), $context->builder->bitcast($slot, $i8->pointerType(0)));
    }

    private static function closePipePair(Context $context, Value $pipe): void
    {
        $close = $context->lookupFunction('close');
        $context->builder->call($close, self::pipeFd($context, $pipe, false));
        $context->builder->call($close, self::pipeFd($context, $pipe, true));
    }

    private static function closePipeRead(Context $context, Value $pipe): void
    {
        $context->builder->call($context->lookupFunction('close'), self::pipeFd($context, $pipe, false));
    }

    private static function closePipeWrite(Context $context, Value $pipe): void
    {
        $context->builder->call($context->lookupFunction('close'), self::pipeFd($context, $pipe, true));
    }

    private static function dupPipeEnd(Context $context, Value $pipe, bool $writeEnd, int $targetFd): void
    {
        $context->builder->call(
            $context->lookupFunction('dup2'),
            self::pipeFd($context, $pipe, $writeEnd),
            $context->getTypeFromString('int32')->constInt($targetFd, false)
        );
    }

    private static function pipeFd(Context $context, Value $pipe, bool $writeEnd): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $idx = $writeEnd ? 1 : 0;

        return $context->builder->load($context->builder->gep($pipe, $i32->constInt($idx, false)));
    }

    private static function stringData(Context $context, Value $str): Value
    {
        $map = $context->structFieldMap['__string__'];

        return $context->builder->pointerCast(
            $context->builder->structGep($str, $map['value']),
            $context->getTypeFromString('int8*')
        );
    }

    private static function literalCstr(Context $context, string $text): Value
    {
        $i8p = $context->getTypeFromString('int8*');

        return $context->builder->pointerCast($context->constantFromString($text), $i8p);
    }

    private static function ensureGlobals(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');

        if (null === $context->module->getNamedGlobal(self::GLOBAL_PIDS)) {
            $pidsTy = $i32->arrayType(self::MAX_PROCESS_HANDLES);
            $global = $context->module->addGlobal($pidsTy, self::GLOBAL_PIDS);
            $global->setInitializer($pidsTy->constNull());
        }
        if (null === $context->module->getNamedGlobal(self::GLOBAL_ACTIVE)) {
            $activeTy = $i8->arrayType(self::MAX_PROCESS_HANDLES);
            $global = $context->module->addGlobal($activeTy, self::GLOBAL_ACTIVE);
            $global->setInitializer($activeTy->constNull());
        }

        $ptrTableTy = $i8p->arrayType(self::MAX_STREAM_HANDLES);
        $i32TableTy = $i32->arrayType(self::MAX_STREAM_HANDLES);
        $wasUsedTy = $i8->arrayType(self::MAX_STREAM_HANDLES);
        foreach ([
            self::GLOBAL_STREAM_HANDLES => $ptrTableTy,
            self::GLOBAL_STREAM_PATHS => $ptrTableTy,
            self::GLOBAL_STREAM_CHUNK => $i32TableTy,
            self::GLOBAL_STREAM_WBUF => $i32TableTy,
            self::GLOBAL_STREAM_RBUF => $i32TableTy,
            self::GLOBAL_STREAM_WAS_USED => $wasUsedTy,
        ] as $name => $ty) {
            if (null !== $context->module->getNamedGlobal($name)) {
                continue;
            }
            $global = $context->module->addGlobal($ty, $name);
            $global->setInitializer($ty->constNull());
        }
    }

    private static function ensureLibc(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $voidTy = $context->getTypeFromString('void');
        $i32p = $i32->pointerType(0);

        foreach ([
            ['pipe', $i32, [$i32p]],
            ['fork', $i32, []],
            ['dup2', $i32, [$i32, $i32]],
            ['close', $i32, [$i32]],
            ['fdopen', $i8p, [$i32, $i8p]],
            ['fclose', $i32, [$i8p]],
            ['waitpid', $i32, [$i32, $i32p, $i32]],
            ['kill', $i32, [$i32, $i32]],
            ['execl', $i32, [$i8p, $i8p, $i8p, $i8p, $i8p]],
            ['_exit', $voidTy, [$i32]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureRuntimeHelpers(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $sizeT = $context->getTypeFromString('size_t');
        $i64 = $context->getTypeFromString('int64');
        $voidTy = $context->getTypeFromString('void');

        foreach ([
            ['__hashtable__setLongAt', $voidTy, [$htPtr, $sizeT, $i64]],
        ] as [$name, $ret, $params]) {
            self::ensureExternal($context, $name, $context->context->functionType($ret, false, ...$params));
        }
    }

    private static function ensureExternal(Context $context, string $name, $ft): void
    {
        try {
            $context->lookupFunction($name);
        } catch (\Throwable) {
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::RUNTIME_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after ProcessOpenJit implement');
            }
            $context->registerFunction($name, $fn);
        }
    }

    private static function captureInsertBlock(Context $context): ?BasicBlock
    {
        try {
            return $context->builder->getInsertBlock();
        } catch (\Throwable) {
            return null;
        }
    }

    private static function restoreInsertBlock(Context $context, ?BasicBlock $block): void
    {
        if (null !== $block) {
            $context->builder->positionAtEnd($block);

            return;
        }
        $context->builder->clearInsertionPosition();
    }
}
