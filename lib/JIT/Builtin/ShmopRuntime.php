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
 * JIT/AOT link for shmop_* (#27408 / #28433).
 *
 * Full LLVM path — NestedJIT FFI/map is unreliable under thin AOT for pointer-sized
 * segment addresses (peer #27423). Owned map is a module global table.
 * php-src: ext/shmop/shmop.c
 */
final class ShmopRuntime
{
    private const IPC_CREAT = 512;

    private const IPC_EXCL = 1024;

    private const IPC_RMID = 0;

    private const IPC_STAT = 2;

    private const SHM_RDONLY = 4096;

    private const SHMID_DS_SIZE = 112;

    private const SHM_SEGSZ_OFFSET = 48;

    /** Max concurrent Shmop handles in one process (issue repros need 1–2). */
    private const MAP_SLOTS = 32;

    /** Slot layout: obj, shmid, addr, size, readonly — 5 × i64 */
    private const SLOT_FIELDS = 5;

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_shmop_open_register',
        '__compiler_shmop_size',
        '__compiler_shmop_delete',
        '__compiler_shmop_close',
        '__compiler_shmop_read',
        '__compiler_shmop_write',
    ];

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_shmop_open_register');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureLibcShm($context);
        LibcExtern::register($context);
        // Module-local memcpy(3) after LibcExtern always-on drop (#31885).
        LibcExtern::ensureMemcpyDecl($context);
        self::ensureMapGlobal($context);
        self::implementOpenRegisterBridge($context);
        self::implementSizeBridge($context);
        self::implementDeleteBridge($context);
        self::implementCloseBridge($context);
        self::implementReadBridge($context);
        self::implementWriteBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function ensureLibcShm(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        foreach (
            [
                'shmget' => [$i32, false, [$i32, $sizeT, $i32]],
                'shmat' => [$i8p, false, [$i32, $i8p, $i32]],
                'shmctl' => [$i32, false, [$i32, $i32, $i8p]],
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
        $name = '__compiler_shmop_owned_map';
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
        $g = $context->module->getNamedGlobal('__compiler_shmop_owned_map');
        if (null === $g) {
            throw new \LogicException('shmop owned map global missing (#28433)');
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

    /** Linear search: return slot index or -1. */
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

    /** Find free slot (obj==0) or -1. */
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

    private static function implementOpenRegisterBridge(Context $context): void
    {
        $abiName = '__compiler_shmop_open_register';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }

        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, $i64, $i64, $i64, $i64, $i64)
            );

        $entry = $fn->appendBasicBlock('shmop_or_entry');
        $fail = $fn->appendBasicBlock('shmop_or_fail');
        $gotId = $fn->appendBasicBlock('shmop_or_got_id');
        $context->builder->positionAtEnd($entry);

        $objAddr = $fn->getParam(0);
        $key = $context->builder->trunc($fn->getParam(1), $i32);
        $modeChar = $fn->getParam(2);
        $permissions = $context->builder->trunc($fn->getParam(3), $i32);
        $reqSize = $fn->getParam(4);

        $isA = $context->builder->icmp(Builder::INT_EQ, $modeChar, $i64->constInt(\ord('a'), false));
        $isC = $context->builder->icmp(Builder::INT_EQ, $modeChar, $i64->constInt(\ord('c'), false));
        $isN = $context->builder->icmp(Builder::INT_EQ, $modeChar, $i64->constInt(\ord('n'), false));

        $permMask = $context->builder->and($permissions, $i32->constInt(0o777, false));
        $creatFlags = $context->builder->select(
            $isN,
            $i32->constInt(self::IPC_CREAT | self::IPC_EXCL, false),
            $context->builder->select($isC, $i32->constInt(self::IPC_CREAT, false), $i32->constInt(0, false))
        );
        $shmflg = $context->builder->or($permMask, $creatFlags);
        $shmatflg = $context->builder->select(
            $isA,
            $i32->constInt(self::SHM_RDONLY, false),
            $i32->constInt(0, false)
        );
        $needsCreate = $context->builder->or($isC, $isN);
        $createSize = $context->builder->select($needsCreate, $reqSize, $i64->constInt(0, false));

        $shmidI32 = $context->builder->call(
            $context->lookupFunction('shmget'),
            $key,
            $context->builder->truncOrBitCast($createSize, $sizeT),
            $shmflg
        );
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $shmidI32, $i32->constInt(0, true)),
            $gotId,
            $fail
        );

        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(-1, true));

        $context->builder->positionAtEnd($gotId);
        $addrPtr = $context->builder->call(
            $context->lookupFunction('shmat'),
            $shmidI32,
            $i8p->constNull(),
            $shmatflg
        );
        $addrI64 = $context->builder->ptrToInt($addrPtr, $i64);
        $attachFail = $fn->appendBasicBlock('shmop_or_attach_fail');
        $attachOk = $fn->appendBasicBlock('shmop_or_attach_ok');
        $context->builder->branchIf(
            $context->builder->or(
                $context->builder->icmp(Builder::INT_EQ, $addrI64, $i64->constInt(0, false)),
                $context->builder->icmp(Builder::INT_EQ, $addrI64, $i64->constInt(-1, true))
            ),
            $attachFail,
            $attachOk
        );

        $context->builder->positionAtEnd($attachFail);
        $context->builder->returnValue($i64->constInt(-1, true));

        $context->builder->positionAtEnd($attachOk);
        $segSizeSlot = BasicBlockHelper::entryAlloca($context, $i64);
        $context->builder->store($createSize, $segSizeSlot);
        $needStat = $context->builder->icmp(Builder::INT_SLT, $createSize, $i64->constInt(1, true));
        $statBb = $fn->appendBasicBlock('shmop_or_stat');
        $afterSize = $fn->appendBasicBlock('shmop_or_after_size');
        $context->builder->branchIf($needStat, $statBb, $afterSize);

        $context->builder->positionAtEnd($statBb);
        $ds = BasicBlockHelper::entryAlloca($context, $i8->arrayType(self::SHMID_DS_SIZE));
        $statRc = $context->builder->call(
            $context->lookupFunction('shmctl'),
            $shmidI32,
            $i32->constInt(self::IPC_STAT, false),
            $context->builder->pointerCast($ds, $i8p)
        );
        $statFail = $fn->appendBasicBlock('shmop_or_stat_fail');
        $statOk = $fn->appendBasicBlock('shmop_or_stat_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_EQ, $statRc, $i32->constInt(0, false)),
            $statOk,
            $statFail
        );

        $context->builder->positionAtEnd($statFail);
        $context->builder->returnValue($i64->constInt(-1, true));

        $context->builder->positionAtEnd($statOk);
        $segszPtr = $context->builder->pointerCast(
            $context->builder->gep($ds, $i32->constInt(0, false), $i32->constInt(self::SHM_SEGSZ_OFFSET, false)),
            $i64->pointerType(0)
        );
        $context->builder->store($context->builder->load($segszPtr), $segSizeSlot);
        $context->builder->branch($afterSize);

        $context->builder->positionAtEnd($afterSize);
        $segSize = $context->builder->load($segSizeSlot);
        $sizeFail = $fn->appendBasicBlock('shmop_or_size_fail');
        $regBb = $fn->appendBasicBlock('shmop_or_reg');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $segSize, $i64->constInt(1, true)),
            $sizeFail,
            $regBb
        );

        $context->builder->positionAtEnd($sizeFail);
        $context->builder->returnValue($i64->constInt(-1, true));

        $context->builder->positionAtEnd($regBb);
        $slot = self::emitAllocSlot($context, $fn, 'shmop_or');
        $noSlot = $fn->appendBasicBlock('shmop_or_noslot');
        $doReg = $fn->appendBasicBlock('shmop_or_doreg');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $slot, $i64->constInt(0, true)),
            $noSlot,
            $doReg
        );

        $context->builder->positionAtEnd($noSlot);
        $context->builder->returnValue($i64->constInt(-1, true));

        $context->builder->positionAtEnd($doReg);
        $shmidI64 = $context->builder->sext($shmidI32, $i64);
        $readonly = $context->builder->zext($isA, $i64);
        $context->builder->store($objAddr, self::slotFieldPtr($context, $slot, 0));
        $context->builder->store($shmidI64, self::slotFieldPtr($context, $slot, 1));
        $context->builder->store($addrI64, self::slotFieldPtr($context, $slot, 2));
        $context->builder->store($segSize, self::slotFieldPtr($context, $slot, 3));
        $context->builder->store($readonly, self::slotFieldPtr($context, $slot, 4));
        $context->builder->returnValue($shmidI64);

        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementSizeBridge(Context $context): void
    {
        $abiName = '__compiler_shmop_size';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, $i64)
            );
        $entry = $fn->appendBasicBlock('shmop_size_entry');
        $context->builder->positionAtEnd($entry);
        $slot = self::emitFindSlot($context, $fn, $fn->getParam(0), 'shmop_size');
        $fail = $fn->appendBasicBlock('shmop_size_fail');
        $ok = $fn->appendBasicBlock('shmop_size_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $slot, $i64->constInt(0, true)),
            $ok,
            $fail
        );
        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(-1, true));
        $context->builder->positionAtEnd($ok);
        $context->builder->returnValue($context->builder->load(self::slotFieldPtr($context, $slot, 3)));
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementCloseBridge(Context $context): void
    {
        $abiName = '__compiler_shmop_close';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($context->getTypeFromString('void'), false, $i64)
            );
        $entry = $fn->appendBasicBlock('shmop_close_entry');
        $context->builder->positionAtEnd($entry);
        // php-src shmop_close is a NOP
        $context->builder->returnVoid();
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementDeleteBridge(Context $context): void
    {
        $abiName = '__compiler_shmop_delete';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, $i64)
            );
        $entry = $fn->appendBasicBlock('shmop_delete_entry');
        $context->builder->positionAtEnd($entry);
        $slot = self::emitFindSlot($context, $fn, $fn->getParam(0), 'shmop_del');
        $fail = $fn->appendBasicBlock('shmop_del_fail');
        $ok = $fn->appendBasicBlock('shmop_del_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $slot, $i64->constInt(0, true)),
            $ok,
            $fail
        );
        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(0, false));
        $context->builder->positionAtEnd($ok);
        $shmid = $context->builder->load(self::slotFieldPtr($context, $slot, 1));
        $rc = $context->builder->call(
            $context->lookupFunction('shmctl'),
            $context->builder->trunc($shmid, $i32),
            $i32->constInt(self::IPC_RMID, false),
            $i8p->constNull()
        );
        $context->builder->returnValue(
            $context->builder->select(
                $context->builder->icmp(Builder::INT_EQ, $rc, $i32->constInt(0, false)),
                $i64->constInt(1, false),
                $i64->constInt(0, false)
            )
        );
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementReadBridge(Context $context): void
    {
        $abiName = '__compiler_shmop_read';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        TypeErrorRaise::ensureLinked($context);
        LibcExtern::register($context);
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $sizeT = $context->getTypeFromString('size_t');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($strPtr, false, $i64, $i64, $i64)
            );
        $entry = $fn->appendBasicBlock('shmop_read_entry');
        $context->builder->positionAtEnd($entry);
        $handle = $fn->getParam(0);
        $start = $fn->getParam(1);
        $count = $fn->getParam(2);
        $slot = self::emitFindSlot($context, $fn, $handle, 'shmop_rd');
        $fail = $fn->appendBasicBlock('shmop_rd_fail');
        $ok = $fn->appendBasicBlock('shmop_rd_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $slot, $i64->constInt(0, true)),
            $ok,
            $fail
        );
        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue(
            $context->builder->call($context->lookupFunction('__string__alloc'), $sizeT->constInt(0, false))
        );
        $context->builder->positionAtEnd($ok);
        $addr = $context->builder->load(self::slotFieldPtr($context, $slot, 2));
        $segSize = $context->builder->load(self::slotFieldPtr($context, $slot, 3));
        self::abortValueErrorIf(
            $context,
            $fn,
            $context->builder->or(
                $context->builder->icmp(Builder::INT_SLT, $start, $i64->constInt(0, true)),
                $context->builder->icmp(Builder::INT_SGT, $start, $segSize)
            ),
            'shmop_read(): Argument #2 ($offset) must be between 0 and the segment size',
            'shmop_rd_start'
        );
        self::abortValueErrorIf(
            $context,
            $fn,
            $context->builder->or(
                $context->builder->icmp(Builder::INT_SLT, $count, $i64->constInt(0, true)),
                $context->builder->icmp(Builder::INT_SGT, $context->builder->add($start, $count), $segSize)
            ),
            'shmop_read(): Argument #3 ($size) is out of range',
            'shmop_rd_count'
        );
        $bytes = $context->builder->select(
            $context->builder->icmp(Builder::INT_EQ, $count, $i64->constInt(0, false)),
            $context->builder->sub($segSize, $start),
            $count
        );
        $empty = $fn->appendBasicBlock('shmop_rd_empty');
        $copy = $fn->appendBasicBlock('shmop_rd_copy');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $bytes, $i64->constInt(1, true)),
            $empty,
            $copy
        );
        $context->builder->positionAtEnd($empty);
        $context->builder->returnValue(
            $context->builder->call($context->lookupFunction('__string__alloc'), $sizeT->constInt(0, false))
        );
        $context->builder->positionAtEnd($copy);
        $nSize = $context->builder->truncOrBitCast($bytes, $sizeT);
        $str = $context->builder->call($context->lookupFunction('__string__alloc'), $nSize);
        $stringMap = $context->structFieldMap['__string__'];
        $dst = $context->builder->structGep($str, $stringMap['value']);
        $src = $context->builder->intToPtr($context->builder->add($addr, $start), $i8p);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->builder->pointerCast($dst, $i8p),
            $src,
            $nSize
        );
        $context->builder->returnValue($str);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementWriteBridge(Context $context): void
    {
        $abiName = '__compiler_shmop_write';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        TypeErrorRaise::ensureLinked($context);
        LibcExtern::register($context);
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $sizeT = $context->getTypeFromString('size_t');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, $i64, $strPtr, $i64)
            );
        $entry = $fn->appendBasicBlock('shmop_wr_entry');
        $context->builder->positionAtEnd($entry);
        $handle = $fn->getParam(0);
        $data = $fn->getParam(1);
        $offset = $fn->getParam(2);
        $slot = self::emitFindSlot($context, $fn, $handle, 'shmop_wr');
        $fail = $fn->appendBasicBlock('shmop_wr_fail');
        $ok = $fn->appendBasicBlock('shmop_wr_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $slot, $i64->constInt(0, true)),
            $ok,
            $fail
        );
        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(-1, true));
        $context->builder->positionAtEnd($ok);
        $addr = $context->builder->load(self::slotFieldPtr($context, $slot, 2));
        $segSize = $context->builder->load(self::slotFieldPtr($context, $slot, 3));
        $readonly = $context->builder->load(self::slotFieldPtr($context, $slot, 4));
        self::abortValueErrorIf(
            $context,
            $fn,
            $context->builder->icmp(Builder::INT_NE, $readonly, $i64->constInt(0, false)),
            'Read-only segment cannot be written',
            'shmop_wr_ro'
        );
        self::abortValueErrorIf(
            $context,
            $fn,
            $context->builder->or(
                $context->builder->icmp(Builder::INT_SLT, $offset, $i64->constInt(0, true)),
                $context->builder->icmp(Builder::INT_SGT, $offset, $segSize)
            ),
            'shmop_write(): Argument #3 ($offset) is out of range',
            'shmop_wr_off'
        );
        $stringMap = $context->structFieldMap['__string__'];
        $dataPtr = $context->builder->structGep($data, $stringMap['value']);
        $dataLen = $context->builder->zext(
            $context->builder->load($context->builder->structGep($data, $stringMap['length'])),
            $i64
        );
        $remain = $context->builder->sub($segSize, $offset);
        $writesize = $context->builder->select(
            $context->builder->icmp(Builder::INT_SLT, $dataLen, $remain),
            $dataLen,
            $remain
        );
        $done = $fn->appendBasicBlock('shmop_wr_done');
        $copy = $fn->appendBasicBlock('shmop_wr_copy');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $writesize, $i64->constInt(1, true)),
            $done,
            $copy
        );
        $context->builder->positionAtEnd($copy);
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->builder->intToPtr($context->builder->add($addr, $offset), $i8p),
            $context->builder->pointerCast($dataPtr, $i8p),
            $context->builder->truncOrBitCast($writesize, $sizeT)
        );
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);
        $context->builder->returnValue($writesize);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function abortValueErrorIf(
        Context $context,
        LlvmFunction $fn,
        Value $cond,
        string $message,
        string $prefix
    ): void {
        $err = $fn->appendBasicBlock($prefix.'_err');
        $ok = $fn->appendBasicBlock($prefix.'_ok');
        $context->builder->branchIf($cond, $err, $ok);
        $context->builder->positionAtEnd($err);
        TypeErrorRaise::emitValueError($context, $message);
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->positionAtEnd($ok);
    }

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after ShmopRuntime link (#28433)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
