<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
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
            'pop' => $this->callZeroArg(
                $context,
                $args,
                'SplDoublyLinkedList::pop',
                static fn (Context $ctx, Variable $self): Value => SplDllistJitHelper::compilePop($ctx, $self)
            ),
            'shift', 'dequeue' => $this->callZeroArg(
                $context,
                $args,
                'dequeue' === strtolower($this->method) ? 'SplQueue::dequeue' : 'SplDoublyLinkedList::shift',
                static fn (Context $ctx, Variable $self): Value => SplDllistJitHelper::compileShift($ctx, $self)
            ),
            'top' => $this->callZeroArg(
                $context,
                $args,
                'SplDoublyLinkedList::top',
                static fn (Context $ctx, Variable $self): Value => SplDllistJitHelper::compileTop($ctx, $self)
            ),
            'bottom' => $this->callZeroArg(
                $context,
                $args,
                'SplDoublyLinkedList::bottom',
                static fn (Context $ctx, Variable $self): Value => SplDllistJitHelper::compileBottom($ctx, $self)
            ),
            default => throw new \LogicException(
                $this->className.' JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }

    /**
     * php-src ZEND_PARSE_PARAMETERS_NONE — ACE cites defining class (#30911).
     *
     * @param callable(Context, Variable): Value $emit
     */
    private function callZeroArg(Context $context, array $args, string $function, callable $emit): Value
    {
        $userArgCount = \count($args) - 1;
        if (0 !== $userArgCount) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                \sprintf('%s() expects exactly 0 arguments, %d given', $function, $userArgCount)
            );
            $unreachable = BasicBlockHelper::append(
                $context,
                'spl_dllist_'.strtolower($this->method).'_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }

        return $emit($context, $args[0]);
    }
}
