<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringSocketCreate;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_create() via SocketCreateJitHelper (#27394).
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_create)
 */
final class JitSocketCreate
{
    public static function invoke(
        Context $context,
        JITVariable $domainArg,
        JITVariable $typeArg,
        JITVariable $protocolArg
    ): Value {
        StringSocketCreate::ensureLinked($context);

        $i64 = $context->getTypeFromString('int64');
        $domain = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $domainArg, 'socket_create() domain'),
            $i64
        );
        $type = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $typeArg, 'socket_create() type'),
            $i64
        );
        $protocol = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $protocolArg, 'socket_create() protocol'),
            $i64
        );

        self::guardDomain($context, $domain);

        $fd = $context->builder->call(
            $context->lookupFunction('__compiler_socket_create_fd'),
            $domain,
            $type,
            $protocol
        );
        $zero = $i64->constInt(0, false);
        $ok = $context->builder->icmp(Builder::INT_SGE, $fd, $zero);

        $failBb = BasicBlockHelper::append($context, 'socket_create_fail');
        $okBb = BasicBlockHelper::append($context, 'socket_create_ok');
        $doneBb = BasicBlockHelper::append($context, 'socket_create_done');
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

    private static function guardDomain(Context $context, Value $domain): void
    {
        $i64 = $context->getTypeFromString('int64');
        $isUnix = $context->builder->icmp(
            Builder::INT_EQ,
            $domain,
            $i64->constInt(VmSockets::AF_UNIX, false)
        );
        $isInet = $context->builder->icmp(
            Builder::INT_EQ,
            $domain,
            $i64->constInt(VmSockets::AF_INET, false)
        );
        $isInet6 = $context->builder->icmp(
            Builder::INT_EQ,
            $domain,
            $i64->constInt(VmSockets::AF_INET6, false)
        );
        $ok = $context->builder->or($isUnix, $context->builder->or($isInet, $isInet6));

        $okBb = BasicBlockHelper::append($context, 'socket_create_domain_ok');
        $errBb = BasicBlockHelper::append($context, 'socket_create_domain_err');
        $context->builder->branchIf($ok, $okBb, $errBb);

        $context->builder->positionAtEnd($errBb);
        TypeErrorRaise::ensureLinked($context);
        TypeErrorRaise::emitValueError(
            $context,
            'socket_create(): Argument #1 ($domain) must be one of AF_UNIX, AF_INET6, or AF_INET'
        );
        $context->builder->call($context->lookupFunction('abort'));

        $context->builder->positionAtEnd($okBb);
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
            'socket_create() expects exactly 3 arguments, '.$argc.' given'
        );
        $slot = JitValueBox::alloc($context);

        return JitValueBox::pointer($context, $slot);
    }
}
