<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\spl\SplHeapBuiltin;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\SplHeapJitHelper;
use PHPLLVM\Value;

/**
 * SplHeap / SplMaxHeap / SplMinHeap thin-AOT methods (#26784, ext/spl/spl_heap.c).
 */
final class SplHeapMethod implements Call
{
    public function __construct(
        private readonly string $method,
        private readonly int $kind = SplHeapBuiltin::KIND_MAX,
    ) {
    }

    public function methodName(): string
    {
        return $this->method;
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('SplHeap::'.$this->method.'() called without $this');
        }

        return match (strtolower($this->method)) {
            '__construct' => SplHeapJitHelper::compileConstruct($context, $args[0], $this->kind),
            'insert' => SplHeapJitHelper::compileInsert(
                $context,
                $args[0],
                $args[1] ?? throw new \ArgumentCountError(
                    'SplHeap::insert() expects exactly 1 argument, 0 given'
                )
            ),
            'extract' => SplHeapJitHelper::compileExtract($context, $args[0]),
            'top' => SplHeapJitHelper::compileTop($context, $args[0]),
            'count' => SplHeapJitHelper::compileCount($context, $args[0]),
            'rewind' => SplHeapJitHelper::compileRewind($context, $args[0]),
            'valid' => SplHeapJitHelper::compileValid($context, $args[0]),
            'current' => SplHeapJitHelper::compileCurrent($context, $args[0]),
            'key' => SplHeapJitHelper::compileKey($context, $args[0]),
            'next' => SplHeapJitHelper::compileNext($context, $args[0]),
            default => throw new \LogicException(
                'SplHeap JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }
}
