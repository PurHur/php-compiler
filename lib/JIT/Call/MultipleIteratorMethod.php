<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\MultipleIteratorZipLlvm;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\SplOuterIteratorHt;
use PHPLLVM\Value;

/**
 * MultipleIterator thin-AOT — zip attach into `__spl_ht` (#27584).
 *
 * Pure LLVM zip (NestedJIT HashTable helpers segfault under thin standalone AOT).
 * php-src: ext/spl/spl_iterators.c — MultipleIterator
 */
final class MultipleIteratorMethod implements Call
{
    public const PROP_ATTACHED = '__spl_mi_n';

    public function __construct(
        private readonly string $method,
    ) {
    }

    public function methodName(): string
    {
        return $this->method;
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return match (strtolower($this->method)) {
            '__construct' => self::compileConstruct($context, $args),
            'attachiterator' => self::compileAttach($context, $args),
            default => throw new \LogicException(
                'MultipleIterator JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }

    /** @param list<Variable> $args */
    private static function compileConstruct(Context $context, array $args): Value
    {
        if ([] === $args) {
            throw new \LogicException('MultipleIterator::__construct() called without $this');
        }
        $receiver = self::objectReceiver($context, $args[0]);
        $empty = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            HashTableHelper::alloc($context)
        );
        $objPtr = $context->helper->loadValue($receiver);
        $slot = $context->type->object->propertySlotFor(
            $objPtr,
            'MultipleIterator',
            SplOuterIteratorHt::PROP_HT
        );
        $context->type->object->propertyStore($slot, $empty, Variable::TYPE_HASHTABLE);
        $zero = new Variable(
            $context,
            Variable::TYPE_NATIVE_LONG,
            Variable::KIND_VALUE,
            $context->getTypeFromString('int64')->constInt(0, false)
        );
        $nSlot = $context->type->object->propertySlotFor(
            $objPtr,
            'MultipleIterator',
            self::PROP_ATTACHED
        );
        $context->type->object->propertyStore($nSlot, $zero, Variable::TYPE_NATIVE_LONG);

        return self::voidResult($context);
    }

    /** @param list<Variable> $args */
    private static function compileAttach(Context $context, array $args): Value
    {
        if (!isset($args[0], $args[1])) {
            throw new \ArgumentCountError(
                'MultipleIterator::attachIterator() expects at least 1 argument, 0 given'
            );
        }
        $receiver = self::objectReceiver($context, $args[0]);
        $inner = self::objectReceiver($context, $args[1]);
        $objPtr = $context->helper->loadValue($receiver);
        $existing = $context->helper->loadValue(
            $context->type->object->splBackingHashtable($receiver)
        );
        $src = $context->helper->loadValue(
            $context->type->object->splBackingHashtable($inner)
        );
        $nSlot = $context->type->object->propertyFetch(
            $objPtr,
            'MultipleIterator',
            self::PROP_ATTACHED
        );
        $n = $context->helper->loadValue($nSlot);
        $i64 = $context->getTypeFromString('int64');
        $isFirst = $context->builder->select(
            $context->builder->icmp(
                \PHPLLVM\Builder::INT_EQ,
                $n,
                $i64->constInt(0, false)
            ),
            $i64->constInt(1, false),
            $i64->constInt(0, false)
        );
        $zipped = MultipleIteratorZipLlvm::zip($context, $existing, $src, $isFirst);
        $zippedVar = new Variable(
            $context,
            Variable::TYPE_HASHTABLE,
            Variable::KIND_VALUE,
            $zipped
        );
        $htSlot = $context->type->object->propertySlotFor(
            $objPtr,
            'MultipleIterator',
            SplOuterIteratorHt::PROP_HT
        );
        $context->type->object->propertyStore($htSlot, $zippedVar, Variable::TYPE_HASHTABLE);
        $nextN = $context->builder->addNoSignedWrap($n, $i64->constInt(1, false));
        $nextVar = new Variable($context, Variable::TYPE_NATIVE_LONG, Variable::KIND_VALUE, $nextN);
        $context->type->object->propertyStore(
            $context->type->object->propertySlotFor($objPtr, 'MultipleIterator', self::PROP_ATTACHED),
            $nextVar,
            Variable::TYPE_NATIVE_LONG
        );

        return self::voidResult($context);
    }

    private static function objectReceiver(Context $context, Variable $receiver): Variable
    {
        if (Variable::TYPE_OBJECT === $receiver->type) {
            return $receiver;
        }
        if (Variable::TYPE_VALUE === $receiver->type) {
            $obj = $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $receiver)
            );

            return new Variable($context, Variable::TYPE_OBJECT, Variable::KIND_VALUE, $obj);
        }

        throw new \LogicException('MultipleIterator method requires an object receiver');
    }

    private static function voidResult(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
