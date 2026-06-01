<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * LLVM lowering for Iterator / IteratorAggregate protocol (Zend zend_iterators.c).
 *
 * Shared by foreach TYPE_ITER_* opcodes (#4011) and ext/standard iterator builtins (#3313).
 */
final class IteratorProtocolHelper
{
    public static function normalizeObjectReceiver(Context $context, Variable $iterable): Variable
    {
        if (Variable::TYPE_OBJECT === $iterable->type) {
            return $iterable;
        }
        if (Variable::TYPE_VALUE === $iterable->type) {
            $obj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $iterable)
            );

            return new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
        }

        throw new \LogicException('iterator protocol requires an object traversable');
    }

    /**
     * True when foreach / iterator walk should call rewind/valid/current/key/next (#4011).
     */
    public static function canLowerIteratorProtocol(
        Context $context,
        Variable $container,
        ?string $containerUserType
    ): bool {
        if (null !== $containerUserType && 'splobjectstorage' === strtolower($containerUserType)) {
            return false;
        }
        if ($container->type & Variable::IS_NATIVE_ARRAY) {
            return false;
        }
        if (Variable::TYPE_HASHTABLE === $container->type) {
            return false;
        }
        if (Variable::TYPE_OBJECT !== $container->type && Variable::TYPE_VALUE !== $container->type) {
            return false;
        }
        try {
            $receiver = self::normalizeObjectReceiver($context, $container);
            self::resolveIteratorMethodProxy($context, $receiver, 'rewind');

            return true;
        } catch (\LogicException) {
            return false;
        }
    }

    public static function receiverSlot(Context $context, Variable $slotKey): Value
    {
        $key = \spl_object_id($slotKey);
        if (isset($context->foreachIteratorReceiverSlots[$key])) {
            return $context->foreachIteratorReceiverSlots[$key];
        }
        $objPtrType = $context->getTypeFromString('__object__*');
        $slot = BasicBlockHelper::entryAlloca($context, $objPtrType);
        $context->foreachIteratorReceiverSlots[$key] = $slot;

        return $slot;
    }

    public static function advanceSlot(Context $context, Variable $slotKey): Value
    {
        $key = \spl_object_id($slotKey);
        if (isset($context->foreachIteratorAdvanceSlots[$key])) {
            return $context->foreachIteratorAdvanceSlots[$key];
        }
        $i1 = $context->getTypeFromString('int1');
        $slot = BasicBlockHelper::entryAlloca($context, $i1);
        $context->foreachIteratorAdvanceSlots[$key] = $slot;

        return $slot;
    }

    public static function storeReceiver(Context $context, Variable $slotKey, Variable $receiver): void
    {
        $obj = Variable::KIND_VALUE === $receiver->kind
            ? $receiver->value
            : $context->builder->load($receiver->value);
        $context->builder->store($obj, self::receiverSlot($context, $slotKey));
    }

    public static function loadReceiver(Context $context, Variable $slotKey): Variable
    {
        $obj = $context->builder->load(self::receiverSlot($context, $slotKey));

        return new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
    }

    /**
     * IteratorAggregate::getIterator() when the compile-time class exposes it.
     */
    public static function resolveForeachReceiver(
        Context $context,
        Variable $container,
        ?string $containerUserType
    ): Variable {
        $receiver = self::normalizeObjectReceiver($context, $container);
        if (null !== $containerUserType && '' !== $containerUserType) {
            $classLc = strtolower(ltrim($containerUserType, '\\'));
            if ('object' !== $classLc) {
                $proxyName = $classLc.'::getiterator';
                if ($context->functionIsRegistered($proxyName)) {
                    $inner = $context->resolveFunctionProxy($proxyName)->call($context, $receiver);

                    return self::normalizeObjectReceiver(
                        $context,
                        new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VALUE, $inner)
                    );
                }
            }
        }

        return $receiver;
    }

    public static function compileForeachReset(
        Context $context,
        Variable $container,
        Variable $slotKey,
        ?string $containerUserType
    ): void {
        $receiver = self::resolveForeachReceiver($context, $container, $containerUserType);
        self::storeReceiver($context, $slotKey, $receiver);
        self::invokeIteratorMethod($context, self::loadReceiver($context, $slotKey), 'rewind');
        $i1 = $context->getTypeFromString('int1');
        $context->builder->store($i1->constInt(0, false), self::advanceSlot($context, $slotKey));
    }

    public static function compileForeachValid(
        Context $context,
        Variable $slotKey
    ): Value {
        $receiver = self::loadReceiver($context, $slotKey);
        $advanceSlot = self::advanceSlot($context, $slotKey);
        $fn = $context->builder->getInsertBlock()->getParent();
        $entry = $context->builder->getInsertBlock();
        $maybeNext = $fn->appendBasicBlock('foreach_iter_maybe_next');
        $checkValid = $fn->appendBasicBlock('foreach_iter_valid');
        $i1 = $context->getTypeFromString('int1');
        $needsNext = $context->builder->load($advanceSlot);
        $context->builder->branchIf($needsNext, $maybeNext, $checkValid);
        $context->builder->positionAtEnd($maybeNext);
        self::invokeIteratorMethod($context, $receiver, 'next');
        $context->builder->store($i1->constInt(0, false), $advanceSlot);
        $context->builder->branch($checkValid);
        $context->builder->positionAtEnd($checkValid);

        return self::invokeIteratorMethodBool($context, $receiver, 'valid');
    }

    public static function compileForeachKey(Context $context, Variable $slotKey): Variable
    {
        return self::invokeIteratorMethodValue(
            $context,
            self::loadReceiver($context, $slotKey),
            'key'
        );
    }

    public static function compileForeachValue(Context $context, Variable $slotKey): Variable
    {
        $receiver = self::loadReceiver($context, $slotKey);
        $value = self::invokeIteratorMethodValue($context, $receiver, 'current');
        $i1 = $context->getTypeFromString('int1');
        $context->builder->store($i1->constInt(1, false), self::advanceSlot($context, $slotKey));

        return $value;
    }

    /**
     * @return array<int, Call>
     */
    public static function methodCandidates(Context $context, string $methodLc): array
    {
        $methodLc = strtolower($methodLc);
        $candidates = [];
        foreach ($context->type->object->allClassNamesById() as $classId => $className) {
            $classLc = strtolower(ltrim($className, '\\'));
            $current = $classLc;
            $visited = [];
            while (!isset($visited[$current])) {
                $visited[$current] = true;
                $proxyName = $current.'::'.$methodLc;
                if ($context->functionIsRegistered($proxyName)) {
                    $candidates[$classId] = $context->resolveFunctionProxy($proxyName);
                    break;
                }
                $current = $context->type->object->parentClassLc($current);
                if (null === $current) {
                    break;
                }
            }
        }

        return $candidates;
    }

    public static function resolveIteratorMethodProxy(
        Context $context,
        Variable $receiver,
        string $methodLc
    ): Call {
        $methodLc = strtolower($methodLc);
        if (null !== $receiver->userType && '' !== $receiver->userType) {
            $classLc = strtolower(ltrim($receiver->userType, '\\'));
            if ('object' !== $classLc) {
                $proxyName = $classLc.'::'.$methodLc;
                if ($context->functionIsRegistered($proxyName)) {
                    return $context->resolveFunctionProxy($proxyName);
                }
            }
        }
        $candidates = self::methodCandidates($context, $methodLc);
        if (1 === \count($candidates)) {
            return reset($candidates);
        }
        if ([] === $candidates) {
            throw new \LogicException("iterator protocol method {$methodLc}() is not available in this compile unit");
        }

        throw new \LogicException(
            "iterator protocol method {$methodLc}() on a polymorphic object is not supported in this compiler build"
        );
    }

    public static function invokeIteratorMethod(Context $context, Variable $receiver, string $methodLc): void
    {
        $proxy = self::resolveIteratorMethodProxy($context, $receiver, $methodLc);
        $proxy->call($context, $receiver);
    }

    public static function invokeIteratorMethodBool(Context $context, Variable $receiver, string $methodLc): Value
    {
        $proxy = self::resolveIteratorMethodProxy($context, $receiver, $methodLc);
        $result = $proxy->call($context, $receiver);

        return self::truthyI1($context, $result);
    }

    public static function invokeIteratorMethodValue(Context $context, Variable $receiver, string $methodLc): Variable
    {
        $proxy = self::resolveIteratorMethodProxy($context, $receiver, $methodLc);
        $result = $proxy->call($context, $receiver);
        if ('int1' === $context->getStringFromType($result->typeOf())) {
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeBool($context, $slot, $result);

            return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
        }
        if ('int64' === $context->getStringFromType($result->typeOf())) {
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeLong($context, $slot, $result);

            return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VARIABLE, $slot);
        }

        return new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VALUE, $result);
    }

    public static function truthyI1(Context $context, Value $result): Value
    {
        $ty = $context->getStringFromType($result->typeOf());
        if ('int1' === $ty) {
            return $result;
        }
        if ('int64' === $ty || 'int32' === $ty) {
            return $context->builder->icmp(
                Builder::INT_NE,
                $result,
                $context->getTypeFromString('int64')->constInt(0, false)
            );
        }
        $boxed = new Variable($context, Variable::TYPE_VALUE, Variable::KIND_VALUE, $result);

        return (new boolval())->call($context, $boxed);
    }
}
