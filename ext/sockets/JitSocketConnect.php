<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringSocketConnect;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VmVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_connect() via SocketConnectJitHelper::connectArgv (#31240).
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_connect)
 */
final class JitSocketConnect
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2 || $argc > 3) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'socket_connect() expects at least 2 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $handle = self::socketHandle($context, $args[0]);
        $addr = JitStringArg::lower($context, $args[1], 'socket_connect() address');
        [$port, $hasPort] = self::lowerPortArg($context, $argc >= 3 ? $args[2] : null);

        // Resolve handle before NestedJIT ensureLinked — same ordering as socket_close (#27394).
        StringSocketConnect::ensureLinked($context);

        $i64 = $context->getTypeFromString('int64');
        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_socket_connect'),
            $handle,
            $addr,
            $port,
            $hasPort
        );

        // NestedJIT returns -2 when AF_INET/AF_INET6 port was null/omitted (#31240).
        $nullPortBb = BasicBlockHelper::append($context, 'socket_connect_helper_null_port');
        $afterNullPortBb = BasicBlockHelper::append($context, 'socket_connect_after_helper_null_port');
        $isNullPort = $context->builder->icmp(
            Builder::INT_EQ,
            $ok,
            $i64->constInt(-2, true)
        );
        $context->builder->branchIf($isNullPort, $nullPortBb, $afterNullPortBb);

        $context->builder->positionAtEnd($nullPortBb);
        ExceptionBridge::emitValueErrorAndAbort(
            $context,
            'socket_connect(): Argument #3 ($port) cannot be null when the socket type is AF_INET'
        );

        $context->builder->positionAtEnd($afterNullPortBb);
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $i1 = $context->getTypeFromString('int1');
        $truthy = $context->builder->icmp(
            Builder::INT_SGT,
            $ok,
            $i64->constInt(0, false)
        );
        JitValueBox::writeBool($context, $slot, $context->builder->select(
            $truthy,
            $i1->constInt(1, false),
            $i1->constInt(0, false)
        ));

        return $ptr;
    }

    /**
     * @return array{0: Value, 1: Value} [port i64, hasPort i64]
     */
    private static function lowerPortArg(Context $context, ?JITVariable $arg): array
    {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);
        if (null === $arg) {
            return [$zero, $zero];
        }
        if (JITVariable::TYPE_NULL === $arg->type || ($arg->isNullConstant ?? false)) {
            return [$zero, $zero];
        }
        if (JITVariable::TYPE_VALUE === $arg->type) {
            return self::lowerBoxedPort($context, $arg);
        }

        return [
            $context->builder->truncOrBitCast(
                JitLongArg::lower($context, $arg, 'socket_connect() port'),
                $i64
            ),
            $one,
        ];
    }

    /**
     * @return array{0: Value, 1: Value} [port i64, hasPort i64]
     */
    private static function lowerBoxedPort(Context $context, JITVariable $arg): array
    {
        $valuePtr = JitValueBox::valuePtrFromVariable($context, $arg);
        $map = $context->structFieldMap['__value__'];
        $typeByte = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['type'])
        );
        $i8 = $context->getTypeFromString('int8');
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        $one = $i64->constInt(1, false);

        $nullBb = BasicBlockHelper::append($context, 'socket_connect_port_null');
        $presentBb = BasicBlockHelper::append($context, 'socket_connect_port_present');
        $doneBb = BasicBlockHelper::append($context, 'socket_connect_port_done');
        $isNull = $context->builder->icmp(
            Builder::INT_EQ,
            $typeByte,
            $i8->constInt(VmVariable::TYPE_NULL, false)
        );
        $context->builder->branchIf($isNull, $nullBb, $presentBb);

        $context->builder->positionAtEnd($nullBb);
        $nullTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($presentBb);
        $portVal = $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $arg, 'socket_connect() port'),
            $i64
        );
        $presentTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $portPhi = $context->builder->phi($i64);
        $portPhi->addIncoming($zero, $nullTail);
        $portPhi->addIncoming($portVal, $presentTail);
        $hasPhi = $context->builder->phi($i64);
        $hasPhi->addIncoming($zero, $nullTail);
        $hasPhi->addIncoming($one, $presentTail);

        return [$portPhi, $hasPhi];
    }

    private static function socketHandle(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return JitGetObjectId::invoke($context, $arg, 'socket_connect');
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
            'socket_connect(): Argument #1 ($socket) must be of type Socket, mixed given'
        );
        $context->builder->call($context->lookupFunction('abort'));

        return $context->getTypeFromString('int64')->constInt(0, false);
    }
}
