<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitNestedHelperCoerce;
use PHPCompiler\JIT\NestedJitCompileScope;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT embed link for libc stream handle table + __phpc_resolve_stream (#9442).
 *
 * Mirrors {@see StreamIoJit} handle registration into PHP for lifecycle SSOT.
 * Standalone keeps LLVM globals in {@see StreamGlobalsJit}.
 */
final class StreamLibcHandleRuntime
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

    public static function emitClearLlvmHandleSlot(Context $context, Value $handle): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE === $context->loadType) {
            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $zero = $i64->constInt(0, false);
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
        $i32 = $context->getTypeFromString('int32');
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
        $stdoutGlobal = $context->module->getNamedGlobal('stdout');
        $stdoutPtr = $context->builder->load($context->builder->pointerCast($stdoutGlobal, $i8p->pointerType(0)));
        $context->builder->returnValue($stdoutPtr);

        $context->builder->positionAtEnd($afterStdout);
        $isStderr = $context->builder->icmp(Builder::INT_EQ, $handle, $two);
        $stderrBb = $fn->appendBasicBlock('resolve_embed_stderr');
        $afterStderr = $fn->appendBasicBlock('resolve_embed_after_stderr');
        $context->builder->branchIf($isStderr, $stderrBb, $afterStderr);

        $context->builder->positionAtEnd($stderrBb);
        $stderrGlobal = $context->module->getNamedGlobal('stderr');
        $stderrPtr = $context->builder->load($context->builder->pointerCast($stderrGlobal, $i8p->pointerType(0)));
        $context->builder->returnValue($stderrPtr);

        $context->builder->positionAtEnd($afterStderr);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $handle, $zero);
        $zeroBb = $fn->appendBasicBlock('resolve_embed_zero');
        $tableBb = $fn->appendBasicBlock('resolve_embed_table');
        $context->builder->branchIf($isZero, $zeroBb, $tableBb);

        $context->builder->positionAtEnd($zeroBb);
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
        $lc = \strtolower($logical);
        $fn = $context->functions[$lc] ?? null;
        if (null === $fn) {
            throw new \LogicException($logical.' missing after StreamLibcHandleJitHelper compile (#9442)');
        }

        return $fn;
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

        $runtime = $context->runtime;
        $path = \dirname(__DIR__, 3).self::HELPER_PATH;
        NestedJitCompileScope::run($context, static function () use ($context, $runtime, $path): void {
            $block = $runtime->parseAndCompile((string) \file_get_contents($path), 'StreamLibcHandleJitHelper.php');
            if (null === $block) {
                throw new \LogicException('StreamLibcHandleJitHelper.php parseAndCompile failed (#9442)');
            }
            $jit = new JIT($context);
            $jit->compile($block);
        });
        foreach (self::COMPILED_HELPERS as $logical) {
            $lc = \strtolower($logical);
            if (!isset($context->functions[$lc])) {
                throw new \LogicException($lc.' was not compiled for JIT libc handles (#9442)');
            }
        }
    }
}
