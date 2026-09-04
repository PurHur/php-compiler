<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin\MemoryManager;

use PHPCompiler\JIT\Builtin\MemoryManager;
use PHPCompiler\JIT\Builtin\MemoryRuntime;
use PHPLLVM;

/**
 * Standalone AOT allocator — request bump-arena + emalloc counters (#36388).
 *
 * php-src: Zend/zend_alloc.c — per-request heap; AG(mm_heap)->size for
 * memory_get_usage(false); php_request_shutdown frees the request arena.
 *
 * Arena-active path bump-allocates from libc chunks (8-byte size prefix);
 * `__mm__free` adjusts counters only; `phpc_request_end` frees chunks after
 * standalone shutdown (see Context.php). Inactive path keeps identity
 * malloc/free + `malloc_usable_size` (helper-runtime muldefs-safe).
 *
 * Hand-maintained (not regenerated from Native.pre).
 */
class Native extends MemoryManager
{
    public const ARENA_RELEASE = '__phpc_mm_arena_release';

    public const ARENA_MALLOC = '__phpc_mm_arena_malloc';

    public const ARENA_FREE = '__phpc_mm_arena_free';

    public const G_CHUNK_LIST = 'phpc_mm_arena_chunks';

    public const G_BUMP = 'phpc_mm_arena_bump';

    public const G_END = 'phpc_mm_arena_end';

    public const G_ACTIVE = 'phpc_mm_arena_active';

    private const CHUNK_PAYLOAD = 262144;

    private const CHUNK_HDR = 16;

    private const ALLOC_HDR = 8;

    public function register(): void
    {
        parent::register();
        $this->ensureMallocUsableSizeDecl();
        $this->ensureArenaGlobals();
    }

    public function implement(): void
    {
        $i8p = $this->context->getTypeFromString('int8*');
        $sizeT = $this->context->getTypeFromString('size_t');
        $i64 = $this->context->getTypeFromString('int64');
        $i8 = $this->context->getTypeFromString('int8');
        $voidTy = $this->context->getTypeFromString('void');

        MemoryRuntime::ensureEmallocGlobals($this->context);
        $this->ensureMallocUsableSizeDecl();
        $this->ensureArenaGlobals();

        $this->implementArenaRelease($i8p, $i64, $voidTy);
        $this->implementArenaMalloc($i8p, $sizeT, $i64);
        $this->implementArenaFree($i8p, $i64, $voidTy);
        $this->implementRequestReset($i64, $voidTy);
        $this->implementRequestBeginEnd($i8, $voidTy);

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

    private function ensureArenaGlobals(): void
    {
        $i8p = $this->context->getTypeFromString('int8*');
        $i8 = $this->context->getTypeFromString('int8');
        foreach ([self::G_CHUNK_LIST, self::G_BUMP, self::G_END] as $name) {
            if (null === $this->context->module->getNamedGlobal($name)) {
                $g = $this->context->module->addGlobal($i8p, $name);
                $g->setInitializer($i8p->constNull());
            }
        }
        if (null === $this->context->module->getNamedGlobal(self::G_ACTIVE)) {
            $g = $this->context->module->addGlobal($i8, self::G_ACTIVE);
            $g->setInitializer($i8->constInt(0, false));
        }
    }

    /** @param mixed $i8p @param mixed $i64 @param mixed $voidTy */
    private function implementArenaRelease($i8p, $i64, $voidTy): void
    {
        $abiName = self::ARENA_RELEASE;
        $probe = $this->context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $this->context->registerFunction($abiName, $probe);

            return;
        }
        $ft = $this->context->context->functionType($voidTy, false);
        $fn = null !== $probe ? $probe : $this->context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('mm_arena_rel_entry');
        $loop = $fn->appendBasicBlock('mm_arena_rel_loop');
        $body = $fn->appendBasicBlock('mm_arena_rel_body');
        $done = $fn->appendBasicBlock('mm_arena_rel_done');
        $this->context->builder->positionAtEnd($entry);
        $this->context->builder->branch($loop);

        $this->context->builder->positionAtEnd($loop);
        $head = $this->context->builder->load($this->gptr(self::G_CHUNK_LIST, $i8p));
        $isNull = $this->context->builder->icmp(PHPLLVM\Builder::INT_EQ, $head, $i8p->constNull());
        $this->context->builder->branchIf($isNull, $done, $body);

        $this->context->builder->positionAtEnd($body);
        $nextSlot = $this->context->builder->pointerCast($head, $i8p->pointerType(0));
        $next = $this->context->builder->load($nextSlot);
        $this->context->builder->store($next, $this->gptr(self::G_CHUNK_LIST, $i8p));
        $this->context->builder->call($this->context->lookupFunction('free'), $head);
        $this->context->builder->branch($loop);

        $this->context->builder->positionAtEnd($done);
        $this->context->builder->store($i8p->constNull(), $this->gptr(self::G_BUMP, $i8p));
        $this->context->builder->store($i8p->constNull(), $this->gptr(self::G_END, $i8p));
        $this->context->builder->returnVoid();
        $this->context->registerFunction($abiName, $fn);
        $this->context->builder->clearInsertionPosition();
    }

