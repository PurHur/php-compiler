<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\StreamGlobalsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT embed link for libc stream handle table + __phpc_resolve_stream (#9442, #19745, #23234).
 *
 * Quarantined from lib/JIT/Builtin/StreamLibcHandleRuntime — {@see \PHPCompiler\JIT\Builtin\StreamLibcHandleRuntime}
 * stays the thin orchestrator. Mirrors {@see \PHPCompiler\JIT\Builtin\StreamIoJit} handle registration into PHP.
 * Standalone keeps LLVM globals in {@see StreamGlobalsJit}.
 *
 * Helper compile: {@see JitVmHelperLink::ensureCompiled} (peer StreamCaps #23012 / EnvLocal #23211).
 *
 * php-src: main/streams/streams.c — stream handle lookup / resolve (reference only)
 */
final class JitStreamLibcHandleKernel
{
    private const HELPER_PATH = '/ext/standard/StreamLibcHandleJitHelper.php';

    private const REGISTER = 'PHPCompiler\\ext\\standard\\StreamLibcHandleJitHelper::registerFromPtr';

    private const MARK_POPEN = 'PHPCompiler\\ext\\standard\\StreamLibcHandleJitHelper::markPopen';

    private const RESOLVE_PTR = 'PHPCompiler\\ext\\standard\\StreamLibcHandleJitHelper::resolvePtr';

    /** @var list<string> */
    private const COMPILED_HELPERS = [
        self::REGISTER,
        self::MARK_POPEN,
        self::RESOLVE_PTR,
    ];

    public static function ensureLinked(Context $context): void
    {
        self::ensureJitHelperCompiled($context);
        self::implementResolveStream($context);
    }

    public static function emitRegisterHandle(Context $context, Value $handle, Value $fpPtr): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }
        self::ensureJitHelperCompiled($context);
        $i64 = $context->getTypeFromString('int64');
        $ptrInt = $context->builder->ptrToInt($fpPtr, $i64);
        $context->builder->call(
            self::helperFunction($context, self::REGISTER),
            $context->builder->truncOrBitCast($handle, $i64),
            $ptrInt
        );
    }

    public static function emitMarkPopen(Context $context, Value $handle): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }
        self::ensureJitHelperCompiled($context);
        $i64 = $context->getTypeFromString('int64');
        $context->builder->call(
            self::helperFunction($context, self::MARK_POPEN),
            $context->builder->truncOrBitCast($handle, $i64)
        );
    }

    public static function emitClearHandle(Context $context, Value $handle): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }
        self::ensureJitHelperCompiled($context);
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $context->builder->call(
            self::helperFunction($context, self::REGISTER),
            $context->builder->truncOrBitCast($handle, $i64),
            $zero
        );
    }

    /**
     * Null {@see StreamGlobalsJit::GLOBAL_HANDLES}[handle] after fclose/pclose (#30792).
     *
     * Thin standalone AOT {@see StreamGlobalsJit::implementThinIsResource} probes these
     * LLVM slots — NestedJIT fclose alone does not clear them (#27186). Must run for
     * LOAD_TYPE_STANDALONE as well as embed.
     *
     * Clear-only: FILE* must already be closed (NestedJIT helper path). Thin AOT
     * LLVM-only slots need {@see emitLibcCloseAndClearLlvmHandleSlot} (#33426).
     */
    public static function emitClearLlvmHandleSlot(Context $context, Value $handle): void
    {
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i64->constInt(0, false);
        StreamGlobalsJit::ensureGlobals($context);
        $global = $context->module->getNamedGlobal(StreamGlobalsJit::GLOBAL_HANDLES);
        if (null === $global) {
            return;
        }
        $slot = $context->builder->gep($global, $zero, $handle);
        $context->builder->store($i8p->constNull(), $context->builder->bitcast($slot, $i8p->pointerType(0)));
    }

    /**
     * fclose(3)/pclose(3) the FILE* in the LLVM handle slot, then null it (#33426).
     *
     * Thin standalone fopen stores FILE* only in {@see StreamGlobalsJit::GLOBAL_HANDLES};
     * NestedJIT {@see StreamLifecycleJitHelper::fcloseArgv} never sees those slots and
     * returns 0 — clear-only then discards the stdio write buffer.
     *
     * @return Value i32 — fclose ABI: 1 on libc success / 0 on miss or error;
     *                     pclose ABI: wait status, or -1 when slot empty
     */
    public static function emitLibcCloseAndClearLlvmHandleSlot(
        Context $context,
        Value $handle,
        bool $pclose
    ): Value {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $zeroI64 = $i64->constInt(0, false);
        $zeroI32 = $i32->constInt(0, false);
        $oneI32 = $i32->constInt(1, false);
        $minusOne = $i32->constInt(-1, true);
        $nullPtr = $i8p->constNull();

        LibcExtern::ensureStdioFile($context);
        self::ensurePcloseDecl($context);

        StreamGlobalsJit::ensureGlobals($context);
        $global = $context->module->getNamedGlobal(StreamGlobalsJit::GLOBAL_HANDLES);
        $fn = $context->builder->getInsertBlock()->getParent();
        $missBb = $fn->appendBasicBlock($pclose ? 'llvm_pclose_miss' : 'llvm_fclose_miss');
        $closeBb = $fn->appendBasicBlock($pclose ? 'llvm_pclose_do' : 'llvm_fclose_do');
        $mergeBb = $fn->appendBasicBlock($pclose ? 'llvm_pclose_merge' : 'llvm_fclose_merge');

        if (null === $global) {
            $context->builder->branch($missBb);
            $context->builder->positionAtEnd($missBb);
            $context->builder->branch($mergeBb);
            $context->builder->positionAtEnd($closeBb);
            $context->builder->branch($mergeBb);
            $context->builder->positionAtEnd($mergeBb);
            $phi = $context->builder->phi($i32, $pclose ? 'llvm_pclose_r' : 'llvm_fclose_r');
            $phi->addIncoming($pclose ? $minusOne : $zeroI32, $missBb);
            $phi->addIncoming($pclose ? $minusOne : $zeroI32, $closeBb);

            return $phi;
        }

        $slot = $context->builder->gep($global, $zeroI64, $handle);
        $slotPtr = $context->builder->bitcast($slot, $i8p->pointerType(0));
        $fp = $context->builder->load($slotPtr);
        $hasFp = $context->builder->icmp(Builder::INT_NE, $fp, $nullPtr);
        $context->builder->branchIf($hasFp, $closeBb, $missBb);

        $context->builder->positionAtEnd($missBb);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($closeBb);
        if ($pclose) {
            $status = self::emitPcloseOrFcloseForSlot($context, $handle, $fp);
        } else {
            $rc = $context->builder->call($context->lookupFunction('fclose'), $fp);
            $status = $context->builder->select(
                $context->builder->icmp(Builder::INT_EQ, $rc, $zeroI32),
                $oneI32,
                $zeroI32
            );
        }
        $context->builder->store($nullPtr, $slotPtr);
        $popenGlobal = $context->module->getNamedGlobal('phpc_stream_is_popen');
        if (null !== $popenGlobal) {
            $popenSlot = $context->builder->gep($popenGlobal, $zeroI64, $handle);
            $context->builder->store(
                $i8->constInt(0, false),
                $context->builder->bitcast($popenSlot, $i8->pointerType(0))
            );
        }
        // Nested pclose/fclose blocks may move the insert point off $closeBb.
        $closeEnd = $context->builder->getInsertBlock();
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);
        $phi = $context->builder->phi($i32, $pclose ? 'llvm_pclose_r' : 'llvm_fclose_r');
        $phi->addIncoming($pclose ? $minusOne : $zeroI32, $missBb);
        $phi->addIncoming($status, $closeEnd);

        return $phi;
    }

    /** php-src exec.c — popen FILE* uses pclose(3); plain FILE* fclose + status 0. */
    private static function emitPcloseOrFcloseForSlot(Context $context, Value $handle, Value $fp): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $zeroI64 = $i64->constInt(0, false);
        $zeroI32 = $i32->constInt(0, false);
        $fn = $context->builder->getInsertBlock()->getParent();
        $pcloseBb = $fn->appendBasicBlock('llvm_slot_pclose');
        $fcloseBb = $fn->appendBasicBlock('llvm_slot_fclose');
        $mergeBb = $fn->appendBasicBlock('llvm_slot_close_merge');

        $isPopen = $i8->constInt(0, false);
        $popenGlobal = $context->module->getNamedGlobal('phpc_stream_is_popen');
        if (null !== $popenGlobal) {
            $popenSlot = $context->builder->gep($popenGlobal, $zeroI64, $handle);
            $isPopen = $context->builder->load($context->builder->bitcast($popenSlot, $i8->pointerType(0)));
        }
        $doPclose = $context->builder->icmp(Builder::INT_NE, $isPopen, $i8->constInt(0, false));
        $context->builder->branchIf($doPclose, $pcloseBb, $fcloseBb);

        $context->builder->positionAtEnd($pcloseBb);
        $pcloseStatus = $context->builder->call($context->lookupFunction('pclose'), $fp);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($fcloseBb);
        $context->builder->call($context->lookupFunction('fclose'), $fp);
        $context->builder->branch($mergeBb);

        $context->builder->positionAtEnd($mergeBb);
        $phi = $context->builder->phi($i32, 'llvm_slot_close_status');
        $phi->addIncoming($pcloseStatus, $pcloseBb);
        $phi->addIncoming($zeroI32, $fcloseBb);

        return $phi;
    }

    private static function ensurePcloseDecl(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        try {
            $context->lookupFunction('pclose');

            return;
        } catch (\LogicException) {
        }
        $fn = $context->module->getNamedFunction('pclose');
        if (null === $fn) {
            $fn = $context->module->addFunction(
                'pclose',
                $context->context->functionType($i32, false, $i8p)
            );
        }
        $context->registerFunction('pclose', $fn);
    }

    /**
     * After NestedJIT fclose/pclose: libc-close LLVM slots when the helper missed (#33426).
     *
     * @return Value i32 ABI result
     */
    public static function emitCloseBridgeResult(
        Context $context,
        LlvmFunction $fn,
        Value $handle,
        Value $helperI32,
        bool $pclose
    ): Value {
        $i32 = $context->getTypeFromString('int32');
        if (!$pclose) {
            $one = $i32->constInt(1, false);
            $helperOk = $context->builder->icmp(Builder::INT_EQ, $helperI32, $one);
            $okBb = $fn->appendBasicBlock('stream_fclose_helper_ok');
            $libcBb = $fn->appendBasicBlock('stream_fclose_libc');
            $mergeBb = $fn->appendBasicBlock('stream_fclose_merge');
            $context->builder->branchIf($helperOk, $okBb, $libcBb);

            $context->builder->positionAtEnd($okBb);
            self::emitClearLlvmHandleSlot($context, $handle);
            $context->builder->branch($mergeBb);

            $context->builder->positionAtEnd($libcBb);
            $libcOk = self::emitLibcCloseAndClearLlvmHandleSlot($context, $handle, false);
            $libcEnd = $context->builder->getInsertBlock();
            $context->builder->branch($mergeBb);

            $context->builder->positionAtEnd($mergeBb);
            $phi = $context->builder->phi($i32, 'stream_fclose_result');
            $phi->addIncoming($one, $okBb);
            $phi->addIncoming($libcOk, $libcEnd);

            return $phi;
        }

        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return self::emitLibcCloseAndClearLlvmHandleSlot($context, $handle, true);
        }

        self::emitClearLlvmHandleSlot($context, $handle);

        return $helperI32;
    }

    private static function implementResolveStream(Context $context): void
    {
        $abiName = '__phpc_resolve_stream';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $ft = $context->context->functionType($i8p, false, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction($abiName, $ft);

        $entry = $fn->appendBasicBlock('resolve_embed_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $nullPtr = $i8p->constNull();
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $zero = $i64->constInt(0, false);
        $max = $i64->constInt(StreamGlobalsJit::MAX_HANDLES, false);

        $isStdout = $context->builder->icmp(Builder::INT_EQ, $handle, $one);
        $stdoutBb = $fn->appendBasicBlock('resolve_embed_stdout');
        $afterStdout = $fn->appendBasicBlock('resolve_embed_after_stdout');
        $context->builder->branchIf($isStdout, $stdoutBb, $afterStdout);

        $context->builder->positionAtEnd($stdoutBb);
        // Nested helper compile can reach resolve before StreamGlobalsJit::implement (#27156).
        StreamGlobalsJit::ensureLibcStdio($context);
        $stdoutGlobal = $context->module->getNamedGlobal('stdout');
        $stdoutPtr = $context->builder->load($context->builder->pointerCast($stdoutGlobal, $i8p->pointerType(0)));
        $context->builder->returnValue($stdoutPtr);

        $context->builder->positionAtEnd($afterStdout);
        $isStderr = $context->builder->icmp(Builder::INT_EQ, $handle, $two);
        $stderrBb = $fn->appendBasicBlock('resolve_embed_stderr');
        $afterStderr = $fn->appendBasicBlock('resolve_embed_after_stderr');
        $context->builder->branchIf($isStderr, $stderrBb, $afterStderr);

        $context->builder->positionAtEnd($stderrBb);
        StreamGlobalsJit::ensureLibcStdio($context);
        $stderrGlobal = $context->module->getNamedGlobal('stderr');
        $stderrPtr = $context->builder->load($context->builder->pointerCast($stderrGlobal, $i8p->pointerType(0)));
        $context->builder->returnValue($stderrPtr);

        $context->builder->positionAtEnd($afterStderr);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $handle, $zero);
        $zeroBb = $fn->appendBasicBlock('resolve_embed_zero');
        $tableBb = $fn->appendBasicBlock('resolve_embed_table');
        $context->builder->branchIf($isZero, $zeroBb, $tableBb);

        $context->builder->positionAtEnd($zeroBb);
        StreamGlobalsJit::ensureLibcStdio($context);
        $zeroStderrGlobal = $context->module->getNamedGlobal('stderr');
        $zeroStderrPtr = $context->builder->load($context->builder->pointerCast($zeroStderrGlobal, $i8p->pointerType(0)));
        $context->builder->returnValue($zeroStderrPtr);

        $context->builder->positionAtEnd($tableBb);
        $inRange = $context->builder->and(
            $context->builder->icmp(Builder::INT_SGT, $handle, $zero),
            $context->builder->icmp(Builder::INT_SLT, $handle, $max)
        );
        $lookupBb = $fn->appendBasicBlock('resolve_embed_lookup');
        $nullBb = $fn->appendBasicBlock('resolve_embed_null');
        $context->builder->branchIf($inRange, $lookupBb, $nullBb);

        $context->builder->positionAtEnd($lookupBb);
        self::ensureJitHelperCompiled($context);
        $ptrRaw = JitNestedHelperCoerce::callHelper(
            $context,
            self::helperFunction($context, self::RESOLVE_PTR),
            [$context->builder->truncOrBitCast($handle, $i64)]
        );
        $ptrI64 = JitNestedHelperCoerce::coerceBridgeResult($context, $ptrRaw, $i64);
        $isZeroPtr = $context->builder->icmp(Builder::INT_EQ, $ptrI64, $zero);
        $foundBb = $fn->appendBasicBlock('resolve_embed_found');
        $context->builder->branchIf($isZeroPtr, $nullBb, $foundBb);

        $context->builder->positionAtEnd($foundBb);
        $context->builder->returnValue($context->builder->intToPtr($ptrI64, $i8p));

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnValue($nullPtr);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function helperFunction(Context $context, string $logical): LlvmFunction
    {
        self::ensureJitHelperCompiled($context);

        return JitVmHelperLink::lookupCompiled($context, $logical, '#23234');
    }

    private static function ensureJitHelperCompiled(Context $context): void
    {
        $missing = false;
        foreach (self::COMPILED_HELPERS as $logical) {
            if (!isset($context->functions[\strtolower($logical)])) {
                $missing = true;
                break;
            }
        }
        if (!$missing) {
            return;
        }

        JitVmHelperLink::ensureCompiled(
            $context,
            self::HELPER_PATH,
            self::COMPILED_HELPERS,
            '#23234'
        );
    }
}
