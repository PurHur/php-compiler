<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\TryCatchHelper;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\FilterIteratorJitHelper;
use PHPLLVM\Value;

/**
 * FilterIterator thin-AOT Iterator protocol + accept() fetch (#27565).
 *
 * php-src: ext/spl/spl_iterators.c — spl_FilterIterator
 */
final class FilterIteratorMethod implements Call
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
            throw new \LogicException('FilterIterator::'.$this->method.'() called without $this');
        }

        return match (strtolower($this->method)) {
            '__construct' => FilterIteratorJitHelper::compileConstruct(
                $context,
                $args[0],
                $args[1] ?? throw new \ArgumentCountError(
                    'FilterIterator::__construct() expects exactly 1 argument, 0 given'
                )
            ),
            'rewind' => FilterIteratorJitHelper::compileRewind($context, $args[0]),
            'next' => FilterIteratorJitHelper::compileNext($context, $args[0]),
            'valid' => FilterIteratorJitHelper::compileValid($context, $args[0]),
            'current' => FilterIteratorJitHelper::compileCurrent($context, $args[0]),
            'key' => FilterIteratorJitHelper::compileKey($context, $args[0]),
            'accept' => self::throwAcceptNotImplemented($context),
            'getinneriterator' => self::voidNull($context),
            default => throw new \LogicException(
                'FilterIterator JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }

    private static function throwAcceptNotImplemented(Context $context): Value
    {
        TryCatchHelper::emitCatchableClassError(
            $context,
            'BadMethodCallException',
            'FilterIterator::accept() must be implemented in a subclass'
        );
        $unreachable = \PHPCompiler\JIT\BasicBlockHelper::append($context, 'filter_it_accept_unreach');
        $context->builder->positionAtEnd($unreachable);

        return self::voidNull($context);
    }

    private static function voidNull(Context $context): Value
    {
        $slot = \PHPCompiler\JIT\JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeNull'),
            \PHPCompiler\JIT\JitValueBox::pointer($context, $slot)
        );

        return $slot;
    }
}
