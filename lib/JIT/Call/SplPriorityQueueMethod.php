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
            'insert' => $this->callExactArg(
                $context,
                $args,
                'SplPriorityQueue::insert',
                2,
                static fn (Context $ctx, Variable ...$callArgs): Value => SplPriorityQueueJitHelper::compileInsert(
                    $ctx,
                    $callArgs[0],
                    $callArgs[1],
                    $callArgs[2]
                )
            ),
            'extract' => $this->callExactArg(
                $context,
                $args,
                'SplPriorityQueue::extract',
                0,
                static fn (Context $ctx, Variable $self): Value => SplPriorityQueueJitHelper::compileExtract($ctx, $self)
            ),
            'top' => $this->callExactArg(
                $context,
                $args,
                'SplPriorityQueue::top',
                0,
                static fn (Context $ctx, Variable $self): Value => SplPriorityQueueJitHelper::compileTop($ctx, $self)
            ),
            'count' => $this->callExactArg(
                $context,
                $args,
                'SplPriorityQueue::count',
                0,
                static fn (Context $ctx, Variable $self): Value => SplPriorityQueueJitHelper::compileCount($ctx, $self)
            ),
            'rewind' => $this->callExactArg(
                $context,
                $args,
                'SplPriorityQueue::rewind',
                0,
                static fn (Context $ctx, Variable $self): Value => SplPriorityQueueJitHelper::compileRewind($ctx, $self)
            ),
            'valid' => $this->callExactArg(
                $context,
                $args,
                'SplPriorityQueue::valid',
                0,
                static fn (Context $ctx, Variable $self): Value => SplPriorityQueueJitHelper::compileValid($ctx, $self)
            ),
            'current' => $this->callExactArg(
                $context,
                $args,
                'SplPriorityQueue::current',
                0,
                static fn (Context $ctx, Variable $self): Value => SplPriorityQueueJitHelper::compileCurrent($ctx, $self)
            ),
            'key' => $this->callExactArg(
                $context,
                $args,
                'SplPriorityQueue::key',
                0,
                static fn (Context $ctx, Variable $self): Value => SplPriorityQueueJitHelper::compileKey($ctx, $self)
            ),
            'next' => $this->callExactArg(
                $context,
                $args,
                'SplPriorityQueue::next',
                0,
                static fn (Context $ctx, Variable $self): Value => SplPriorityQueueJitHelper::compileNext($ctx, $self)
            ),
            default => throw new \LogicException(
                'SplPriorityQueue JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }

    /**
     * php-src ZEND_PARSE_PARAMETERS_* — ACE cites SplPriorityQueue (#30955).
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
                'spl_pqueue_'.strtolower($this->method).'_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }

        return $emit($context, ...$args);
    }
}