    /** @param mixed $i8p @param mixed $sizeT @param mixed $i64 */
    private function implementArenaMalloc($i8p, $sizeT, $i64): void
    {
        $abiName = self::ARENA_MALLOC;
        $probe = $this->context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $this->context->registerFunction($abiName, $probe);

            return;
        }
        $ft = $this->context->context->functionType($i8p, false, $sizeT);
        $fn = null !== $probe ? $probe : $this->context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('mm_am_entry');
        $needNew = $fn->appendBasicBlock('mm_am_new');
        $oom = $fn->appendBasicBlock('mm_am_oom');
        $afterNew = $fn->appendBasicBlock('mm_am_after_new');
        $place = $fn->appendBasicBlock('mm_am_place');
        $this->context->builder->positionAtEnd($entry);

        $size = $fn->getParam(0);
        $sizeI64 = $this->context->builder->intCast($size, $i64);
        $withHdr = $this->context->builder->add($sizeI64, $i64->constInt(self::ALLOC_HDR, false));
        $alignMask = $i64->constInt(7, false);
        $aligned = $this->context->builder->and(
            $this->context->builder->add($withHdr, $alignMask),
            $this->context->builder->xor($alignMask, $i64->constInt(-1, true))
        );
        $overflow = $this->context->builder->icmp(PHPLLVM\Builder::INT_ULT, $aligned, $withHdr);
        $need = $this->context->builder->select($overflow, $withHdr, $aligned);

        $bump = $this->context->builder->load($this->gptr(self::G_BUMP, $i8p));
        $end = $this->context->builder->load($this->gptr(self::G_END, $i8p));
        $bumpI = $this->context->builder->ptrToInt($bump, $i64);
        $endI = $this->context->builder->ptrToInt($end, $i64);
        $nextI = $this->context->builder->add($bumpI, $need);
        $fits = $this->context->builder->icmp(PHPLLVM\Builder::INT_ULE, $nextI, $endI);
        $bumpOk = $this->context->builder->icmp(PHPLLVM\Builder::INT_NE, $bump, $i8p->constNull());
        $canFit = $this->context->builder->and($bumpOk, $fits);
        $this->context->builder->branchIf($canFit, $place, $needNew);

        $this->context->builder->positionAtEnd($needNew);
        $defaultCap = $i64->constInt(self::CHUNK_PAYLOAD, false);
        $capGe = $this->context->builder->icmp(PHPLLVM\Builder::INT_UGE, $need, $defaultCap);
        $cap = $this->context->builder->select($capGe, $need, $defaultCap);
        $chunkBytes = $this->context->builder->add($cap, $i64->constInt(self::CHUNK_HDR, false));
        $chunk = $this->context->builder->call(
            $this->context->lookupFunction('malloc'),
            $this->context->builder->intCast($chunkBytes, $sizeT)
        );
        $chunkNull = $this->context->builder->icmp(PHPLLVM\Builder::INT_EQ, $chunk, $i8p->constNull());
        $this->context->builder->branchIf($chunkNull, $oom, $afterNew);

