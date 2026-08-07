<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\SplPriorityQueueJitHelper;
use PHPLLVM\Value;

/**
 * SplPriorityQueue thin-AOT methods (#27277, #28708, ext/spl/spl_heap.c).
 */
final class SplPriorityQueueMethod implements Call
{
    public function __construct(private readonly string $method)
    {
    }

    public function methodName(): string
    {
        return $this->method;
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('SplPriorityQueue::'.$this->method.'() called without $this');
        }

        return match (strtolower($this->method)) {
            '__construct' => SplPriorityQueueJitHelper::compileConstruct($context, $args[0]),
            'insert' => SplPriorityQueueJitHelper::compileInsert(
                $context,
                $args[0],
                $args[1] ?? throw new \ArgumentCountError(
                    'SplPriorityQueue::insert() expects exactly 2 arguments, 0 given'
                ),
                $args[2] ?? throw new \ArgumentCountError(
                    'SplPriorityQueue::insert() expects exactly 2 arguments, 1 given'
                )
            ),
            'extract' => SplPriorityQueueJitHelper::compileExtract($context, $args[0]),
            'top' => SplPriorityQueueJitHelper::compileTop($context, $args[0]),
            'count' => SplPriorityQueueJitHelper::compileCount($context, $args[0]),
            'rewind' => SplPriorityQueueJitHelper::compileRewind($context, $args[0]),
            'valid' => SplPriorityQueueJitHelper::compileValid($context, $args[0]),
            'current' => SplPriorityQueueJitHelper::compileCurrent($context, $args[0]),
            'key' => SplPriorityQueueJitHelper::compileKey($context, $args[0]),
            'next' => SplPriorityQueueJitHelper::compileNext($context, $args[0]),
            default => throw new \LogicException(
                'SplPriorityQueue JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }
}
