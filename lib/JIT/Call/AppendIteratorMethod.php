<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\HashTableHelper;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\SplOuterIteratorHt;
use PHPLLVM\Value;

/**
 * AppendIterator thin-AOT — empty `__spl_ht` at construct; append merges inner HT (#26825).
 *
 * php-src: ext/spl/spl_iterators.c — AppendIterator
 */
final class AppendIteratorMethod implements Call
{
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
            'append' => self::compileAppend($context, $args),
            default => throw new \LogicException(
                'AppendIterator JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }

    /** @param list<Variable> $args */
    private static function compileConstruct(Context $context, array $args): Value
    {
        if ([] === $args) {
            throw new \LogicException('AppendIterator::__construct() called without $this');
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
            'AppendIterator',
            SplOuterIteratorHt::PROP_HT
        );
        $context->type->object->propertyStore($slot, $empty, Variable::TYPE_HASHTABLE);

        return self::voidResult($context);
    }

    /** @param list<Variable> $args */
    private static function compileAppend(Context $context, array $args): Value
    {
        if (!isset($args[0], $args[1])) {
            throw new \ArgumentCountError(
                'AppendIterator::append() expects exactly 1 argument, '
                .(isset($args[0]) ? '0' : '0').' given'
            );
        }
        $receiver = self::objectReceiver($context, $args[0]);
        $inner = self::objectReceiver($context, $args[1]);
        $destHtVar = $context->type->object->splBackingHashtable($receiver);
        $srcHtVar = $context->type->object->splBackingHashtable($inner);
        // spreadInto expects Variable destinations typed as HASHTABLE.
        HashTableHelper::spreadInto(
            $context,
            $destHtVar,
            new Variable(
                $context,
                Variable::TYPE_HASHTABLE,
                Variable::KIND_VALUE,
                $context->helper->loadValue($srcHtVar)
            )
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

        throw new \LogicException(
            'AppendIterator method expects an object, got '
            .Variable::getStringType($receiver->type)
        );
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