        $this->context->builder->positionAtEnd($oom);
        $this->context->builder->returnValue($i8p->constNull());

        $this->context->builder->positionAtEnd($afterNew);
        $oldHead = $this->context->builder->load($this->gptr(self::G_CHUNK_LIST, $i8p));
        $nextSlot = $this->context->builder->pointerCast($chunk, $i8p->pointerType(0));
        $this->context->builder->store($oldHead, $nextSlot);
        $chunkI = $this->context->builder->ptrToInt($chunk, $i64);
        $capAddr = $this->context->builder->intToPtr(
            $this->context->builder->add($chunkI, $i64->constInt(8, false)),
            $i64->pointerType(0)
        );
        $this->context->builder->store($cap, $capAddr);
        $this->context->builder->store($chunk, $this->gptr(self::G_CHUNK_LIST, $i8p));
        $payload = $this->context->builder->intToPtr(
            $this->context->builder->add($chunkI, $i64->constInt(self::CHUNK_HDR, false)),
            $i8p
        );
        $payloadEnd = $this->context->builder->intToPtr(
            $this->context->builder->add(
                $this->context->builder->add($chunkI, $i64->constInt(self::CHUNK_HDR, false)),
                $cap
            ),
            $i8p
        );
        $this->context->builder->store($payload, $this->gptr(self::G_BUMP, $i8p));
        $this->context->builder->store($payloadEnd, $this->gptr(self::G_END, $i8p));
        $this->context->builder->branch($place);

