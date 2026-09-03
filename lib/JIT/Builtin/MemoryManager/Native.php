<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin\MemoryManager;

use PHPCompiler\JIT\Builtin\MemoryManager;
use PHPCompiler\JIT\Builtin\MemoryRuntime;
use PHPLLVM;

/**
 * Standalone AOT allocator — libc malloc/realloc/free with emalloc-style counters
 * for honest memory_get_usage(false) (#36388).
 *
 * php-src: Zend/zend_alloc.c — AG(mm_heap)->size backs memory_get_usage(false).
 * Thin C ABI stays unmodified pointers (malloc/free) so prelinked helper-runtime
 * objects that inlined the prior passthrough `__mm__*` remain ABI-compatible.
 * Size for free/realloc accounting comes from glibc `malloc_usable_size` (same
 * family as Zend's chunk size introspection) — one sentence of proof that no
 * new runtime/*.c is required.
 *
 * Hand-maintained (not regenerated from Native.pre).
 */
class Native extends MemoryManager
{
    public function register(): void
    {
        parent::register();
        // malloc/realloc/free lazy via Context::lookupFunction (#36100 / peer #32273 #36074).
        // __mm__* implement() is the first lookup on thin hello-world AOT.
        $this->ensureMallocUsableSizeDecl();
    }

    public function implement(): void
    {
        $i8p = $this->context->getTypeFromString('int8*');
        $sizeT = $this->context->getTypeFromString('size_t');
        $i64 = $this->context->getTypeFromString('int64');
        $voidTy = $this->context->getTypeFromString('void');

        MemoryRuntime::ensureEmallocGlobals($this->context);
        $this->ensureMallocUsableSizeDecl();

        $this->implementMalloc($i8p, $sizeT, $i64);
        $this->implementRealloc($i8p, $sizeT, $i64);
        $this->implementFree($i8p, $sizeT, $i64);
        MemoryRuntime::implementEmallocQueryBridges($this->context, $i64, $voidTy);
    }

    private function ensureMallocUsableSizeDecl(): void
    {
        if (null !== $this->context->module->getNamedFunction('malloc_usable_size')) {
            $fn = $this->context->module->getNamedFunction('malloc_usable_size');
            $this->context->registerFunction('malloc_usable_size', $fn);

            return;
        }
        $sizeT = $this->context->getTypeFromString('size_t');
        $i8p = $this->context->getTypeFromString('int8*');
        $ft = $this->context->context->functionType($sizeT, false, $i8p);
        $fn = $this->context->module->addFunction('malloc_usable_size', $ft);
        $this->context->registerFunction('malloc_usable_size', $fn);
    }

    private function implementMalloc(PHPLLVM\Type $i8p, PHPLLVM\Type $sizeT, PHPLLVM\Type $i64): void
    {
        $fn = $this->context->lookupFunction('__mm__malloc');
        $entry = $fn->appendBasicBlock('mm_malloc_entry');
        $oom = $fn->appendBasicBlock('mm_malloc_oom');
        $ok = $fn->appendBasicBlock('mm_malloc_ok');
        $this->context->builder->positionAtEnd($entry);

        $size = $fn->getParam(0);
        $raw = $this->context->builder->call(
            $this->context->lookupFunction('malloc'),
            $size
        );
        $isNull = $this->context->builder->icmp(
            PHPLLVM\Builder::INT_EQ,
            $raw,
            $i8p->constNull()
        );
        $this->context->builder->branchIf($isNull, $oom, $ok);

        $this->context->builder->positionAtEnd($oom);
        $this->context->builder->returnValue($i8p->constNull());

        $this->context->builder->positionAtEnd($ok);
        $usable = $this->context->builder->call(
            $this->context->lookupFunction('malloc_usable_size'),
            $raw
        );
        $usableI64 = $this->context->builder->intCast($usable, $i64);
        MemoryRuntime::emitNoteEmallocDelta($this->context, $usableI64);
        $this->context->builder->returnValue($raw);
        $this->context->builder->clearInsertionPosition();
    }

