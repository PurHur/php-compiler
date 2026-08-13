<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Builtin\StreamGlobalsJit;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\JitVmHelperLink;
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
