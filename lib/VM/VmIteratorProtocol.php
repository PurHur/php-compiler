<?php

declare(strict_types=1);

namespace PHPCompiler\VM;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Call\RuntimeIndirectInstanceMethodCall;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JitVariable;
use PHPLLVM\Builder;
use PHPLLVM\Value;

/**
 * SSOT for JIT Iterator / IteratorAggregate protocol lowering (Zend zend_iterators.c, #10240).
 *
 * Shared by foreach TYPE_ITER_* opcodes (#4011) and ext/standard iterator builtins (#3313).
 *
 * php-src: Zend/zend_execute.c — ZEND_FE_FETCH_R Iterator branch
 * php-src: Zend/zend_interfaces.c — user_iterator_* handlers
 *
 * VM runtime: {@see \PHPCompiler\VM\ForeachIterator}
 */
final class VmIteratorProtocol
{
    /** @var list<string> */
    private const ITERATOR_IFACES_LC = ['iterator', 'iteratoraggregate'];

    public static function normalizeObjectReceiver(Context $context, JitVariable $iterable): JitVariable
    {
        if (JitVariable::TYPE_OBJECT === $iterable->type) {
            return $iterable;
        }
        if (JitVariable::TYPE_VALUE === $iterable->type) {
            $obj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $iterable)
            );

