<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
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
            // push lives on SplDoublyLinkedList; enqueue is SplQueue-only (php-src ACE cites defining class)
            'push', 'enqueue' => $this->callExactArg(
                $context,
                $args,
                'enqueue' === strtolower($this->method)
                    ? 'SplQueue::enqueue'
                    : 'SplDoublyLinkedList::push',
                1,
                static fn (Context $ctx, Variable $self, Variable $value): Value => SplDllistJitHelper::compilePush(
                    $ctx,
                    $self,
                    $value
                )
            ),
            'unshift' => $this->callExactArg(
                $context,
                $args,
                'SplDoublyLinkedList::unshift',
                1,
                static fn (Context $ctx, Variable $self, Variable $value): Value => SplDllistJitHelper::compileUnshift(
                    $ctx,
                    $self,
                    $value
                )
            ),
            'pop' => $this->callExactArg(
                $context,
                $args,
                'SplDoublyLinkedList::pop',
                0,
                static fn (Context $ctx, Variable $self): Value => SplDllistJitHelper::compilePop($ctx, $self)
            ),
            'shift', 'dequeue' => $this->callExactArg(
                $context,
                $args,
                'dequeue' === strtolower($this->method) ? 'SplQueue::dequeue' : 'SplDoublyLinkedList::shift',
                0,
                static fn (Context $ctx, Variable $self): Value => SplDllistJitHelper::compileShift($ctx, $self)
            ),
            'top' => $this->callExactArg(
                $context,
                $args,
                'SplDoublyLinkedList::top',
                0,
                static fn (Context $ctx, Variable $self): Value => SplDllistJitHelper::compileTop($ctx, $self)
            ),
            'bottom' => $this->callExactArg(
                $context,
                $args,
                'SplDoublyLinkedList::bottom',
                0,
                static fn (Context $ctx, Variable $self): Value => SplDllistJitHelper::compileBottom($ctx, $self)
            ),
            default => throw new \LogicException(
                $this->className.' JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }

    /**
     * php-src ZEND_PARSE_PARAMETERS_* — ACE cites defining class (#30911, #30964).
     *
     * @param callable(Context, Variable...): Value $emit
     */
    private function callExactArg(
        Context $context,
        array $args,
        string $function,
        int $expected,
        callable $emit
    ): Value {
        $userArgCount = max(0, \count($args) - 1);
        if ($userArgCount !== $expected) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage($function, $expected, $userArgCount)
            );
            $unreachable = BasicBlockHelper::append(
                $context,
                'spl_dllist_'.strtolower($this->method).'_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }

        return $emit($context, ...$args);
    }
}
