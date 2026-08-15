<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\JIT\ArrayBuiltinHelper;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringSocketSelect;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_select() via SocketCreateJitHelper select slots (#31355 / #6395).
 *
 * Walks packed Socket arrays, LLVM libc poll, NestedJIT ready slots, rewrites via
 * __hashtable__setObjectAt (peer {@see JitSocketCreatePair}).
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_select)
 */
final class JitSocketSelect
{
    /** Packed int-key sockets per array (covers create_pair repro + typical selects). */
    private const MAX_PER_ARRAY = 8;

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 4 || $argc > 5) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                $argc < 4
                    ? 'socket_select() expects at least 4 arguments, '.$argc.' given'
                    : 'socket_select() expects at most 5 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        StringSocketSelect::ensureLinked($context);
        TypeErrorRaise::ensureLinked($context);

        $i64 = $context->getTypeFromString('int64');
        $i1 = $context->getTypeFromString('int1');
        $seconds = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[3], 'socket_select() seconds'),
            $i64
        );
        $microseconds = 5 === $argc
            ? $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[4], 'socket_select() microseconds'),
                $i64
            )
            : $i64->constInt(0, false);

        $context->builder->call($context->lookupFunction('__compiler_socket_select_reset'));

        $hadAny = $context->builder->alloca($i1);
        $context->builder->store($i1->constInt(0, false), $hadAny);

        self::ingestArrayArg($context, $args[0], 1, 'read', 1, $hadAny);
        self::ingestArrayArg($context, $args[1], 2, 'write', 2, $hadAny);
        self::ingestArrayArg($context, $args[2], 3, 'except', 3, $hadAny);

        $any = $context->builder->load($hadAny);
        $okAnyBb = BasicBlockHelper::append($context, 'socket_select_any_ok');
        $noAnyBb = BasicBlockHelper::append($context, 'socket_select_any_err');
        $context->builder->branchIf($any, $okAnyBb, $noAnyBb);

        $context->builder->positionAtEnd($noAnyBb);
        TypeErrorRaise::emitValueError(
            $context,
            'socket_select(): At least one array argument must be passed'
        );
        $context->builder->call($context->lookupFunction('abort'));
        $context->builder->branch($okAnyBb);

        $context->builder->positionAtEnd($okAnyBb);
        $n = $context->builder->call(
            $context->lookupFunction('__compiler_socket_select_run'),
            $seconds,
            $microseconds
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);

        $isFail = $context->builder->icmp(Builder::INT_EQ, $n, $i64->constInt(-1, true));
        $failBb = BasicBlockHelper::append($context, 'socket_select_fail');
        $okBb = BasicBlockHelper::append($context, 'socket_select_ok');
        $doneBb = BasicBlockHelper::append($context, 'socket_select_done');
        $context->builder->branchIf($isFail, $failBb, $okBb);

        $context->builder->positionAtEnd($failBb);
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        self::rewriteArrayArg($context, $args[0], 1);
        self::rewriteArrayArg($context, $args[1], 2);
        self::rewriteArrayArg($context, $args[2], 3);
        JitValueBox::writeLong($context, $slot, $n);
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($ptr, $failTail);
        $result->addIncoming($ptr, $okTail);

        return $result;
    }

    private static function ingestArrayArg(
        Context $context,
        JITVariable $arg,
        int $argNum,
        string $paramName,
        int $kind,
        Value $hadAnyPtr
    ): void {
        $i1 = $context->getTypeFromString('int1');

        // Compile-time null — skip (php null by-ref write/except).
        if (JITVariable::TYPE_NULL === $arg->type) {
            return;
        }

        if (ArrayBuiltinHelper::isNativeArray($arg->type)) {
            $elemType = $arg->type & ~JITVariable::IS_NATIVE_ARRAY;
            if (JITVariable::TYPE_OBJECT === $elemType) {
                $context->builder->store($i1->constInt(1, false), $hadAnyPtr);
                self::ingestNativeObjectArray($context, $arg, $argNum, $paramName, $kind);

                return;
            }
        }

        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            $context->builder->store($i1->constInt(1, false), $hadAnyPtr);
            $ht = ArrayBuiltinHelper::loadHashTable($context, $arg);
            self::ingestPackedHt($context, $ht, $argNum, $paramName, $kind);

            return;
        }

        // Boxed value — runtime null vs array via coerce.
        if (JITVariable::TYPE_VALUE === $arg->type || JitValueBox::isValueOperand($arg)) {
            $i8 = $context->getTypeFromString('int8');
            $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
            $map = $context->structFieldMap['__value__'];
            $type = $context->builder->load($context->builder->structGep($valuePtr, $map['type']));
            $typeKind = $context->builder->and($type, $i8->constInt(0x7f, false));
            $isNull = $context->builder->icmp(
                Builder::INT_EQ,
                $typeKind,
                $i8->constInt(VMVariable::TYPE_NULL, false)
            );
            $nullBb = BasicBlockHelper::append($context, 'socket_select_arg'.$argNum.'_null');
            $goBb = BasicBlockHelper::append($context, 'socket_select_arg'.$argNum.'_go');
            $contBb = BasicBlockHelper::append($context, 'socket_select_arg'.$argNum.'_cont');
            $context->builder->branchIf($isNull, $nullBb, $goBb);
            $context->builder->positionAtEnd($nullBb);
            $context->builder->branch($contBb);
            $context->builder->positionAtEnd($goBb);
            $context->builder->store($i1->constInt(1, false), $hadAnyPtr);
            $htVar = \PHPCompiler\JIT\HashTableHelper::coerceToPackedHashtable($context, $arg);
            self::ingestPackedHt($context, $context->helper->loadValue($htVar), $argNum, $paramName, $kind);
            $context->builder->branch($contBb);
            $context->builder->positionAtEnd($contBb);

            return;
        }

        TypeErrorRaise::emitRaise(
            $context,
            \sprintf(
                'socket_select(): Argument #%d ($%s) must be of type ?array, mixed given',
                $argNum,
                $paramName
            )
        );
        $context->builder->call($context->lookupFunction('abort'));
    }

    private static function ingestNativeObjectArray(
        Context $context,
        JITVariable $arg,
        int $argNum,
        string $paramName,
        int $kind
    ): void {
        // Prefer HT ingest — nextFreeElement bounds the native list; probing MAX
        // reads uninitialized slots and aborts on garbage "Socket" pointers (#31355).
        $ht = ArrayBuiltinHelper::nativeListToHashTable($context, $arg);
        self::ingestPackedHt($context, $ht, $argNum, $paramName, $kind);
    }

    private static function ingestPackedHt(
        Context $context,
        Value $ht,
        int $argNum,
        string $paramName,
        int $kind
    ): void {
        $i64 = $context->getTypeFromString('int64');
        $i8 = $context->getTypeFromString('int8');
        $sizeT = $context->getTypeFromString('size_t');
        $map = $context->structFieldMap['__value__'];
        $n = $context->builder->call(
            $context->lookupFunction('__hashtable__getNumElements'),
            $ht
        );
        $htMap = $context->structFieldMap['__hashtable__'];
        $values = $context->builder->load($context->builder->structGep($ht, $htMap['values']));

        for ($i = 0; $i < self::MAX_PER_ARRAY; ++$i) {
            $idx = $sizeT->constInt($i, false);
            $inRange = $context->builder->icmp(Builder::INT_ULT, $idx, $n);
            $takeBb = BasicBlockHelper::append($context, 'socket_select_arg'.$argNum.'_i'.$i);
            $skipBb = BasicBlockHelper::append($context, 'socket_select_arg'.$argNum.'_s'.$i);
            $context->builder->branchIf($inRange, $takeBb, $skipBb);

            $context->builder->positionAtEnd($takeBb);
            $isSet = $context->builder->call(
                $context->lookupFunction('__hashtable__offsetIsSet'),
                $ht,
                $idx
            );
            $setBb = BasicBlockHelper::append($context, 'socket_select_arg'.$argNum.'_set'.$i);
            $context->builder->branchIf($isSet, $setBb, $skipBb);

            $context->builder->positionAtEnd($setBb);
            $entry = $context->builder->inBoundsGep($values, $idx);
            // Prefer object read; type tags on materialized/native cells vary (#28661).
            $obj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                $entry
            );
            $objNull = $context->builder->icmp(
                Builder::INT_EQ,
                $obj,
                $obj->typeOf()->constNull()
            );
            $elemBadBb = BasicBlockHelper::append($context, 'socket_select_arg'.$argNum.'_ebad'.$i);
            $objBb = BasicBlockHelper::append($context, 'socket_select_arg'.$argNum.'_obj'.$i);
            $context->builder->branchIf($objNull, $elemBadBb, $objBb);

            $context->builder->positionAtEnd($elemBadBb);
            TypeErrorRaise::emitRaise(
                $context,
                \sprintf(
                    'socket_select(): Argument #%d ($%s) must only have elements of type Socket, mixed given',
                    $argNum,
                    $paramName
                )
            );
            $context->builder->call($context->lookupFunction('abort'));
            $context->builder->branch($skipBb);

            $context->builder->positionAtEnd($objBb);
            $voidp = $context->getTypeFromString('void')->pointerType(0);
            $handle = $context->builder->ptrToInt(
                $context->builder->pointerCast($obj, $voidp),
                $i64
            );
            $addRc = $context->builder->call(
                $context->lookupFunction('__compiler_socket_select_add'),
                $handle,
                $i64->constInt($kind, false),
                $i64->constInt($i, false)
            );
            $addOk = $context->builder->icmp(Builder::INT_EQ, $addRc, $i64->constInt(0, false));
            $addFailBb = BasicBlockHelper::append($context, 'socket_select_arg'.$argNum.'_af'.$i);
            $context->builder->branchIf($addOk, $skipBb, $addFailBb);

            $context->builder->positionAtEnd($addFailBb);
            TypeErrorRaise::emitRaise(
                $context,
                'socket_select(): supplied resource is not a valid Socket resource'
            );
            $context->builder->call($context->lookupFunction('abort'));
            $context->builder->branch($skipBb);

            $context->builder->positionAtEnd($skipBb);
        }
    }

    private static function rewriteArrayArg(Context $context, JITVariable $arg, int $kind): void
    {
        if (JITVariable::TYPE_NULL === $arg->type) {
            return;
        }

        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $objTy = $context->getTypeFromString('__object__*');

        $isNative = JITVariable::TYPE_HASHTABLE === $arg->type
            || ArrayBuiltinHelper::isNativeArray($arg->type);

        if ($isNative) {
            $doBb = BasicBlockHelper::append($context, 'socket_select_rw_k'.$kind);
            $skipBb = BasicBlockHelper::append($context, 'socket_select_rw_skip_k'.$kind);
            $context->builder->branch($doBb);
            $context->builder->positionAtEnd($doBb);
            $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
            self::fillReadyHt($context, $ht, $kind);
            // Prefer HashTableHelper store for native/by-ref arrays.
            \PHPCompiler\JIT\HashTableHelper::storeHashtableInArrayVariable($context, $arg, $ht);
            $context->refcount->addref($ht);
            $context->builder->branch($skipBb);
            $context->builder->positionAtEnd($skipBb);

            return;
        }

        $i8 = $context->getTypeFromString('int8');
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $type = $context->builder->load($context->builder->structGep($valuePtr, $map['type']));
        $typeKind = $context->builder->and($type, $i8->constInt(0x7f, false));

        $isArray6 = $context->builder->icmp(
            Builder::INT_EQ,
            $typeKind,
            $i8->constInt(VMVariable::TYPE_ARRAY, false)
        );
        $isArray7 = $context->builder->icmp(
            Builder::INT_EQ,
            $typeKind,
            $i8->constInt(VMVariable::TYPE_INDIRECT, false)
        );
        $isArray = $context->builder->or($isArray6, $isArray7);
        $doBb = BasicBlockHelper::append($context, 'socket_select_rw_k'.$kind);
        $skipBb = BasicBlockHelper::append($context, 'socket_select_rw_skip_k'.$kind);
        $context->builder->branchIf($isArray, $doBb, $skipBb);

        $context->builder->positionAtEnd($doBb);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        self::fillReadyHt($context, $ht, $kind);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $valuePtr,
            $ht
        );
        $context->refcount->addref($ht);
        $context->builder->branch($skipBb);

        $context->builder->positionAtEnd($skipBb);
    }

    private static function fillReadyHt(Context $context, Value $ht, int $kind): void
    {
        $i64 = $context->getTypeFromString('int64');
        $sizeT = $context->getTypeFromString('size_t');
        $objTy = $context->getTypeFromString('__object__*');
        $readyN = $context->builder->call(
            $context->lookupFunction('__compiler_socket_select_ready_count')
        );
        $maxReady = self::MAX_PER_ARRAY * 3;
        for ($j = 0; $j < $maxReady; ++$j) {
            $jVal = $i64->constInt($j, false);
            $inRange = $context->builder->icmp(Builder::INT_SLT, $jVal, $readyN);
            $takeBb = BasicBlockHelper::append($context, 'socket_select_rw_k'.$kind.'_j'.$j);
            $nextBb = BasicBlockHelper::append($context, 'socket_select_rw_k'.$kind.'_n'.$j);
            $context->builder->branchIf($inRange, $takeBb, $nextBb);

            $context->builder->positionAtEnd($takeBb);
            $rk = $context->builder->call(
                $context->lookupFunction('__compiler_socket_select_ready_kind'),
                $jVal
            );
            $kindMatch = $context->builder->icmp(
                Builder::INT_EQ,
                $rk,
                $i64->constInt($kind, false)
            );
            $matchBb = BasicBlockHelper::append($context, 'socket_select_rw_k'.$kind.'_m'.$j);
            $context->builder->branchIf($kindMatch, $matchBb, $nextBb);

            $context->builder->positionAtEnd($matchBb);
            $handle = $context->builder->call(
                $context->lookupFunction('__compiler_socket_select_ready_handle'),
                $jVal
            );
            $key = $context->builder->call(
                $context->lookupFunction('__compiler_socket_select_ready_key'),
                $jVal
            );
            $obj = $context->builder->intToPtr($handle, $objTy);
            $destKey = $context->builder->truncOrBitCast($key, $sizeT);
            $context->builder->call(
                $context->lookupFunction('__hashtable__setObjectAt'),
                $ht,
                $destKey,
                $obj
            );
            $context->builder->branch($nextBb);

            $context->builder->positionAtEnd($nextBb);
        }
    }
}
