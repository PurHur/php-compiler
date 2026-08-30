<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\LimitIteratorJitHelper;
use PHPLLVM\Value;

/** LimitIterator::rewind / seek — OOB before HT snapshot walk (#24295, #31621). */
final class LimitIteratorMethod implements Call
{
    public function __construct(private readonly string $method)
    {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('LimitIterator::'.$this->method.'() called without $this');
        }

        return match (strtolower($this->method)) {
            'rewind' => LimitIteratorJitHelper::compileRewind($context, $args[0]),
            'seek' => LimitIteratorJitHelper::compileSeek(
                $context,
                $args[0],
                $args[1] ?? throw new \ArgumentCountError(
                    'LimitIterator::seek() expects exactly 1 argument, 0 given'
                )
            ),
            default => throw new \LogicException(
                'LimitIterator JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }
}
