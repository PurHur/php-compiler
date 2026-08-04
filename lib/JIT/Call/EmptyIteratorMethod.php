<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * EmptyIterator thin-AOT — always-invalid; current/key throw (#27582 / #24246).
 *
 * php-src: ext/spl/spl_iterators.c — empty iterator current/key
 */
final class EmptyIteratorMethod implements Call
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
        if ([] === $args) {
            throw new \LogicException('EmptyIterator::'.$this->method.'() called without $this');
        }

        return match (strtolower($this->method)) {
            '__construct' => self::voidConstruct($context, $args[0]),
            'rewind', 'next' => self::voidResult($context),
            'valid' => self::validFalse($context),
            'current' => self::throwBadMethod(
                $context,
                'Accessing the value of an EmptyIterator'
            ),
            'key' => self::throwBadMethod(
                $context,
                'Accessing the key of an EmptyIterator'
            ),
            default => throw new \LogicException(
                'EmptyIterator JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }

    private static function voidConstruct(Context $context, Variable $receiver): Value
    {
        $obj = self::loadObject($context, $receiver);
        $context->type->object->markObjectConstructed($obj);

        return self::voidResult($context);
    }

    private static function validFalse(Context $context): Value
    {
        $slot = JitValueBox::alloc($context);
        JitValueBox::writeBool($context, $slot, $context->getTypeFromString('int1')->constInt(0, false));

        return $slot;
    }

    private static function throwBadMethod(Context $context, string $message): Value
    {
        TryCatchHelper::emitCatchableClassError(
            $context,
            'BadMethodCallException',
            $message
        );
        // emitCatchableClassError terminates the insert block; dummy for Call ABI.
        $unreachable = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'empty_it_throw_unreach');
        $context->builder->positionAtEnd($unreachable);

        return self::voidResult($context);
    }

    private static function loadObject(Context $context, Variable $receiver): Value
    {
        if (Variable::TYPE_OBJECT === $receiver->type) {
            return $context->helper->loadValue($receiver);
        }
        if (Variable::TYPE_VALUE === $receiver->type || JitValueBox::isValueOperand($receiver)) {
            return $context->builder->call(
                $context->lookupFunction('__value__readObject'),
                JitValueBox::valuePtrFromVariable($context, $receiver)
            );
        }

        throw new \LogicException('EmptyIterator method requires an object receiver');
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
