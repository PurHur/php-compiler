<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Builder;
use PHPLLVM\Value;
use PHPLLVM\Value\Function_ as LlvmFunction;

/**
 * JIT/AOT link for sem_* (#28431).
 *
 * Full LLVM path — NestedJIT FFI/semop is unreliable under thin AOT (peer #28433 / #27423).
 * Owned map is a module global table. php-src: ext/sysvsem/sysvsem.c
 */
final class SemRuntime
{
    private const IPC_CREAT = 512;

    private const IPC_NOWAIT = 2048;

    private const IPC_RMID = 0;

    private const IPC_STAT = 2;

    private const GETVAL = 12;

    private const SETVAL = 16;

    private const SEM_UNDO = 4096;

    private const EINTR = 4;

    private const SYSVSEM_SEM = 0;

    private const SYSVSEM_USAGE = 1;

    private const SYSVSEM_SETVAL = 2;

    /** Linux x86_64 sizeof(struct sembuf) */
    private const SEMBUF_SIZE = 6;

    private const SEMBUF_NUM_OFF = 0;

    private const SEMBUF_OP_OFF = 2;

    private const SEMBUF_FLG_OFF = 4;

    /** sizeof(struct semid_ds) on Linux x86_64 — generous pad */
    private const SEMID_DS_SIZE = 128;

    /** Max concurrent SysvSemaphore handles in one process. */
    private const MAP_SLOTS = 32;

