<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringSocketCreateListen;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_create_listen() via SocketCreateJitHelper (#31242).
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_create_listen)
 */
final class JitSocketCreateListen
{
    /** php-src 8.2 stub default (8.4+ uses SOMAXCONN). */
    private const DEFAULT_BACKLOG = 128;

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 2) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                $argc < 1
                    ? 'socket_create_listen() expects at least 1 argument, '.$argc.' given'
                    : 'socket_create_listen() expects at most 2 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        StringSocketCreateListen::ensureLinked($context);

        $i64 = $context->getTypeFromString('int64');
        $port = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $args[0], 'socket_create_listen() port'),
            $i64
        );
        $backlog = $argc >= 2
            ? $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $args[1], 'socket_create_listen() backlog'),
                $i64
            )
            : $i64->constInt(self::DEFAULT_BACKLOG, false);

        $fd = $context->builder->call(
            $context->lookupFunction('__compiler_socket_create_listen_fd'),
            $port,
            $backlog
        );
        $zero = $i64->constInt(0, false);
        $ok = $context->builder->icmp(Builder::INT_SGE, $fd, $zero);

        $failBb = BasicBlockHelper::append($context, 'socket_create_listen_fail');
        $okBb = BasicBlockHelper::append($context, 'socket_create_listen_ok');
        $doneBb = BasicBlockHelper::append($context, 'socket_create_listen_done');
        $context->builder->branchIf($ok, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $falseSlot = JitValueBox::alloc($context);
        $falsePtr = JitValueBox::pointer($context, $falseSlot);
        JitValueBox::writeBool($context, $falseSlot, $context->getTypeFromString('int1')->constInt(0, false));
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $objPtr = self::allocateSocketObject($context);
        $voidp = $context->getTypeFromString('void')->pointerType(0);
        $objAddr = $context->builder->ptrToInt(
            $context->builder->pointerCast($objPtr, $voidp),
            $i64
        );
        $context->builder->call(
            $context->lookupFunction('__compiler_socket_create_register'),
            $objAddr,
            $fd,
            $i64->constInt(VmSockets::AF_INET, false)
        );
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call(
            $context->lookupFunction('__value__writeObject'),
            $ptr,
            $objPtr
        );
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($falsePtr, $failTail);
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
}