        $this->context->builder->positionAtEnd($place);
        $bump2 = $this->context->builder->load($this->gptr(self::G_BUMP, $i8p));
        $sizeSlot = $this->context->builder->pointerCast($bump2, $i64->pointerType(0));
        $this->context->builder->store($sizeI64, $sizeSlot);
        $user = $this->context->builder->intToPtr(
            $this->context->builder->add(
                $this->context->builder->ptrToInt($bump2, $i64),
                $i64->constInt(self::ALLOC_HDR, false)
            ),
            $i8p
        );
        $newBump = $this->context->builder->intToPtr(
            $this->context->builder->add($this->context->builder->ptrToInt($bump2, $i64), $need),
            $i8p
        );
        $this->context->builder->store($newBump, $this->gptr(self::G_BUMP, $i8p));
        MemoryRuntime::emitNoteEmallocDelta($this->context, $sizeI64);
        $this->context->builder->returnValue($user);
        $this->context->registerFunction($abiName, $fn);
        $this->context->builder->clearInsertionPosition();
    }

    /** @param mixed $i8p @param mixed $i64 @param mixed $voidTy */
    private function implementArenaFree($i8p, $i64, $voidTy): void
    {
        $abiName = self::ARENA_FREE;
        $probe = $this->context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $this->context->registerFunction($abiName, $probe);

            return;
        }
        $ft = $this->context->context->functionType($voidTy, false, $i8p);
        $fn = null !== $probe ? $probe : $this->context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('mm_af_entry');
        $this->context->builder->positionAtEnd($entry);
        $ptr = $fn->getParam(0);
        $hdr = $this->context->builder->intToPtr(
            $this->context->builder->sub(
                $this->context->builder->ptrToInt($ptr, $i64),
                $i64->constInt(self::ALLOC_HDR, false)
            ),
            $i64->pointerType(0)
        );
        $oldSize = $this->context->builder->load($hdr);
        $neg = $this->context->builder->sub($i64->constInt(0, false), $oldSize);
        MemoryRuntime::emitNoteEmallocDelta($this->context, $neg);
        $this->context->builder->store($i64->constInt(0, false), $hdr);
        $this->context->builder->returnVoid();
        $this->context->registerFunction($abiName, $fn);
        $this->context->builder->clearInsertionPosition();
    }

    /** @param mixed $i64 @param mixed $voidTy */
    private function implementRequestReset($i64, $voidTy): void
    {
        $abiName = MemoryRuntime::EMALLOC_REQUEST_RESET;
        $probe = $this->context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $this->context->registerFunction($abiName, $probe);

            return;
        }
        $ft = $this->context->context->functionType($voidTy, false);
        $fn = null !== $probe ? $probe : $this->context->module->addFunction($abiName, $ft);
        $entry = $fn->appendBasicBlock('mm_req_reset');
        $this->context->builder->positionAtEnd($entry);
        $this->context->builder->call($this->context->lookupFunction(self::ARENA_RELEASE));
        $zero = $i64->constInt(0, false);
        $this->context->builder->store($zero, $this->emallocPtr(MemoryRuntime::G_EMALLOC_CURRENT, $i64));
        $this->context->builder->store($zero, $this->emallocPtr(MemoryRuntime::G_EMALLOC_PEAK, $i64));
        $this->context->builder->returnVoid();
        $this->context->registerFunction($abiName, $fn);
        $this->context->builder->clearInsertionPosition();
    }

    /** @param mixed $i8 @param mixed $voidTy */
    private function implementRequestBeginEnd($i8, $voidTy): void
    {
        foreach (
            [
                MemoryRuntime::REQUEST_BEGIN => [1, 'mm_req_begin'],
                MemoryRuntime::REQUEST_END => [0, 'mm_req_end'],
            ] as $abiName => [$active, $bb]
        ) {
            $probe = $this->context->module->getNamedFunction($abiName);
            if (null !== $probe && $probe->countBasicBlocks() > 0) {
                $this->context->registerFunction($abiName, $probe);

                continue;
            }
            $ft = $this->context->context->functionType($voidTy, false);
            $fn = null !== $probe ? $probe : $this->context->module->addFunction($abiName, $ft);
            $entry = $fn->appendBasicBlock($bb);
            $this->context->builder->positionAtEnd($entry);
            $this->context->builder->call($this->context->lookupFunction(MemoryRuntime::EMALLOC_REQUEST_RESET));
            $this->context->builder->store($i8->constInt($active, false), $this->gptr(self::G_ACTIVE, $i8));
            $this->context->builder->returnVoid();
            $this->context->registerFunction($abiName, $fn);
            $this->context->builder->clearInsertionPosition();
        }
    }

    private function implementMalloc(PHPLLVM\Type $i8p, PHPLLVM\Type $sizeT, PHPLLVM\Type $i64): void
    {
        $fn = $this->context->lookupFunction('__mm__malloc');
        $entry = $fn->appendBasicBlock('mm_malloc_entry');
        $arenaBb = $fn->appendBasicBlock('mm_malloc_arena');
        $libcBb = $fn->appendBasicBlock('mm_malloc_libc');
        $oom = $fn->appendBasicBlock('mm_malloc_oom');
        $ok = $fn->appendBasicBlock('mm_malloc_ok');
        $this->context->builder->positionAtEnd($entry);

        $size = $fn->getParam(0);
        $i8 = $this->context->getTypeFromString('int8');
        $active = $this->context->builder->load($this->gptr(self::G_ACTIVE, $i8));
        $isActive = $this->context->builder->icmp(PHPLLVM\Builder::INT_NE, $active, $i8->constInt(0, false));
        $this->context->builder->branchIf($isActive, $arenaBb, $libcBb);

        $this->context->builder->positionAtEnd($arenaBb);
        $fromArena = $this->context->builder->call($this->context->lookupFunction(self::ARENA_MALLOC), $size);
        $this->context->builder->returnValue($fromArena);

        $this->context->builder->positionAtEnd($libcBb);
        $raw = $this->context->builder->call($this->context->lookupFunction('malloc'), $size);
        $isNull = $this->context->builder->icmp(PHPLLVM\Builder::INT_EQ, $raw, $i8p->constNull());
        $this->context->builder->branchIf($isNull, $oom, $ok);

        $this->context->builder->positionAtEnd($oom);
        $this->context->builder->returnValue($i8p->constNull());

        $this->context->builder->positionAtEnd($ok);
        $usable = $this->context->builder->call($this->context->lookupFunction('malloc_usable_size'), $raw);
        MemoryRuntime::emitNoteEmallocDelta($this->context, $this->context->builder->intCast($usable, $i64));
        $this->context->builder->returnValue($raw);
        $this->context->builder->clearInsertionPosition();
    }

    private function implementRealloc(PHPLLVM\Type $i8p, PHPLLVM\Type $sizeT, PHPLLVM\Type $i64): void
    {
        $fn = $this->context->lookupFunction('__mm__realloc');
        $entry = $fn->appendBasicBlock('mm_realloc_entry');
        $viaMalloc = $fn->appendBasicBlock('mm_realloc_via_malloc');
        $check = $fn->appendBasicBlock('mm_realloc_check');
        $arenaPath = $fn->appendBasicBlock('mm_realloc_arena');
        $libcPath = $fn->appendBasicBlock('mm_realloc_libc');
        $oom = $fn->appendBasicBlock('mm_realloc_oom');
        $ok = $fn->appendBasicBlock('mm_realloc_ok');
        $this->context->builder->positionAtEnd($entry);

        $ptr = $fn->getParam(0);
        $size = $fn->getParam(1);
        $isNull = $this->context->builder->icmp(PHPLLVM\Builder::INT_EQ, $ptr, $i8p->constNull());
        $this->context->builder->branchIf($isNull, $viaMalloc, $check);

        $this->context->builder->positionAtEnd($viaMalloc);
        $this->context->builder->returnValue(
            $this->context->builder->call($this->context->lookupFunction('__mm__malloc'), $size)
        );

        $this->context->builder->positionAtEnd($check);
        $i8 = $this->context->getTypeFromString('int8');
        $active = $this->context->builder->load($this->gptr(self::G_ACTIVE, $i8));
        $isActive = $this->context->builder->icmp(PHPLLVM\Builder::INT_NE, $active, $i8->constInt(0, false));
        $this->context->builder->branchIf($isActive, $arenaPath, $libcPath);

        $this->context->builder->positionAtEnd($arenaPath);
        $newPtr = $this->context->builder->call($this->context->lookupFunction('__mm__malloc'), $size);
        $fail = $fn->appendBasicBlock('mm_realloc_arena_fail');
        $copy = $fn->appendBasicBlock('mm_realloc_arena_copy');
        $newNull = $this->context->builder->icmp(PHPLLVM\Builder::INT_EQ, $newPtr, $i8p->constNull());
        $this->context->builder->branchIf($newNull, $fail, $copy);
        $this->context->builder->positionAtEnd($fail);
        $this->context->builder->returnValue($i8p->constNull());
        $this->context->builder->positionAtEnd($copy);
        $oldSize = $this->context->builder->load($this->context->builder->intToPtr(
            $this->context->builder->sub(
                $this->context->builder->ptrToInt($ptr, $i64),
                $i64->constInt(self::ALLOC_HDR, false)
            ),
            $i64->pointerType(0)
        ));
        $sizeI64 = $this->context->builder->intCast($size, $i64);
        $nLt = $this->context->builder->icmp(PHPLLVM\Builder::INT_ULT, $sizeI64, $oldSize);
        $copyN = $this->context->builder->select($nLt, $sizeI64, $oldSize);
        $memcpy = $this->ensureMemcpy($i8p, $sizeT);
        $this->context->builder->call($memcpy, $newPtr, $ptr, $this->context->builder->intCast($copyN, $sizeT));
        $this->context->builder->call($this->context->lookupFunction('__mm__free'), $ptr);
        $this->context->builder->returnValue($newPtr);

        $this->context->builder->positionAtEnd($libcPath);
        $oldUsable = $this->context->builder->call($this->context->lookupFunction('malloc_usable_size'), $ptr);
        $oldI64 = $this->context->builder->intCast($oldUsable, $i64);
        $newRaw = $this->context->builder->call($this->context->lookupFunction('realloc'), $ptr, $size);
        $newIsNull = $this->context->builder->icmp(PHPLLVM\Builder::INT_EQ, $newRaw, $i8p->constNull());
        $this->context->builder->branchIf($newIsNull, $oom, $ok);
        $this->context->builder->positionAtEnd($oom);
        $this->context->builder->returnValue($i8p->constNull());
        $this->context->builder->positionAtEnd($ok);
        $newUsable = $this->context->builder->call($this->context->lookupFunction('malloc_usable_size'), $newRaw);
        $delta = $this->context->builder->sub($this->context->builder->intCast($newUsable, $i64), $oldI64);
        MemoryRuntime::emitNoteEmallocDelta($this->context, $delta);
        $this->context->builder->returnValue($newRaw);
        $this->context->builder->clearInsertionPosition();
    }

    private function implementFree(PHPLLVM\Type $i8p, PHPLLVM\Type $sizeT, PHPLLVM\Type $i64): void
    {
        $fn = $this->context->lookupFunction('__mm__free');
        $entry = $fn->appendBasicBlock('mm_free_entry');
        $done = $fn->appendBasicBlock('mm_free_done');
        $check = $fn->appendBasicBlock('mm_free_check');
        $arenaBb = $fn->appendBasicBlock('mm_free_arena');
        $libcBb = $fn->appendBasicBlock('mm_free_libc');
        $this->context->builder->positionAtEnd($entry);

        $ptr = $fn->getParam(0);
        $isNull = $this->context->builder->icmp(PHPLLVM\Builder::INT_EQ, $ptr, $i8p->constNull());
        $this->context->builder->branchIf($isNull, $done, $check);

        $this->context->builder->positionAtEnd($check);
        $i8 = $this->context->getTypeFromString('int8');
        $active = $this->context->builder->load($this->gptr(self::G_ACTIVE, $i8));
        $isActive = $this->context->builder->icmp(PHPLLVM\Builder::INT_NE, $active, $i8->constInt(0, false));
        $this->context->builder->branchIf($isActive, $arenaBb, $libcBb);

        $this->context->builder->positionAtEnd($arenaBb);
        $this->context->builder->call($this->context->lookupFunction(self::ARENA_FREE), $ptr);
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($libcBb);
        $usable = $this->context->builder->call($this->context->lookupFunction('malloc_usable_size'), $ptr);
        $neg = $this->context->builder->sub($i64->constInt(0, false), $this->context->builder->intCast($usable, $i64));
        MemoryRuntime::emitNoteEmallocDelta($this->context, $neg);
        $this->context->builder->call($this->context->lookupFunction('free'), $ptr);
        $this->context->builder->branch($done);

        $this->context->builder->positionAtEnd($done);
        $this->context->builder->returnVoid();
        $this->context->builder->clearInsertionPosition();
    }

    /** @param mixed $i8p @param mixed $sizeT @return mixed */
    private function ensureMemcpy($i8p, $sizeT)
    {
        $existing = $this->context->module->getNamedFunction('memcpy');
        if (null !== $existing) {
            $this->context->registerFunction('memcpy', $existing);

            return $existing;
        }
        $ft = $this->context->context->functionType($i8p, false, $i8p, $i8p, $sizeT);
        $fn = $this->context->module->addFunction('memcpy', $ft);
        $this->context->registerFunction('memcpy', $fn);

        return $fn;
    }

    /** @param mixed $llvmType */
    private function gptr(string $name, $llvmType): PHPLLVM\Value
    {
        $global = $this->context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException('Native arena global missing: '.$name.' (#36388)');
        }

        return $this->context->builder->pointerCast($global, $llvmType->pointerType(0));
    }

    /** @param mixed $llvmType */
    private function emallocPtr(string $name, $llvmType): PHPLLVM\Value
    {
        $global = $this->context->module->getNamedGlobal($name);
        if (null === $global) {
            throw new \LogicException('Native emalloc global missing: '.$name.' (#36388)');
        }

        return $this->context->builder->pointerCast($global, $llvmType->pointerType(0));
    }
}
