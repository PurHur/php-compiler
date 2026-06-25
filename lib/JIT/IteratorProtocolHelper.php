<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPCompiler\VM\VmIteratorProtocol;
use PHPLLVM\Value;

/**
 * JIT trampoline for Iterator / IteratorAggregate protocol (Zend zend_iterators.c, #10240).
 *
 * SSOT: {@see \PHPCompiler\VM\VmIteratorProtocol}
 */
final class IteratorProtocolHelper
{
    public static function normalizeObjectReceiver(Context $context, Variable $iterable): Variable
    {
        return VmIteratorProtocol::normalizeObjectReceiver($context, $iterable);
    }

    public static function canLowerIteratorProtocol(
        Context $context,
        Variable $container,
        ?string $containerUserType
    ): bool {
        return VmIteratorProtocol::canLowerIteratorProtocol($context, $container, $containerUserType);
    }

    public static function receiverSlot(Context $context, Variable $slotKey): Value
    {
        return VmIteratorProtocol::receiverSlot($context, $slotKey);
    }

    public static function advanceSlot(Context $context, Variable $slotKey): Value
    {
        return VmIteratorProtocol::advanceSlot($context, $slotKey);
    }

    public static function storeReceiver(Context $context, Variable $slotKey, Variable $receiver): void
    {
        VmIteratorProtocol::storeReceiver($context, $slotKey, $receiver);
    }

    public static function loadReceiver(Context $context, Variable $slotKey): Variable
    {
        return VmIteratorProtocol::loadReceiver($context, $slotKey);
    }

    public static function resolveForeachReceiver(
        Context $context,
        Variable $container,
        ?string $containerUserType
    ): Variable {
        return VmIteratorProtocol::resolveForeachReceiver($context, $container, $containerUserType);
    }

    public static function compileForeachReset(
        Context $context,
        Variable $container,
        Variable $slotKey,
        ?string $containerUserType
    ): void {
        VmIteratorProtocol::compileForeachReset($context, $container, $slotKey, $containerUserType);
    }

    public static function compileForeachValid(
        Context $context,
        Variable $slotKey,
        ?string $containerUserType = null
    ): Value {
        return VmIteratorProtocol::compileForeachValid($context, $slotKey, $containerUserType);
    }

    public static function compileForeachKey(
        Context $context,
        Variable $slotKey,
        ?string $containerUserType = null
    ): Variable {
        return VmIteratorProtocol::compileForeachKey($context, $slotKey, $containerUserType);
    }

    public static function compileForeachValue(
        Context $context,
        Variable $slotKey,
        ?string $containerUserType = null
    ): Variable {
        return VmIteratorProtocol::compileForeachValue($context, $slotKey, $containerUserType);
    }

    /**
     * @return array<int, Call>
     */
    public static function methodCandidates(Context $context, string $methodLc): array
    {
        return VmIteratorProtocol::methodCandidates($context, $methodLc);
    }

    public static function resolveIteratorMethodProxy(
        Context $context,
        Variable $receiver,
        string $methodLc,
        ?string $containerUserType = null
    ): Call {
        return VmIteratorProtocol::resolveIteratorMethodProxy($context, $receiver, $methodLc, $containerUserType);
    }

    public static function classImplementsIteratorProtocol(Context $context, string $classLc): bool
    {
        return VmIteratorProtocol::classImplementsIteratorProtocol($context, $classLc);
    }

    public static function invokeIteratorMethod(
        Context $context,
        Variable $receiver,
        string $methodLc,
        ?string $containerUserType = null
    ): void {
        VmIteratorProtocol::invokeIteratorMethod($context, $receiver, $methodLc, $containerUserType);
    }

    public static function invokeIteratorMethodBool(
        Context $context,
        Variable $receiver,
        string $methodLc,
        ?string $containerUserType = null
    ): Value {
        return VmIteratorProtocol::invokeIteratorMethodBool($context, $receiver, $methodLc, $containerUserType);
    }

    public static function invokeIteratorMethodValue(
        Context $context,
        Variable $receiver,
        string $methodLc,
        ?string $containerUserType = null
    ): Variable {
        return VmIteratorProtocol::invokeIteratorMethodValue($context, $receiver, $methodLc, $containerUserType);
    }

    public static function truthyI1(Context $context, Value $result): Value
    {
        return VmIteratorProtocol::truthyI1($context, $result);
    }
}
