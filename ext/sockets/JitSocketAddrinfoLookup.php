<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sockets;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Builtin\StringSocketAddrinfo;
use PHPCompiler\JIT\Builtin\TypeErrorRaise;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableReadLlvm;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitStringArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for socket_addrinfo_lookup() via SocketAddrinfoJitHelper (#31357).
 *
 * php-src: ext/sockets/sockets.c — PHP_FUNCTION(socket_addrinfo_lookup)
 */
final class JitSocketAddrinfoLookup
{
    private static int $blockSerial = 0;

    public static function invoke(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 1 || $argc > 3) {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitArgumentCountError(
                $context,
                $argc < 1
                    ? 'socket_addrinfo_lookup() expects at least 1 argument, '.$argc.' given'
                    : 'socket_addrinfo_lookup() expects at most 3 arguments, '.$argc.' given'
            );
            $slot = JitValueBox::alloc($context);

            return JitValueBox::pointer($context, $slot);
        }

        $host = JitStringArg::lower($context, $args[0], 'socket_addrinfo_lookup() host');
        $service = $argc >= 2
            ? JitStringArg::lower($context, $args[1], 'socket_addrinfo_lookup() service')
            : $context->builder->load($context->constantStringFromString(''));
        [$flags, $family, $socktype, $protocol] = self::lowerHints(
            $context,
            $argc >= 3 ? $args[2] : null
        );

        StringSocketAddrinfo::ensureLinked($context);
        $list = $context->builder->call(
            $context->lookupFunction('__compiler_socket_addrinfo_lookup'),
            $host,
            $service,
            $flags,
            $family,
            $socktype,
            $protocol
        );

        return self::boxedArray($context, $list);
    }

    /**
     * @return array{0: Value, 1: Value, 2: Value, 3: Value}
     */
    private static function lowerHints(Context $context, ?JITVariable $arg): array
    {
        $i64 = $context->getTypeFromString('int64');
        $zero = $i64->constInt(0, false);
        if (null === $arg) {
            return [$zero, $zero, $zero, $zero];
        }

        if (JITVariable::TYPE_HASHTABLE === $arg->type) {
            $ht = $context->helper->loadValue($arg);
        } elseif (JITVariable::TYPE_VALUE === $arg->type) {
            $loaded = JitValueBox::valuePtrFromVariable($context, $arg);
            $ht = $context->builder->call(
                $context->lookupFunction('__value__readHashtable'),
                $loaded
            );
        } else {
            TypeErrorRaise::ensureLinked($context);
            TypeErrorRaise::emitRaise(
                $context,
                'socket_addrinfo_lookup(): Argument #3 ($hints) must be of type array, mixed given'
            );
            $context->builder->call($context->lookupFunction('abort'));

            return [$zero, $zero, $zero, $zero];
        }

        return [
            self::hintLong($context, $ht, 'ai_flags'),
            self::hintLong($context, $ht, 'ai_family'),
            self::hintLong($context, $ht, 'ai_socktype'),
            self::hintLong($context, $ht, 'ai_protocol'),
        ];
    }

    private static function hintLong(Context $context, Value $ht, string $key): Value
    {
        $i64 = $context->getTypeFromString('int64');
        $keyStr = $context->builder->load($context->constantStringFromString($key));
        $box = HashTableReadLlvm::readStringKeyToValueBox($context, $ht, $keyStr);

        return $context->builder->truncOrBitCast(
            JitLongArg::lower($context, $box, 'socket_addrinfo_lookup() hints'),
            $i64
        );
    }

    private static function boxedArray(Context $context, Value $listHt): Value
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');
        $isNull = $context->builder->icmp(Builder::INT_EQ, $listHt, $htPtr->constNull());

        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $id = (string) (++self::$blockSerial);
        $failBlock = BasicBlockHelper::append($context, 'sai_lookup_fail_'.$id);
        $okBlock = BasicBlockHelper::append($context, 'sai_lookup_ok_'.$id);
        $doneBlock = BasicBlockHelper::append($context, 'sai_lookup_done_'.$id);
        $context->builder->branchIf($isNull, $failBlock, $okBlock);

        $context->builder->positionAtEnd($failBlock);
        $i1 = $context->getTypeFromString('int1');
        JitValueBox::writeBool($context, $slot, $i1->constInt(0, false));
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($okBlock);
        $context->builder->call(
            $context->lookupFunction('__value__writeHashtable'),
            $ptr,
            $listHt
        );
        $context->builder->branch($doneBlock);

        $context->builder->positionAtEnd($doneBlock);

        return $ptr;
    }
}
