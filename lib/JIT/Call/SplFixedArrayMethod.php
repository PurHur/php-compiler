<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
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
            'count', 'getsize' => SplFixedArrayJitHelper::compileCount(
                $context,
                $args[0] ?? throw new \LogicException('SplFixedArray::'.$this->method.'() called without $this')
            ),
            'offsetget' => SplFixedArrayJitHelper::compileOffsetGet(
                $context,
                $args[0] ?? throw new \LogicException('SplFixedArray::offsetGet() called without $this'),
                $args[1] ?? throw new \ArgumentCountError(
                    'SplFixedArray::offsetGet() expects exactly 1 argument, 0 given'
                )
            ),
            'offsetset' => SplFixedArrayJitHelper::compileOffsetSet(
                $context,
                $args[0] ?? throw new \LogicException('SplFixedArray::offsetSet() called without $this'),
                $args[1] ?? throw new \ArgumentCountError(
                    'SplFixedArray::offsetSet() expects exactly 2 arguments, 0 given'
                ),
                $args[2] ?? throw new \ArgumentCountError(
                    'SplFixedArray::offsetSet() expects exactly 2 arguments, 1 given'
                )
            ),
            'offsetexists' => SplFixedArrayJitHelper::compileOffsetExists(
                $context,
                $args[0] ?? throw new \LogicException('SplFixedArray::offsetExists() called without $this'),
                $args[1] ?? throw new \ArgumentCountError(
                    'SplFixedArray::offsetExists() expects exactly 1 argument, 0 given'
                )
            ),
            'offsetunset' => SplFixedArrayJitHelper::compileOffsetUnset(
                $context,
                $args[0] ?? throw new \LogicException('SplFixedArray::offsetUnset() called without $this'),
                $args[1] ?? throw new \ArgumentCountError(
                    'SplFixedArray::offsetUnset() expects exactly 1 argument, 0 given'
                )
            ),
            default => throw new \LogicException(
                'SplFixedArray JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }
}
