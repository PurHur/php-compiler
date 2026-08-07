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
 * JIT/AOT link for msg_get_queue/send/receive/remove (#28432).
 *
 * Full LLVM path — NestedJIT FFI unreliable under thin AOT (peer #28431 / #28433).
 * Raw string send/receive only (serialize/unserialize=false); php-src: ext/sysvmsg/sysvmsg.c
 */
final class MsgRuntime
{
    private const IPC_CREAT = 512;

    private const IPC_EXCL = 1024;

    private const IPC_RMID = 0;

    private const IPC_NOWAIT = 2048;

    /** sizeof(long) on Linux x86_64 — mtype field */
    private const MTYPE_SIZE = 8;

    private const MAP_SLOTS = 32;

    /** Slot: obj, msqid — 2 × i64 */
    private const SLOT_FIELDS = 2;

    /** @var list<string> */
    private const ABI_FUNCTIONS = [
        '__compiler_msg_get_register',
        '__compiler_msg_send',
        '__compiler_msg_receive',
        '__compiler_msg_remove',
    ];

    public static function ensureLinked(Context $context): void
    {
        $probe = $context->module->getNamedFunction('__compiler_msg_get_register');
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            self::registerLinkedRuntime($context);

            return;
        }

        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }

        self::ensureLibcMsg($context);
        LibcExtern::register($context);
        self::ensureMapGlobal($context);
        self::implementGetRegisterBridge($context);
        self::implementSendBridge($context);
        self::implementReceiveBridge($context);
        self::implementRemoveBridge($context);
        self::registerLinkedRuntime($context);

        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        } else {
            $context->builder->clearInsertionPosition();
        }
    }

    private static function ensureLibcMsg(Context $context): void
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        foreach (
            [
                'msgget' => [$i32, false, [$i32, $i32]],
                'msgctl' => [$i32, false, [$i32, $i32, $i8p]],
                'msgsnd' => [$i32, false, [$i32, $i8p, $sizeT, $i32]],
                'msgrcv' => [$i64, false, [$i32, $i8p, $sizeT, $i64, $i32]],
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
        $name = '__compiler_msg_owned_map';
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
        $g = $context->module->getNamedGlobal('__compiler_msg_owned_map');
        if (null === $g) {
            throw new \LogicException('msg owned map global missing (#28432)');
        }

        return $g;
    }

    private static function slotFieldPtr(Context $context, Value $index, int $field): Value
    {
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $map = self::mapGlobal($context);
        $base = $context->builder->mul($index, $i64->constInt(self::SLOT_FIELDS, false));
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
        $context->builder->store($context->builder->add($idx, $i64->constInt(1, false)), $idxSlot);
        $context->builder->branch($loop);
        $context->builder->positionAtEnd($miss);
        $context->builder->store($i64->constInt(-1, true), $result);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        return $context->builder->load($result);
    }

    private static function emitAllocSlot(Context $context, LlvmFunction $fn, string $prefix): Value
    {
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
        $context->builder->store($context->builder->add($idx, $i64->constInt(1, false)), $idxSlot);
        $context->builder->branch($loop);
        $context->builder->positionAtEnd($miss);
        $context->builder->store($i64->constInt(-1, true), $result);
        $context->builder->branch($done);
        $context->builder->positionAtEnd($done);

        return $context->builder->load($result);
    }

    private static function implementGetRegisterBridge(Context $context): void
    {
        $abiName = '__compiler_msg_get_register';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, $i64, $i64, $i64)
            );
        $entry = $fn->appendBasicBlock('msg_gr_entry');
        $context->builder->positionAtEnd($entry);
        $objAddr = $fn->getParam(0);
        $key = $context->builder->trunc($fn->getParam(1), $i32);
        $perm = $context->builder->trunc($fn->getParam(2), $i32);

        $existing = $context->builder->call(
            $context->lookupFunction('msgget'),
            $key,
            $i32->constInt(0, false)
        );
        $idSlot = BasicBlockHelper::entryAlloca($context, $i32);
        $context->builder->store($existing, $idSlot);
        $have = $fn->appendBasicBlock('msg_gr_have');
        $create = $fn->appendBasicBlock('msg_gr_create');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $existing, $i32->constInt(0, true)),
            $have,
            $create
        );
        $context->builder->positionAtEnd($create);
        $created = $context->builder->call(
            $context->lookupFunction('msgget'),
            $key,
            $context->builder->or(
                $perm,
                $i32->constInt(self::IPC_CREAT | self::IPC_EXCL, false)
            )
        );
        $context->builder->store($created, $idSlot);
        $context->builder->branch($have);
        $context->builder->positionAtEnd($have);
        $msqid = $context->builder->load($idSlot);
        $fail = $fn->appendBasicBlock('msg_gr_fail');
        $ok = $fn->appendBasicBlock('msg_gr_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $msqid, $i32->constInt(0, true)),
            $ok,
            $fail
        );
        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(0, false));
        $context->builder->positionAtEnd($ok);
        $slot = self::emitAllocSlot($context, $fn, 'msg_gr');
        $noSlot = $fn->appendBasicBlock('msg_gr_noslot');
        $reg = $fn->appendBasicBlock('msg_gr_reg');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SLT, $slot, $i64->constInt(0, true)),
            $noSlot,
            $reg
        );
        $context->builder->positionAtEnd($noSlot);
        $context->builder->returnValue($i64->constInt(0, false));
        $context->builder->positionAtEnd($reg);
        $context->builder->store($objAddr, self::slotFieldPtr($context, $slot, 0));
        $context->builder->store($context->builder->sext($msqid, $i64), self::slotFieldPtr($context, $slot, 1));
        $context->builder->returnValue($i64->constInt(1, false));
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementSendBridge(Context $context): void
    {
        $abiName = '__compiler_msg_send';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        LibcExtern::register($context);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $sizeT = $context->getTypeFromString('size_t');
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType($i64, false, $i64, $i64, $strPtr, $i64)
            );
        $entry = $fn->appendBasicBlock('msg_snd_entry');
        $context->builder->positionAtEnd($entry);
        $handle = $fn->getParam(0);
        $mtype = $fn->getParam(1);
        $data = $fn->getParam(2);
        $blocking = $fn->getParam(3);
        $slot = self::emitFindSlot($context, $fn, $handle, 'msg_snd');
        $fail = $fn->appendBasicBlock('msg_snd_fail');
        $ok = $fn->appendBasicBlock('msg_snd_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $slot, $i64->constInt(0, true)),
            $ok,
            $fail
        );
        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(0, false));
        $context->builder->positionAtEnd($ok);
        $msqid = $context->builder->trunc(
            $context->builder->load(self::slotFieldPtr($context, $slot, 1)),
            $i32
        );
        $stringMap = $context->structFieldMap['__string__'];
        $dataPtr = $context->builder->structGep($data, $stringMap['value']);
        $dataLen = $context->builder->zext(
            $context->builder->load($context->builder->structGep($data, $stringMap['length'])),
            $i64
        );
        // buf size = MTYPE_SIZE + len + 1 (NUL like php-src)
        $bufSize = $context->builder->add(
            $context->builder->add($dataLen, $i64->constInt(self::MTYPE_SIZE, false)),
            $i64->constInt(1, false)
        );
        $raw = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $context->builder->truncOrBitCast($bufSize, $sizeT)
        );
        $mtypePtr = $context->builder->pointerCast($raw, $i64->pointerType(0));
        $context->builder->store($mtype, $mtypePtr);
        $textPtr = $context->builder->gep($raw, $i32->constInt(self::MTYPE_SIZE, false));
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $textPtr,
            $context->builder->pointerCast($dataPtr, $i8p),
            $context->builder->truncOrBitCast($dataLen, $sizeT)
        );
        // NUL terminate like php-src
        $nulPtr = $context->builder->gep(
            $raw,
            $context->builder->trunc(
                $context->builder->add($dataLen, $i64->constInt(self::MTYPE_SIZE, false)),
                $i32
            )
        );
        $context->builder->store($i8->constInt(0, false), $nulPtr);
        $flags = $context->builder->select(
            $context->builder->icmp(Builder::INT_NE, $blocking, $i64->constInt(0, false)),
            $i32->constInt(0, false),
            $i32->constInt(self::IPC_NOWAIT, false)
        );
        $rc = $context->builder->call(
            $context->lookupFunction('msgsnd'),
            $msqid,
            $raw,
            $context->builder->truncOrBitCast($dataLen, $sizeT),
            $flags
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $raw);
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

    private static function implementReceiveBridge(Context $context): void
    {
        $abiName = '__compiler_msg_receive';
        $probe = $context->module->getNamedFunction($abiName);
        if (null !== $probe && $probe->countBasicBlocks() > 0) {
            $context->registerFunction($abiName, $probe);

            return;
        }
        LibcExtern::register($context);
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $strPtr = $context->getTypeFromString('__string__*');
        $sizeT = $context->getTypeFromString('size_t');
        // handle, desired_type, max_size -> returns string*; writes type via out_type* (i64*)
        $fn = null !== $probe
            ? $probe
            : $context->module->addFunction(
                $abiName,
                $context->context->functionType(
                    $strPtr,
                    false,
                    $i64,
                    $i64,
                    $i64,
                    $i64->pointerType(0)
                )
            );
        $entry = $fn->appendBasicBlock('msg_rcv_entry');
        $context->builder->positionAtEnd($entry);
        $handle = $fn->getParam(0);
        $desired = $fn->getParam(1);
        $maxSize = $fn->getParam(2);
        $outType = $fn->getParam(3);
        $slot = self::emitFindSlot($context, $fn, $handle, 'msg_rcv');
        $fail = $fn->appendBasicBlock('msg_rcv_fail');
        $ok = $fn->appendBasicBlock('msg_rcv_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $slot, $i64->constInt(0, true)),
            $ok,
            $fail
        );
        $context->builder->positionAtEnd($fail);
        $context->builder->store($i64->constInt(0, false), $outType);
        $context->builder->returnValue(
            $context->builder->call(
                $context->lookupFunction('__string__alloc'),
                $sizeT->constInt(0, false)
            )
        );
        $context->builder->positionAtEnd($ok);
        $msqid = $context->builder->trunc(
            $context->builder->load(self::slotFieldPtr($context, $slot, 1)),
            $i32
        );
        $bufSize = $context->builder->add($maxSize, $i64->constInt(self::MTYPE_SIZE + 1, false));
        $raw = $context->builder->call(
            $context->lookupFunction('__mm__malloc'),
            $context->builder->truncOrBitCast($bufSize, $sizeT)
        );
        $n = $context->builder->call(
            $context->lookupFunction('msgrcv'),
            $msqid,
            $raw,
            $context->builder->truncOrBitCast($maxSize, $sizeT),
            $desired,
            $i32->constInt(0, false)
        );
        $rcvFail = $fn->appendBasicBlock('msg_rcv_rcvfail');
        $rcvOk = $fn->appendBasicBlock('msg_rcv_rcvok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $n, $i64->constInt(0, true)),
            $rcvOk,
            $rcvFail
        );
        $context->builder->positionAtEnd($rcvFail);
        $context->builder->call($context->lookupFunction('__mm__free'), $raw);
        $context->builder->store($i64->constInt(0, false), $outType);
        $context->builder->returnValue(
            $context->builder->call($context->lookupFunction('__string__alloc'), $sizeT->constInt(0, false))
        );
        $context->builder->positionAtEnd($rcvOk);
        $mtypePtr = $context->builder->pointerCast($raw, $i64->pointerType(0));
        $context->builder->store($context->builder->load($mtypePtr), $outType);
        $nSize = $context->builder->truncOrBitCast($n, $sizeT);
        $str = $context->builder->call($context->lookupFunction('__string__alloc'), $nSize);
        $stringMap = $context->structFieldMap['__string__'];
        $dst = $context->builder->structGep($str, $stringMap['value']);
        $src = $context->builder->gep($raw, $context->getTypeFromString('int32')->constInt(self::MTYPE_SIZE, false));
        $context->builder->call(
            $context->lookupFunction('memcpy'),
            $context->builder->pointerCast($dst, $i8p),
            $src,
            $nSize
        );
        $context->builder->call($context->lookupFunction('__mm__free'), $raw);
        $context->builder->returnValue($str);
        $context->registerFunction($abiName, $fn);
        $context->builder->clearInsertionPosition();
    }

    private static function implementRemoveBridge(Context $context): void
    {
        $abiName = '__compiler_msg_remove';
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
        $entry = $fn->appendBasicBlock('msg_rm_entry');
        $context->builder->positionAtEnd($entry);
        $slot = self::emitFindSlot($context, $fn, $fn->getParam(0), 'msg_rm');
        $fail = $fn->appendBasicBlock('msg_rm_fail');
        $ok = $fn->appendBasicBlock('msg_rm_ok');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGE, $slot, $i64->constInt(0, true)),
            $ok,
            $fail
        );
        $context->builder->positionAtEnd($fail);
        $context->builder->returnValue($i64->constInt(0, false));
        $context->builder->positionAtEnd($ok);
        $msqid = $context->builder->trunc(
            $context->builder->load(self::slotFieldPtr($context, $slot, 1)),
            $i32
        );
        $rc = $context->builder->call(
            $context->lookupFunction('msgctl'),
            $msqid,
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

    private static function registerLinkedRuntime(Context $context): void
    {
        foreach (self::ABI_FUNCTIONS as $name) {
            $fn = $context->module->getNamedFunction($name);
            if (null === $fn) {
                throw new \LogicException($name.' missing after MsgRuntime link (#28432)');
            }
            $context->registerFunction($name, $fn);
        }
    }
}