    private function implementRealloc(PHPLLVM\Type $i8p, PHPLLVM\Type $sizeT, PHPLLVM\Type $i64): void
    {
        $fn = $this->context->lookupFunction('__mm__realloc');
        $entry = $fn->appendBasicBlock('mm_realloc_entry');
        $viaMalloc = $fn->appendBasicBlock('mm_realloc_via_malloc');
        $body = $fn->appendBasicBlock('mm_realloc_body');
        $oom = $fn->appendBasicBlock('mm_realloc_oom');
        $ok = $fn->appendBasicBlock('mm_realloc_ok');
        $this->context->builder->positionAtEnd($entry);

        $ptr = $fn->getParam(0);
        $size = $fn->getParam(1);
        $isNull = $this->context->builder->icmp(
            PHPLLVM\Builder::INT_EQ,
            $ptr,
            $i8p->constNull()
        );
        $this->context->builder->branchIf($isNull, $viaMalloc, $body);

        $this->context->builder->positionAtEnd($viaMalloc);
        $fresh = $this->context->builder->call(
            $this->context->lookupFunction('__mm__malloc'),
            $size
        );
        $this->context->builder->returnValue($fresh);

        $this->context->builder->positionAtEnd($body);
        $oldUsable = $this->context->builder->call(
            $this->context->lookupFunction('malloc_usable_size'),
            $ptr
        );
        $oldI64 = $this->context->builder->intCast($oldUsable, $i64);
        $newRaw = $this->context->builder->call(
            $this->context->lookupFunction('realloc'),
            $ptr,
            $size
        );
        $newIsNull = $this->context->builder->icmp(
            PHPLLVM\Builder::INT_EQ,
            $newRaw,
            $i8p->constNull()
        );
        $this->context->builder->branchIf($newIsNull, $oom, $ok);

        $this->context->builder->positionAtEnd($oom);
        // realloc failed — old block still live; undo nothing (oldUsable still counted).
        $this->context->builder->returnValue($i8p->constNull());

        $this->context->builder->positionAtEnd($ok);
        $newUsable = $this->context->builder->call(
            $this->context->lookupFunction('malloc_usable_size'),
            $newRaw
        );
        $newI64 = $this->context->builder->intCast($newUsable, $i64);
        $delta = $this->context->builder->sub($newI64, $oldI64);
        MemoryRuntime::emitNoteEmallocDelta($this->context, $delta);
        $this->context->builder->returnValue($newRaw);
        $this->context->builder->clearInsertionPosition();
    }

    private function implementFree(PHPLLVM\Type $i8p, PHPLLVM\Type $sizeT, PHPLLVM\Type $i64): void
    {
        $fn = $this->context->lookupFunction('__mm__free');
        $entry = $fn->appendBasicBlock('mm_free_entry');
        $done = $fn->appendBasicBlock('mm_free_done');
        $body = $fn->appendBasicBlock('mm_free_body');
        $this->context->builder->positionAtEnd($entry);

        $ptr = $fn->getParam(0);
        $isNull = $this->context->builder->icmp(
            PHPLLVM\Builder::INT_EQ,
            $ptr,
            $i8p->constNull()
        );
        $this->context->builder->branchIf($isNull, $done, $body);

        $this->context->builder->positionAtEnd($body);
        $usable = $this->context->builder->call(
            $this->context->lookupFunction('malloc_usable_size'),
            $ptr
        );
        $usableI64 = $this->context->builder->intCast($usable, $i64);
        $neg = $this->context->builder->sub($i64->constInt(0, false), $usableI64);
        MemoryRuntime::emitNoteEmallocDelta($this->context, $neg);
        $this->context->builder->call(
            $this->context->lookupFunction('free'),
            $ptr
        );
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($done);
        $this->context->builder->returnVoid();
        $this->context->builder->clearInsertionPosition();
    }
}
