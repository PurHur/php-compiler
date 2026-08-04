<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\SplHtPosIteratorJitHelper;
use PHPLLVM\Value;

/**
 * NoRewindIterator / InfiniteIterator thin-AOT Iterator protocol (#27583 / #27568).
 *
 * php-src: ext/spl/spl_iterators.c
 */
final class SplHtPosIteratorMethod implements Call
{
    public function __construct(
        private readonly string $method,
        private readonly string $className,
        private readonly int $rewindMode,
        private readonly int $nextMode = SplHtPosIteratorJitHelper::NEXT_STOP,
    ) {
    }

    public function methodName(): string
    {
        return $this->method;
    }

    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException($this->className.'::'.$this->method.'() called without $this');
        }

        return match (strtolower($this->method)) {
            '__construct' => SplHtPosIteratorJitHelper::compileConstruct(
                $context,
                $args[0],
                $args[1] ?? throw new \ArgumentCountError(
                    $this->className.'::__construct() expects exactly 1 argument, 0 given'
                ),
                $this->className
            ),
            'rewind' => SplHtPosIteratorJitHelper::compileRewind(
                $context,
                $args[0],
                $this->className,
                $this->rewindMode
            ),
            'valid' => SplHtPosIteratorJitHelper::compileValid($context, $args[0], $this->className),
            'current' => SplHtPosIteratorJitHelper::compileCurrent($context, $args[0], $this->className),
            'key' => SplHtPosIteratorJitHelper::compileKey($context, $args[0], $this->className),
            'next' => SplHtPosIteratorJitHelper::compileNext(
                $context,
                $args[0],
                $this->className,
                $this->nextMode
            ),
            default => throw new \LogicException(
                $this->className.' JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }
}
