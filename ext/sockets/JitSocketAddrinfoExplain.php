<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\ext\standard\JitGetObjectId;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringSocketAddrinfo;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_addrinfo_explain() via SocketAddrinfoJitHelper (#31357).
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_addrinfo_explain)
 */
final class JitSocketAddrinfoExplain
{
    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (1 !== $argc) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                'socket_addrinfo_explain() expects exactly 1 argument, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $handle = self::addressHandle($context, $args[0]);
        StringSocketAddrinfo::ensureLinked($context);

        $i64 = $context->getTypeFromString('int64');
        $ok = $context->builder->call(
            $context->lookupFunction('__compiler_socket_addrinfo_explain_load'),
            $handle
        );

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $loaded = $context->builder->icmp(
            Builder::INT_SGT,
            $ok,
            $i64->constInt(0, false)
        );
        $failBb = BasicBlockHelper::append($context, 'sai_explain_fail');
        $okBb = BasicBlockHelper::append($context, 'sai_explain_ok');
        $doneBb = BasicBlockHelper::append($context, 'sai_explain_done');
        $context->builder->branchIf($loaded, $okBb, $failBb);

        $context->builder->positionAtEnd($failBb);
        // Missing snapshot — empty explain array (avoid abort under thin AOT; #31357).
        $emptyHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $emptyHt
        );
        $failTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($okBb);
        $outHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        self::setLongKey($context, $outHt, 'ai_flags', '__compiler_socket_addrinfo_explain_flags');
        self::setLongKey($context, $outHt, 'ai_family', '__compiler_socket_addrinfo_explain_family');
        self::setLongKey($context, $outHt, 'ai_socktype', '__compiler_socket_addrinfo_explain_socktype');
        self::setLongKey($context, $outHt, 'ai_protocol', '__compiler_socket_addrinfo_explain_protocol');

        $addrHt = $context->builder->call($context->lookupFunction('__hashtable__alloc'));
        $isInet6 = $context->builder->call(
            $context->lookupFunction('__compiler_socket_addrinfo_explain_inet6')
        );
        $inet6Bb = BasicBlockHelper::append($context, 'sai_explain_inet6');
        $inetBb = BasicBlockHelper::append($context, 'sai_explain_inet');
        $addrDoneBb = BasicBlockHelper::append($context, 'sai_explain_addr_done');
        $context->builder->branchIf(
            $context->builder->icmp(Builder::INT_SGT, $isInet6, $i64->constInt(0, false)),
            $inet6Bb,
            $inetBb
        );

        $context->builder->positionAtEnd($inetBb);
        self::setLongKeyDirect(
            $context,
            $addrHt,
            'sin_port',
            $context->builder->call($context->lookupFunction('__compiler_socket_addrinfo_explain_sin_port'))
        );
        self::setStringKeyDirect(
            $context,
            $addrHt,
            'sin_addr',
            $context->builder->call($context->lookupFunction('__compiler_socket_addrinfo_explain_sin_addr'))
        );
        $context->builder->branch($addrDoneBb);

        $context->builder->positionAtEnd($inet6Bb);
        self::setLongKeyDirect(
            $context,
            $addrHt,
            'sin6_port',
            $context->builder->call($context->lookupFunction('__compiler_socket_addrinfo_explain_sin_port'))
        );
        self::setStringKeyDirect(
            $context,
            $addrHt,
            'sin6_addr',
            $context->builder->call($context->lookupFunction('__compiler_socket_addrinfo_explain_sin_addr'))
        );
        $context->builder->branch($addrDoneBb);

        $context->builder->positionAtEnd($addrDoneBb);
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyHashtable'),
            $outHt,
            $context->builder->load($context->constantStringFromString('ai_addr')),
            $addrHt
        );
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $outHt
        );
        $okTail = $context->builder->getInsertBlock();
        $context->builder->branch($doneBb);

        $context->builder->positionAtEnd($doneBb);
        $ptrTy = $context->getTypeFromString('__value__*');
        $result = $context->builder->phi($ptrTy);
        $result->addIncoming($ptr, $failTail);
        $result->addIncoming($ptr, $okTail);

        return $result;
    }

    private static function setLongKey(Context $context, Value $ht, string $key, string $abi): void
    {
        self::setLongKeyDirect(
            $context,
            $ht,
            $key,
            $context->builder->call($context->lookupFunction($abi))
        );
    }

    private static function setLongKeyDirect(Context $context, Value $ht, string $key, Value $long): void
    {
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyLong'),
            $ht,
            $context->builder->load($context->constantStringFromString($key)),
            $long
        );
    }

    private static function setStringKeyDirect(Context $context, Value $ht, string $key, Value $str): void
    {
        $context->builder->call(
            $context->lookupFunction('__hashtable__setStringKeyString'),
            $ht,
            $context->builder->load($context->constantStringFromString($key)),
            $str
        );
    }

    private static function addressHandle(Context $context, JITVariable $arg): Value
    {
        if (JITVariable::TYPE_OBJECT === $arg->type) {
            return JitGetObjectId::invoke($context, $arg, 'socket_addrinfo_explain');
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
            'socket_addrinfo_explain(): Argument #1 ($address) must be of type AddressInfo, mixed given'
        );
        $context->builder->call($context->lookupFunction('abort'));

        return $context->getTypeFromString('int64')->constInt(0, false);
    }
}
