<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringSocketPairIo;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_create_pair() via SocketPairIoJitHelper (#27423).
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_create_pair)
 */
final class JitSocketCreatePair
{
    public static function invoke(
        Context $context,
        JITVariable $domainArg,
        JITVariable $typeArg,
        JITVariable $protocolArg,
        JITVariable $pairArg
    ): Value {
        StringSocketPairIo::ensureLinked($context);

        $i64 = $context->getTypeFromString('int64');
        $domain = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $domainArg, 'socket_create_pair() domain'),
            $i64
        );
        $type = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $typeArg, 'socket_create_pair() type'),
            $i64
        );
        $protocol = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $protocolArg, 'socket_create_pair() protocol'),
            $i64
        );

        $obj0 = self::allocateSocketObject($context);
        $obj1 = self::allocateSocketObject($context);
        $voidp = $context->getTypeFromString('void')->pointerType(0);
        $addr0 = $context->builder->ptrToInt($context->builder->pointerCast($obj0, $voidp), $i64);
        $addr1 = $context->builder->ptrToInt($context->builder->pointerCast($obj1, $voidp), $i64);

        $i32 = $context->getTypeFromString('int32');
        $okI32 = $context->builder->call(
            $context->lookupFunction('__compiler_socket_create_pair'),
            $domain,
            $type,
            $protocol,
            $addr0,
            $addr1
        );
        $ok = $context->builder->icmp(Builder::INT_NE, $okI32, $i32->constInt(0, false));

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $failBb = BasicBlockHelper::append($context, 'socket_pair_fail');
        $okBb = BasicBlockHelper::append($context, 'socket_pair_ok');
        $doneBb = BasicBlockHelper::append($context, 'socket_pair_done');
        $context->builder->branchIf($ok, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(0, false));
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $ht = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $sizeT = $context->getTypeFromString('size_t');
        $context->builder->call(
            $context->lookupFunction('__hashtable__setObjectAt'),
            $ht,
            $sizeT->constInt(0, false),
            $obj0
        );
        $context->builder->call(
            $context->lookupFunction('__hashtable__setObjectAt'),
            $ht,
            $sizeT->constInt(1, false),
            $obj1
        );
        $pairPtr = JitValueBox::valuePtrFromVariable($context, $pairArg);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $pairPtr,
            $ht
        );
        $context->refcount->addref($ht);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(1, false));
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($ptr, $failTail);
        $result->addIncoming($ptr, $okTail);

        return $result;
    }

    private static function allocateSocketObject(Context $context): Value
    {
        $objectType = $context->type->object;
        $classId = $objectType->lookup('Socket');
        $obj = $objectType->allocate($classId);
        $objectType->markObjectConstructed($obj);

        return $obj;
    }

    public static function emitArgumentCountError(Context $context, int $argc): Value
    {
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitArgumentCountError(
            $context,
            'socket_create_pair() expects exactly 4 arguments, '.$argc.' given'
        );
        $slot = JitValueBox::alloc($context);

        return JitValueBox::pointer($context, $slot);
    }
}
