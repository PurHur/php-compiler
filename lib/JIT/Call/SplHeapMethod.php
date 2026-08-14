<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\spl\SplHeapBuiltin;
use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
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
            'insert' => $this->callExactArg(
                $context,
                $args,
                'SplHeap::insert',
                1,
                static fn (Context $ctx, Variable ...$callArgs): Value => SplHeapJitHelper::compileInsert(
                    $ctx,
                    $callArgs[0],
                    $callArgs[1]
                )
            ),
            'extract' => $this->callExactArg(
                $context,
                $args,
                'SplHeap::extract',
                0,
                static fn (Context $ctx, Variable $self): Value => SplHeapJitHelper::compileExtract($ctx, $self)
            ),
            'top' => $this->callExactArg(
                $context,
                $args,
                'SplHeap::top',
                0,
                static fn (Context $ctx, Variable $self): Value => SplHeapJitHelper::compileTop($ctx, $self)
            ),
            'count' => $this->callExactArg(
                $context,
                $args,
                'SplHeap::count',
                0,
                static fn (Context $ctx, Variable $self): Value => SplHeapJitHelper::compileCount($ctx, $self)
            ),
            'rewind' => $this->callExactArg(
                $context,
                $args,
                'SplHeap::rewind',
                0,
                static fn (Context $ctx, Variable $self): Value => SplHeapJitHelper::compileRewind($ctx, $self)
            ),
            'valid' => $this->callExactArg(
                $context,
                $args,
                'SplHeap::valid',
                0,
                static fn (Context $ctx, Variable $self): Value => SplHeapJitHelper::compileValid($ctx, $self)
            ),
            'current' => $this->callExactArg(
                $context,
                $args,
                'SplHeap::current',
                0,
                static fn (Context $ctx, Variable $self): Value => SplHeapJitHelper::compileCurrent($ctx, $self)
            ),
            'key' => $this->callExactArg(
                $context,
                $args,
                'SplHeap::key',
                0,
                static fn (Context $ctx, Variable $self): Value => SplHeapJitHelper::compileKey($ctx, $self)
            ),
            'next' => $this->callExactArg(
                $context,
                $args,
                'SplHeap::next',
                0,
                static fn (Context $ctx, Variable $self): Value => SplHeapJitHelper::compileNext($ctx, $self)
            ),
            default => throw new \LogicException(
                'SplHeap JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }

    /**
     * php-src ZEND_PARSE_PARAMETERS_* — ACE cites SplHeap (defining class) (#30955).
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
                'spl_heap_'.strtolower($this->method).'_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }

        return $emit($context, ...$args);
    }
}
