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
use PHPCompiler\VM\SplFixedArrayJitHelper;
use PHPLLVM\Value;

/**
 * SplFixedArray thin-AOT methods (#26793, ext/spl/spl_fixedarray.c).
 */
final class SplFixedArrayMethod implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve (static fromArray). */
    public string $name;

    /** @var list<string> */
    public array $paramNames = [];

    /** Static factory — no implicit $this. */
    public int $namedArgsReceiverPrefix = 1;

    public function __construct(
        private readonly string $method,
    ) {
        $this->name = 'SplFixedArray::'.$method;
        if ('fromArray' === $method) {
            $this->paramNames = ['array', 'preserveKeys='];
            $this->namedArgsReceiverPrefix = 0;
        } elseif ('__construct' === $method) {
            $this->paramNames = ['size='];
        } elseif (\in_array($method, ['offsetGet', 'offsetExists', 'offsetUnset'], true)) {
            $this->paramNames = ['index'];
        } elseif ('offsetSet' === $method) {
            $this->paramNames = ['index', 'value'];
        }
    }

    public function methodName(): string
    {
        return $this->method;
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return match (strtolower($this->method)) {
            '__construct' => SplFixedArrayJitHelper::compileConstruct(
                $context,
                $args[0] ?? throw new \LogicException('SplFixedArray::__construct() called without $this'),
                $args[1] ?? null
            ),
            'fromarray' => SplFixedArrayJitHelper::compileFromArray($context, ...$args),
            'count', 'getsize' => $this->callExactArg(
                $context,
                $args,
                'SplFixedArray::'.$this->method,
                0,
                static fn (Context $ctx, Variable $self): Value => SplFixedArrayJitHelper::compileCount($ctx, $self)
            ),
            'offsetget' => $this->callExactArg(
                $context,
                $args,
                'SplFixedArray::offsetGet',
                1,
                static fn (Context $ctx, Variable $self, Variable $index): Value => SplFixedArrayJitHelper::compileOffsetGet(
                    $ctx,
                    $self,
                    $index
                )
            ),
            'offsetset' => $this->callExactArg(
                $context,
                $args,
                'SplFixedArray::offsetSet',
                2,
                static fn (Context $ctx, Variable $self, Variable $index, Variable $value): Value => SplFixedArrayJitHelper::compileOffsetSet(
                    $ctx,
                    $self,
                    $index,
                    $value
                )
            ),
            'offsetexists' => $this->callExactArg(
                $context,
                $args,
                'SplFixedArray::offsetExists',
                1,
                static fn (Context $ctx, Variable $self, Variable $index): Value => SplFixedArrayJitHelper::compileOffsetExists(
                    $ctx,
                    $self,
                    $index
                )
            ),
            'offsetunset' => $this->callExactArg(
                $context,
                $args,
                'SplFixedArray::offsetUnset',
                1,
                static fn (Context $ctx, Variable $self, Variable $index): Value => SplFixedArrayJitHelper::compileOffsetUnset(
                    $ctx,
                    $self,
                    $index
                )
            ),
            default => throw new \LogicException(
                'SplFixedArray JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }

    /**
     * php-src ZEND_PARSE_PARAMETERS_* — exact user arity (#30997).
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
        if ([] === $args) {
            throw new \LogicException($function.'() called without $this');
        }
        $userArgCount = max(0, \count($args) - 1);
        if ($userArgCount !== $expected) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage($function, $expected, $userArgCount)
            );
            $unreachable = BasicBlockHelper::append(
                $context,
                'spl_fixedarray_'.strtolower($this->method).'_argc_unreach'
            );
            $context->builder->positionAtEnd($unreachable);

            return JitValueBox::alloc($context);
        }

        return $emit($context, ...$args);
    }
}
