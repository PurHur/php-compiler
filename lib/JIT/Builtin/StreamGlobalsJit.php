<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;
use PHPLLVM\Builder;
/**
 * LLVM stream handle-table globals + __phpc_resolve_stream (#5343 phase 5).
 *
 * Replaces lib/AOT/runtime/phpc_stream.c — last C TU for stream registry.
 * php-src: main/streams/streams.c (handle lookup semantics reference only).
 */
final class StreamGlobalsJit
{
    public const MAX_HANDLES = 256;

    public const GLOBAL_HANDLES = 'phpc_stream_handles';

    public const GLOBAL_PATHS = 'phpc_stream_paths';

    public const GLOBAL_WAS_USED = 'phpc_stream_was_used';

    public const GLOBAL_CHUNK_SIZE = 'phpc_stream_chunk_size';

    public const GLOBAL_WRITE_BUFFER = 'phpc_stream_write_buffer';

    public const GLOBAL_READ_BUFFER = 'phpc_stream_read_buffer';

    public const GLOBAL_WRITE_BUFFER_STORAGE = 'phpc_stream_write_buffer_storage';

    public static function implement(Context $context): void
    {
        self::ensureGlobals($context);
        self::ensureLibcStdio($context);
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            StreamLibcHandleRuntime::ensureLinked($context);

            return;
        }
        self::implementResolveStream($context);
    }

    public static function ensureGlobals(Context $context): void
    {
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $i32 = $context->getTypeFromString('int32');
        $ptrTableTy = $i8p->arrayType(self::MAX_HANDLES);
        $i32TableTy = $i32->arrayType(self::MAX_HANDLES);
        $wasUsedTy = $i8->arrayType(self::MAX_HANDLES);
        $storageTy = $i8->arrayType(8192)->arrayType(self::MAX_HANDLES);

        $isPopenTy = $i8->arrayType(self::MAX_HANDLES);
        $isGzTy = $i8->arrayType(self::MAX_HANDLES);
        foreach ([
            self::GLOBAL_HANDLES => $ptrTableTy,
            self::GLOBAL_PATHS => $ptrTableTy,
            self::GLOBAL_CHUNK_SIZE => $i32TableTy,
            self::GLOBAL_WRITE_BUFFER => $i32TableTy,
            self::GLOBAL_READ_BUFFER => $i32TableTy,
            self::GLOBAL_WAS_USED => $wasUsedTy,
            self::GLOBAL_WRITE_BUFFER_STORAGE => $storageTy,
            'phpc_stream_is_popen' => $isPopenTy,
            'phpc_stream_is_gz' => $isGzTy,
        ] as $name => $ty) {
            $global = $context->module->getNamedGlobal($name);
            if (null === $global) {
                $global = $context->module->addGlobal($ty, $name);
            }
            $global->setInitializer($ty->constNull());
        }
    }

    private static function ensureLibcStdio(Context $context): void
    {
        $i8p = $context->getTypeFromString('int8*');
        foreach (['stdout', 'stderr'] as $name) {
            if (null === $context->module->getNamedGlobal($name)) {
                $context->module->addGlobal($i8p, $name);
            }
        }
    }

    private static function implementResolveStream(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__phpc_resolve_stream');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction('__phpc_resolve_stream', $probe);

            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $ft = $context->context->functionType($i8p, false, $i64);
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction('__phpc_resolve_stream', $ft);
        $context->registerFunction('__phpc_resolve_stream', $fn);

        $entry = $fn->appendBasicBlock('resolve_entry');
        $context->builder->positionAtEnd($entry);

        $handle = $fn->getParam(0);
        $nullPtr = $i8p->constNull();
        $one = $i64->constInt(1, false);
        $two = $i64->constInt(2, false);
        $zero = $i64->constInt(0, false);
        $max = $i64->constInt(self::MAX_HANDLES, false);

        $isStdout = $context->builder->icmp(Builder::INT_EQ, $handle, $one);
        $stdoutBb = $fn->appendBasicBlock('resolve_stdout');
        $afterStdout = $fn->appendBasicBlock('resolve_after_stdout');
        $context->builder->branchIf($isStdout, $stdoutBb, $afterStdout);

        $context->builder->positionAtEnd($stdoutBb);
        $stdoutGlobal = $context->module->getNamedGlobal('stdout');
        $stdoutPtr = $context->builder->load($context->builder->pointerCast($stdoutGlobal, $i8p->pointerType(0)));
        $context->builder->returnValue($stdoutPtr);

        $context->builder->positionAtEnd($afterStdout);
        $isStderr = $context->builder->icmp(Builder::INT_EQ, $handle, $two);
        $stderrBb = $fn->appendBasicBlock('resolve_stderr');
        $afterStderr = $fn->appendBasicBlock('resolve_after_stderr');
        $context->builder->branchIf($isStderr, $stderrBb, $afterStderr);

        $context->builder->positionAtEnd($stderrBb);
        $stderrGlobal = $context->module->getNamedGlobal('stderr');
        $stderrPtr = $context->builder->load($context->builder->pointerCast($stderrGlobal, $i8p->pointerType(0)));
        $context->builder->returnValue($stderrPtr);

        $context->builder->positionAtEnd($afterStderr);
        $isZero = $context->builder->icmp(Builder::INT_EQ, $handle, $zero);
        $zeroBb = $fn->appendBasicBlock('resolve_zero');
        $tableBb = $fn->appendBasicBlock('resolve_table');
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
        $lookupBb = $fn->appendBasicBlock('resolve_lookup');
        $nullBb = $fn->appendBasicBlock('resolve_null');
        $context->builder->branchIf($inRange, $lookupBb, $nullBb);

        $context->builder->positionAtEnd($lookupBb);
        $handles = $context->module->getNamedGlobal(self::GLOBAL_HANDLES);
        $zeroI64 = $i64->constInt(0, false);
        $slot = $context->builder->gep($handles, $zeroI64, $handle);
        $loaded = $context->builder->load($context->builder->bitcast($slot, $i8p->pointerType(0)));
        $hasFp = $context->builder->icmp(Builder::INT_NE, $loaded, $nullPtr);
        $foundBb = $fn->appendBasicBlock('resolve_found');
        $context->builder->branchIf($hasFp, $foundBb, $nullBb);

        $context->builder->positionAtEnd($foundBb);
        $context->builder->returnValue($loaded);

        $context->builder->positionAtEnd($nullBb);
        $context->builder->returnValue($nullPtr);
        $context->builder->clearInsertionPosition();
    }
}