    /** Slot: obj, semid, key, count, auto_release — 5 × i64 */
    private const SLOT_FIELDS = 5;

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_sem_get_register',
        '__compiler_sem_acquire',
        '__compiler_sem_release',
        '__compiler_sem_remove',
    ];

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_sem_get_register');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureLibcSem($context);
        LibcExtern::register($context);
        self::ensureMapGlobal($context);
        self::implementGetRegisterBridge($context);
        self::implementAcquireBridge($context);
        self::implementReleaseBridge($context);
        self::implementRemoveBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function ensureLibcSem(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        foreach (
            [
                'semget' => [$i32, false, [$i32, $i32, $i32]],
                // sembuf* as i8*; nsops as size_t
                'semop' => [$i32, false, [$i32, $i8p, $sizeT]],
                // union semun as i64 (x86_64)
                'semctl' => [$i32, false, [$i32, $i32, $i32, $context->getTypeFromString('int64')]],
            ] as $name => $spec
        ) {
            $existing = $context->module->getNamedFunction($name);
            if (null !== $existing) {
                $context->registerFunction($name, $existing);
                continue;
            }
            $fn = $context->module->addFunction(
                $name,
                $context->context->functionType($spec[0], $spec[1], ...$spec[2])
            );
            $context->registerFunction($name, $fn);
        }
    }

    private static function ensureMapGlobal(Context $context): void
    {
        $name = '__compiler_sem_owned_map';
        if (null !== $context->module->getNamedGlobal($name)) {
            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $arrTy = $i64->arrayType(self::MAP_SLOTS * self::SLOT_FIELDS);
        $global = $context->module->addGlobal($arrTy, $name);
        $global->setInitializer($arrTy->constNull());
    }

    private static function mapGlobal(Context $context): Value
    {
        $g = $context->module->getNamedGlobal('__compiler_sem_owned_map');
        if (null === $g) {
            throw new \LogicException('sem owned map global missing (#28431)');
        }

        return $g;
    }

    private static function slotFieldPtr(Context $context, Value $index, int $field): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $map = self::mapGlobal($context);
        $base = $context->builder->mul(
            $index,
            $i64->constInt(self::SLOT_FIELDS, false)
        );
        $off = $context->builder->add($base, $i64->constInt($field, false));

        return $context->builder->gep(
            $map,
            $i32->constInt(0, false),
            $context->builder->trunc($off, $i32)
        );
    }

    private static function emitFindSlot(
        Context $context,
        LlvmFunction $fn,
        Value $objAddr,
        string $prefix
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $idxSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(0, false), $idxSlot);
        $loop = $fn->appendBasicBlock($prefix.'_find_loop');
        $body = $fn->appendBasicBlock($prefix.'_find_body');
        $next = $fn->appendBasicBlock($prefix.'_find_next');
        $found = $fn->appendBasicBlock($prefix.'_find_found');
        $miss = $fn->appendBasicBlock($prefix.'_find_miss');
        $done = $fn->appendBasicBlock($prefix.'_find_done');
        $result = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $idx = $context->builder->load($idxSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $idx, $i64->constInt(self::MAP_SLOTS, false)),
            $body,
            $miss
        );

        $context->builder->positionAtEnd($body);
        $key = $context->builder->load(self::slotFieldPtr($context, $idx, 0));
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $key, $objAddr),
            $found,
            $next
        );

        $context->builder->positionAtEnd($found);
        $context->builder->store($idx, $result);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($next);
        $context->builder->store(
            $context->builder->add($idx, $i64->constInt(1, false)),
            $idxSlot
        );
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($miss);
        $context->builder->store($i64->constInt(-1, true), $result);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($result);
    }

    private static function emitAllocSlot(
        Context $context,
        LlvmFunction $fn,
        string $prefix
    ): Value {
        $i64 = $context->getTypeFromString('int64');
        $idxSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($i64->constInt(0, false), $idxSlot);
        $loop = $fn->appendBasicBlock($prefix.'_alloc_loop');
        $body = $fn->appendBasicBlock($prefix.'_alloc_body');
        $next = $fn->appendBasicBlock($prefix.'_alloc_next');
        $found = $fn->appendBasicBlock($prefix.'_alloc_found');
        $miss = $fn->appendBasicBlock($prefix.'_alloc_miss');
        $done = $fn->appendBasicBlock($prefix.'_alloc_done');
        $result = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $idx = $context->builder->load($idxSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $idx, $i64->constInt(self::MAP_SLOTS, false)),
            $body,
            $miss
        );

        $context->builder->positionAtEnd($body);
        $key = $context->builder->load(self::slotFieldPtr($context, $idx, 0));
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $key, $i64->constInt(0, false)),
            $found,
            $next
        );

        $context->builder->positionAtEnd($found);
        $context->builder->store($idx, $result);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($next);
        $context->builder->store(
            $context->builder->add($idx, $i64->constInt(1, false)),
            $idxSlot
        );
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($miss);
        $context->builder->store($i64->constInt(-1, true), $result);
        $context->builder->branch($done);

        $context->builder->positionAtEnd($done);

        return $context->builder->load($result);
    }

    /** Store one sembuf at base[index] (base is char[N] alloca). */
    private static function storeSembuf(
        Context $context,
        Value $baseArr,
        int $index,
        int $num,
        int $op,
        int $flg
    ): void {
        $i16 = $context->getTypeFromString('int16');
        $i32 = $context->getTypeFromString('int32');
        $off = $index * self::SEMBUF_SIZE;
        $numPtr = $context->builder->pointerCast(
            $context->builder->gep(
                $baseArr,
                $i32->constInt(0, false),
                $i32->constInt($off + self::SEMBUF_NUM_OFF, false)
            ),
            $i16->pointerType(0)
        );
        $opPtr = $context->builder->pointerCast(
            $context->builder->gep(
                $baseArr,
                $i32->constInt(0, false),
                $i32->constInt($off + self::SEMBUF_OP_OFF, false)
            ),
            $i16->pointerType(0)
        );
        $flgPtr = $context->builder->pointerCast(
            $context->builder->gep(
                $baseArr,
                $i32->constInt(0, false),
                $i32->constInt($off + self::SEMBUF_FLG_OFF, false)
            ),
            $i16->pointerType(0)
        );
        $context->builder->store($i16->constInt($num, false), $numPtr);
        $context->builder->store($i16->constInt($op, true), $opPtr);
        $context->builder->store($i16->constInt($flg, true), $flgPtr);
    }

    /**
     * Retrying semop until success or non-EINTR failure. Returns i32 rc.
     */
    private static function emitSemopRetry(
        Context $context,
        LlvmFunction $fn,
        Value $semidI32,
        Value $sopsI8,
        int $nsops,
        string $prefix
    ): Value {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $rcSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $loop = $fn->appendBasicBlock($prefix.'_semop_loop');
        $check = $fn->appendBasicBlock($prefix.'_semop_check');
        $done = $fn->appendBasicBlock($prefix.'_semop_done');
        $context->builder->branch($loop);

        $context->builder->positionAtEnd($loop);
        $rc = $context->builder->call(
            $context->lookupFunction('semop'),
            $semidI32,
            $context->builder->pointerCast(
                $context->builder->gep($sopsI8, $i32->constInt(0, false), $i32->constInt(0, false)),
                $i8p
            ),
            $sizeT->constInt($nsops, false)
        );
        $context->builder->store($rc, $rcSlot);
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $rc, $i32->constInt(0, false)),
            $done,
            $check
        );

        $context->builder->positionAtEnd($check);
        // errno via __errno_location if available; otherwise treat any failure as terminal
        $errnoFn = $context->module->getNamedFunction('__errno_location');
        if (null === $errnoFn) {
            $errnoFn = $context->module->addFunction(
                '__errno_location',
                $context->context->functionType($i32->pointerType(0), false)
            );
            $context->registerFunction('__errno_location', $errnoFn);
        } else {
            $context->registerFunction('__errno_location', $errnoFn);
        }
        $errno = $context->builder->load($context->builder->call($errnoFn));
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $errno, $i32->constInt(self::EINTR, false)),
            $loop,
            $done
        );

        $context->builder->positionAtEnd($done);

        return $context->builder->load($rcSlot);
    }

    private static function implementGetRegisterBridge(Context $context): void
    {
        $abiName = '__compiler_sem_get_register';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, $i64, $i64, $i64, $i64, $i64)
            );

        $entry = $fn->appendBasicBlock('sem_gr_entry');
        $fail = $fn->appendBasicBlock('sem_gr_fail');
        $gotId = $fn->appendBasicBlock('sem_gr_got_id');
        $context->builder->positionAtEnd($entry);

        $objAddr = $fn->getParam(0);
        $key = $context->builder->trunc($fn->getParam(1), $i32);
        $maxAcquire = $context->builder->trunc($fn->getParam(2), $i32);
        $perm = $context->builder->trunc($fn->getParam(3), $i32);
        $autoRelease = $fn->getParam(4);

        $maxAcquire = $context->builder->select(
            $context->builder->icmp(Builder::INT_SLT, $maxAcquire, $i32->constInt(1, true)),
            $i32->constInt(1, false),
            $maxAcquire
        );
        $shmflg = $context->builder->or($perm, $i32->constInt(self::IPC_CREAT, false));
        $semid = $context->builder->call(
            $context->lookupFunction('semget'),
            $key,
            $i32->constInt(3, false),
            $shmflg
        );
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $semid, $i32->constInt(0, true)),
            $gotId,
            $fail
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(0, false));

        $context->builder->positionAtEnd($gotId);
        // Lock SETVAL + bump USAGE (3 ops)
        $lockBuf = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::SEMBUF_SIZE * 3));
        self::storeSembuf($context, $lockBuf, 0, self::SYSVSEM_SETVAL, 0, 0);
        self::storeSembuf($context, $lockBuf, 1, self::SYSVSEM_SETVAL, 1, self::SEM_UNDO);
        self::storeSembuf($context, $lockBuf, 2, self::SYSVSEM_USAGE, 1, self::SEM_UNDO);
        self::emitSemopRetry($context, $fn, $semid, $lockBuf, 3, 'sem_gr_lock');

        $count = $context->builder->call(
            $context->lookupFunction('semctl'),
            $semid,
            $i32->constInt(self::SYSVSEM_USAGE, false),
            $i32->constInt(self::GETVAL, false),
            $i64->constInt(0, false)
        );
        $needSet = $context->builder->icmp(Builder::INT_EQ, $count, $i32->constInt(1, false));
        $setBb = $fn->appendBasicBlock('sem_gr_setval');
        $afterSet = $fn->appendBasicBlock('sem_gr_after_set');
        $context->builder->branchIf($needSet, $setBb, $afterSet);

        $context->builder->positionAtEnd($setBb);
        $context->builder->call(
            $context->lookupFunction('semctl'),
            $semid,
            $i32->constInt(self::SYSVSEM_SEM, false),
            $i32->constInt(self::SETVAL, false),
            $context->builder->zext($maxAcquire, $i64)
        );
        $context->builder->branch($afterSet);

        $context->builder->positionAtEnd($afterSet);
        $unlockBuf = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::SEMBUF_SIZE));
        self::storeSembuf($context, $unlockBuf, 0, self::SYSVSEM_SETVAL, -1, self::SEM_UNDO);
        self::emitSemopRetry($context, $fn, $semid, $unlockBuf, 1, 'sem_gr_unlock');

        $slot = self::emitAllocSlot($context, $fn, 'sem_gr');
        $noSlot = $fn->appendBasicBlock('sem_gr_noslot');
        $doReg = $fn->appendBasicBlock('sem_gr_doreg');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $slot, $i64->constInt(0, true)),
            $noSlot,
            $doReg
        );

        $context->builder->positionAtEnd($noSlot);
        $context->builder->returnValue($i64->constInt(0, false));

        $context->builder->positionAtEnd($doReg);
        $context->builder->store($objAddr, self::slotFieldPtr($context, $slot, 0));
        $context->builder->store($context->builder->sext($semid, $i64), self::slotFieldPtr($context, $slot, 1));
        $context->builder->store($context->builder->sext($key, $i64), self::slotFieldPtr($context, $slot, 2));
        $context->builder->store($i64->constInt(0, false), self::slotFieldPtr($context, $slot, 3));
        $context->builder->store(
            $context->builder->select(
                $context->builder->icmp(Builder::INT_NE, $autoRelease, $i64->constInt(0, false)),
                $i64->constInt(1, false),
                $i64->constInt(0, false)
            ),
            self::slotFieldPtr($context, $slot, 4)
        );
        $context->builder->returnValue($i64->constInt(1, false));

        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementAcquireBridge(Context $context): void
    {
        $abiName = '__compiler_sem_acquire';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, $i64, $i64)
            );
        $entry = $fn->appendBasicBlock('sem_acq_entry');
        $context->builder->positionAtEnd($entry);
        $handle = $fn->getParam(0);
        $nowait = $fn->getParam(1);
        $slot = self::emitFindSlot($context, $fn, $handle, 'sem_acq');
        $fail = $fn->appendBasicBlock('sem_acq_fail');
        $ok = $fn->appendBasicBlock('sem_acq_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $slot, $i64->constInt(0, true)),
            $ok,
            $fail
        );
        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(0, false));

        $context->builder->positionAtEnd($ok);
        $count = $context->builder->load(self::slotFieldPtr($context, $slot, 3));
        $removed = $fn->appendBasicBlock('sem_acq_removed');
        $live = $fn->appendBasicBlock('sem_acq_live');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $count, $i64->constInt(0, true)),
            $removed,
            $live
        );
        $context->builder->positionAtEnd($removed);
        $context->builder->returnValue($i64->constInt(0, false));

        $context->builder->positionAtEnd($live);
        $semid = $context->builder->trunc(
            $context->builder->load(self::slotFieldPtr($context, $slot, 1)),
            $i32
        );
        $flg = $context->builder->select(
            $context->builder->icmp(Builder::INT_NE, $nowait, $i64->constInt(0, false)),
            $i32->constInt(self::SEM_UNDO | self::IPC_NOWAIT, false),
            $i32->constInt(self::SEM_UNDO, false)
        );
        // Dynamic flg — store after computing
        $buf = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::SEMBUF_SIZE));
        $i16 = $context->getTypeFromString('int16');
        $numPtr = $context->builder->pointerCast(
            $context->builder->gep($buf, $i32->constInt(0, false), $i32->constInt(self::SEMBUF_NUM_OFF, false)),
            $i16->pointerType(0)
        );
        $opPtr = $context->builder->pointerCast(
            $context->builder->gep($buf, $i32->constInt(0, false), $i32->constInt(self::SEMBUF_OP_OFF, false)),
            $i16->pointerType(0)
        );
        $flgPtr = $context->builder->pointerCast(
            $context->builder->gep($buf, $i32->constInt(0, false), $i32->constInt(self::SEMBUF_FLG_OFF, false)),
            $i16->pointerType(0)
        );
        $context->builder->store($i16->constInt(self::SYSVSEM_SEM, false), $numPtr);
        $context->builder->store($i16->constInt(-1, true), $opPtr);
        $context->builder->store($context->builder->trunc($flg, $i16), $flgPtr);
        $rc = self::emitSemopRetry($context, $fn, $semid, $buf, 1, 'sem_acq');
        $opFail = $fn->appendBasicBlock('sem_acq_opfail');
        $opOk = $fn->appendBasicBlock('sem_acq_opok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $rc, $i32->constInt(0, false)),
            $opOk,
            $opFail
        );
        $context->builder->positionAtEnd($opFail);
        $context->builder->returnValue($i64->constInt(0, false));
        $context->builder->positionAtEnd($opOk);
        $context->builder->store(
            $context->builder->add($count, $i64->constInt(1, false)),
            self::slotFieldPtr($context, $slot, 3)
        );
        $context->builder->returnValue($i64->constInt(1, false));
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementReleaseBridge(Context $context): void
    {
        $abiName = '__compiler_sem_release';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, $i64)
            );
        $entry = $fn->appendBasicBlock('sem_rel_entry');
        $context->builder->positionAtEnd($entry);
        $slot = self::emitFindSlot($context, $fn, $fn->getParam(0), 'sem_rel');
        $fail = $fn->appendBasicBlock('sem_rel_fail');
        $ok = $fn->appendBasicBlock('sem_rel_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $slot, $i64->constInt(0, true)),
            $ok,
            $fail
        );
        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(0, false));
        $context->builder->positionAtEnd($ok);
        $count = $context->builder->load(self::slotFieldPtr($context, $slot, 3));
        $bad = $fn->appendBasicBlock('sem_rel_bad');
        $live = $fn->appendBasicBlock('sem_rel_live');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLE, $count, $i64->constInt(0, true)),
            $bad,
            $live
        );
        $context->builder->positionAtEnd($bad);
        $context->builder->returnValue($i64->constInt(0, false));
        $context->builder->positionAtEnd($live);
        $semid = $context->builder->trunc(
            $context->builder->load(self::slotFieldPtr($context, $slot, 1)),
            $i32
        );
        $buf = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::SEMBUF_SIZE));
        self::storeSembuf($context, $buf, 0, self::SYSVSEM_SEM, 1, self::SEM_UNDO);
        $rc = self::emitSemopRetry($context, $fn, $semid, $buf, 1, 'sem_rel');
        $opFail = $fn->appendBasicBlock('sem_rel_opfail');
        $opOk = $fn->appendBasicBlock('sem_rel_opok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $rc, $i32->constInt(0, false)),
            $opOk,
            $opFail
        );
        $context->builder->positionAtEnd($opFail);
        $context->builder->returnValue($i64->constInt(0, false));
        $context->builder->positionAtEnd($opOk);
        $context->builder->store(
            $context->builder->sub($count, $i64->constInt(1, false)),
            self::slotFieldPtr($context, $slot, 3)
        );
        $context->builder->returnValue($i64->constInt(1, false));
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementRemoveBridge(Context $context): void
    {
        $abiName = '__compiler_sem_remove';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, $i64)
            );
        $entry = $fn->appendBasicBlock('sem_rm_entry');
        $context->builder->positionAtEnd($entry);
        $slot = self::emitFindSlot($context, $fn, $fn->getParam(0), 'sem_rm');
        $fail = $fn->appendBasicBlock('sem_rm_fail');
        $ok = $fn->appendBasicBlock('sem_rm_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $slot, $i64->constInt(0, true)),
            $ok,
            $fail
        );
        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(0, false));
        $context->builder->positionAtEnd($ok);
        $count = $context->builder->load(self::slotFieldPtr($context, $slot, 3));
        $removed = $fn->appendBasicBlock('sem_rm_already');
        $live = $fn->appendBasicBlock('sem_rm_live');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $count, $i64->constInt(0, true)),
            $removed,
            $live
        );
        $context->builder->positionAtEnd($removed);
        $context->builder->returnValue($i64->constInt(0, false));
        $context->builder->positionAtEnd($live);
        $semid = $context->builder->trunc(
            $context->builder->load(self::slotFieldPtr($context, $slot, 1)),
            $i32
        );
        $ds = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::SEMID_DS_SIZE));
        // union semun.buf = &ds — pass pointer as i64 (x86_64 union-by-value)
        $arg = $context->builder->ptrToInt(
            $context->builder->gep($ds, $i32->constInt(0, false), $i32->constInt(0, false)),
            $i64
        );
        $statRc = $context->builder->call(
            $context->lookupFunction('semctl'),
            $semid,
            $i32->constInt(0, false),
            $i32->constInt(self::IPC_STAT, false),
            $arg
        );
        $statFail = $fn->appendBasicBlock('sem_rm_statfail');
        $statOk = $fn->appendBasicBlock('sem_rm_statok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $statRc, $i32->constInt(0, false)),
            $statOk,
            $statFail
        );
        $context->builder->positionAtEnd($statFail);
        $context->builder->returnValue($i64->constInt(0, false));
        $context->builder->positionAtEnd($statOk);
        $rmRc = $context->builder->call(
            $context->lookupFunction('semctl'),
            $semid,
            $i32->constInt(0, false),
            $i32->constInt(self::IPC_RMID, false),
            $arg
        );
        $rmFail = $fn->appendBasicBlock('sem_rm_rmfail');
        $rmOk = $fn->appendBasicBlock('sem_rm_rmok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $rmRc, $i32->constInt(0, false)),
            $rmOk,
            $rmFail
        );
        $context->builder->positionAtEnd($rmFail);
        $context->builder->returnValue($i64->constInt(0, false));
        $context->builder->positionAtEnd($rmOk);
        $context->builder->store($i64->constInt(-1, true), self::slotFieldPtr($context, $slot, 3));
        $context->builder->returnValue($i64->constInt(1, false));
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after SemRuntime link (#28431)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