            return new JitVariable($context, JitVariable::TYPE_OBJECT, JitVariable::KIND_VALUE, $obj);
        }

        throw new \LogicException('iterator protocol requires an object traversable');
    }

    /**
     * True when foreach / iterator walk should call rewind/valid/current/key/next (#4011).
     */
    public static function canLowerIteratorProtocol(
        Context $context,
        JitVariable $container,
        ?string $containerUserType
    ): bool {
        if (null !== $containerUserType) {
            $ut = strtolower(ltrim($containerUserType, '\\'));
            // HT-backed SPL — foreach walks `__spl_ht`, not Iterator method proxies (#26783, #26775, #26825).
            if ('splobjectstorage' === $ut
                || \PHPCompiler\VM\SplOuterIteratorHt::isHtBacked($containerUserType)) {
                return false;
            }
            // Arrays are never Iterator protocol (#27105).
            if ('array' === $ut) {
                return false;
            }
        }
        if ($container->type & JitVariable::IS_NATIVE_ARRAY) {
            return false;
        }
        if (JitVariable::TYPE_HASHTABLE === $container->type) {
            return false;
        }
        if (JitVariable::TYPE_OBJECT !== $container->type && JitVariable::TYPE_VALUE !== $container->type) {
            return false;
        }
        // Main-script locals are TYPE_VALUE script globals. Without a concrete class
        // hint, multi-candidate RuntimeIndirect would claim arrays as Iterators and
        // __value__readObject a hashtable (#27105 / AOT foreach segfault).
        if (JitVariable::TYPE_VALUE === $container->type) {
            if (null === $containerUserType || '' === $containerUserType) {
                return false;
            }
            $ut = strtolower(ltrim($containerUserType, '\\'));
            if ('object' === $ut) {
                return false;
            }
        }
        try {
            $receiver = self::normalizeObjectReceiver($context, $container);
            self::resolveIteratorMethodProxy($context, $receiver, 'rewind', $containerUserType);

            return true;
        } catch (\LogicException) {
            return false;
        }
    }

    public static function receiverSlot(Context $context, JitVariable $slotKey): Value
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

    public static function advanceSlot(Context $context, JitVariable $slotKey): Value
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

    public static function storeReceiver(Context $context, JitVariable $slotKey, JitVariable $receiver): void
    {
        $obj = JitVariable::KIND_VALUE === $receiver->kind
            ? $receiver->value
            : $context->builder->load($receiver->value);
        $context->builder->store($obj, self::receiverSlot($context, $slotKey));
    }

    public static function loadReceiver(Context $context, JitVariable $slotKey): JitVariable
    {
        $obj = $context->builder->load(self::receiverSlot($context, $slotKey));

        return new JitVariable($context, JitVariable::TYPE_OBJECT, JitVariable::KIND_VALUE, $obj);
    }

    /**
     * IteratorAggregate::getIterator() when the compile-time class exposes it.
     */
    public static function resolveForeachReceiver(
        Context $context,
        JitVariable $container,
        ?string $containerUserType
    ): JitVariable {
        $receiver = self::normalizeObjectReceiver($context, $container);
        if (null !== $containerUserType && '' !== $containerUserType) {
            $classLc = strtolower(ltrim($containerUserType, '\\'));
            if ('object' !== $classLc) {
                $proxyName = $classLc.'::getiterator';
                if ($context->functionIsRegistered($proxyName)) {
                    $inner = $context->resolveFunctionProxy($proxyName)->call($context, $receiver);

                    return self::normalizeObjectReceiver(
                        $context,
                        new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VALUE, $inner)
                    );
                }
            }
        }

        return $receiver;
    }

    public static function compileForeachReset(
        Context $context,
        JitVariable $container,
        JitVariable $slotKey,
        ?string $containerUserType
    ): void {
        $receiver = self::resolveForeachReceiver($context, $container, $containerUserType);
        self::storeReceiver($context, $slotKey, $receiver);
        self::invokeIteratorMethod(
            $context,
            self::loadReceiver($context, $slotKey),
            'rewind',
            $containerUserType
        );
        $i1 = $context->getTypeFromString('int1');
        $context->builder->store($i1->constInt(0, false), self::advanceSlot($context, $slotKey));
    }

    public static function compileForeachValid(
        Context $context,
        JitVariable $slotKey,
        ?string $containerUserType = null
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
        self::invokeIteratorMethod($context, $receiver, 'next', $containerUserType);
        $context->builder->store($i1->constInt(0, false), $advanceSlot);
        $context->builder->branch($checkValid);
        $context->builder->positionAtEnd($checkValid);

        return self::invokeIteratorMethodBool($context, $receiver, 'valid', $containerUserType);
    }

    public static function compileForeachKey(
        Context $context,
        JitVariable $slotKey,
        ?string $containerUserType = null
    ): JitVariable {
        return self::invokeIteratorMethodValue(
            $context,
            self::loadReceiver($context, $slotKey),
            'key',
            $containerUserType
        );
    }

    public static function compileForeachValue(
        Context $context,
        JitVariable $slotKey,
        ?string $containerUserType = null
    ): JitVariable {
        $receiver = self::loadReceiver($context, $slotKey);
        $value = self::invokeIteratorMethodValue($context, $receiver, 'current', $containerUserType);
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
            if (!self::classImplementsIteratorProtocol($context, $classLc)) {
                continue;
            }
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
        JitVariable $receiver,
        string $methodLc,
        ?string $containerUserType = null
    ): Call {
        $methodLc = strtolower($methodLc);
        if (null !== $containerUserType && '' !== $containerUserType) {
            $classLc = strtolower(ltrim($containerUserType, '\\'));
            if ('object' !== $classLc) {
                $proxyName = $classLc.'::'.$methodLc;
                if ($context->functionIsRegistered($proxyName)) {
                    return $context->resolveFunctionProxy($proxyName);
                }
            }
        }
        $candidates = self::methodCandidates($context, $methodLc);
        // RuntimeIndirect compiles every candidate arm — Generator::* needs resume
        // metadata that a value-box type switch does not have (#27634 / #26825).
        if ([] === $context->generatorCreators) {
            $candidates = array_filter(
                $candidates,
                static fn (Call $proxy): bool => !self::isGeneratorIteratorProxy($proxy)
            );
        }
        if (1 === \count($candidates)) {
            $only = reset($candidates);
            // Never treat Generator::* as the universal sole Iterator (#26825).
            // Real Generators go through hydrateGeneratorMetadata / isGeneratorVariable first.
            if (self::isGeneratorIteratorProxy($only)) {
                throw new \LogicException(
                    "iterator protocol method {$methodLc}() must not resolve solely to Generator"
                );
            }

            return $only;
        }
        if ([] === $candidates) {
            throw new \LogicException("iterator protocol method {$methodLc}() is not available in this compile unit");
        }

        return new RuntimeIndirectInstanceMethodCall($receiver, $methodLc, $candidates);
    }

    private static function isGeneratorIteratorProxy(Call $proxy): bool
    {
        return $proxy instanceof \PHPCompiler\JIT\Call\GeneratorRewind
            || $proxy instanceof \PHPCompiler\JIT\Call\GeneratorNext
            || $proxy instanceof \PHPCompiler\JIT\Call\GeneratorValid
            || $proxy instanceof \PHPCompiler\JIT\Call\GeneratorCurrent
            || $proxy instanceof \PHPCompiler\JIT\Call\GeneratorKey;
    }

    public static function classImplementsIteratorProtocol(Context $context, string $classLc): bool
    {
        foreach ($context->type->object->allInterfacesForClassLc($classLc) as $ifaceLc) {
            if (in_array($ifaceLc, self::ITERATOR_IFACES_LC, true)) {
                return true;
            }
        }

        return false;
    }

    public static function invokeIteratorMethod(
        Context $context,
        JitVariable $receiver,
        string $methodLc,
        ?string $containerUserType = null
    ): void {
        $proxy = self::resolveIteratorMethodProxy($context, $receiver, $methodLc, $containerUserType);
        $proxy->call($context, $receiver);
    }

    public static function invokeIteratorMethodBool(
        Context $context,
        JitVariable $receiver,
        string $methodLc,
        ?string $containerUserType = null
    ): Value {
        $proxy = self::resolveIteratorMethodProxy($context, $receiver, $methodLc, $containerUserType);
        $result = $proxy->call($context, $receiver);

        return self::truthyI1($context, $result);
    }

    public static function invokeIteratorMethodValue(
        Context $context,
        JitVariable $receiver,
        string $methodLc,
        ?string $containerUserType = null
    ): JitVariable {
        $proxy = self::resolveIteratorMethodProxy($context, $receiver, $methodLc, $containerUserType);
        $result = $proxy->call($context, $receiver);
        if ('int1' === $context->getStringFromType($result->typeOf())) {
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeBool($context, $slot, $result);

            return new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VARIABLE, $slot);
        }
        if ('int64' === $context->getStringFromType($result->typeOf())) {
            $slot = JitValueBox::alloc($context);
            JitValueBox::writeLong($context, $slot, $result);

            return new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VARIABLE, $slot);
        }

        return new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VALUE, $result);
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
        $boxed = new JitVariable($context, JitVariable::TYPE_VALUE, JitVariable::KIND_VALUE, $result);

        return (new \PHPCompiler\ext\standard\boolval())->call($context, $boxed);
    }
}
