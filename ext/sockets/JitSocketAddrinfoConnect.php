<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringSocketAddrinfo;
use PHPCompiler\JIT\Builtin\StringSocketCreate;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_addrinfo_connect() via SocketAddrinfoJitHelper (#31357).
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_addrinfo_connect)
 */
final class JitSocketAddrinfoConnect
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        return self::invokeOp($context, $args, 0, 'socket_addrinfo_connect');
    }

    /**
     * @param list<JITVariable> $args
     * @param int               $op   0=connect, 1=bind
     */
    public static function invokeOp(Context $context, array $args, int $op, string $fnName): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                $fnName.'() expects exactly 1 argument, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $handle = self::addressHandle($context, $args[0], $fnName);
        StringSocketAddrinfo::ensureLinked($context);
        StringSocketCreate::ensureLinked($context);

        $i64 = $context->getTypeFromString('int64');
        $fd = $context->builder->call(
            $context->lookupFunction('__compiler_socket_addrinfo_socket_fd'),
            $handle,
            $i64->constInt($op, false)
        );
        $ok = $context->builder->icmp(Builder::INT_SGE, $fd, $i64->constInt(0, false));

        $failBb = BasicBlockHelper::append($context, 'sai_sock_fail');
        $okBb = BasicBlockHelper::append($context, 'sai_sock_ok');
        $doneBb = BasicBlockHelper::append($context, 'sai_sock_done');
        $context->builder->branchIf($ok, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        $falseSlot = JitValueBox::alloc($context);
        $falsePtr = JitValueBox::pointer($context, $falseSlot);
        JitValueBox::writeBool($context, $falseSlot, $context->getTypeFromString('int1')->constInt(0, false));
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $domain = $context->builder->call(
            $context->lookupFunction('__compiler_socket_addrinfo_domain'),
            $handle
        );
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
            $domain
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

    private static function addressHandle(Context $context, JITVariable $arg, string $fnName): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return JitGetObjectId::invoke($context, $arg, $fnName);
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            $loaded = JitValueBox::valuePtrFromVariable($context, $arg);
            $obj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                $loaded
            );
            $voidp = $context->getTypeFromString('void')->pointerType(0);
            $i64 = $context->getTypeFromString('int64');

            return $context->builder->ptrToInt(
                $context->builder->pointerCast($obj, $voidp),
                $i64
            );
        }
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitRaise(
            $context,
            $fnName.'(): Argument #1 ($address) must be of type AddressInfo, mixed given'
        );
        $context->builder->call($context->lookupFunction('abort'));

        return $context->getTypeFromString('int64')->constInt(0, false);
    }
}
