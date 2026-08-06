<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\SplDllistJitHelper;
use PHPLLVM\Value;

/**
 * SplDoublyLinkedList / SplQueue / SplStack thin-AOT methods (#26790, ext/spl/spl_dllist.c).
 */
final class SplDllistMethod implements Call
{
    public function __construct(
        private readonly string $method,
        private readonly string $className,
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
            '__construct' => SplDllistJitHelper::compileConstruct($context, $args[0], $this->className),
            'push', 'enqueue' => SplDllistJitHelper::compilePush(
                $context,
                $args[0],
                $args[1] ?? throw new \ArgumentCountError(
                    $this->className.'::'.$this->method.'() expects exactly 1 argument, 0 given'
                )
            ),
            'unshift' => SplDllistJitHelper::compileUnshift(
                $context,
                $args[0],
                $args[1] ?? throw new \ArgumentCountError(
                    $this->className.'::unshift() expects exactly 1 argument, 0 given'
                )
            ),
            'pop' => SplDllistJitHelper::compilePop($context, $args[0]),
            'shift', 'dequeue' => SplDllistJitHelper::compileShift($context, $args[0]),
            default => throw new \LogicException(
                $this->className.' JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }
}
