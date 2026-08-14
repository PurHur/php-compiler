<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\ArrayObjectJitHelper;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/**
 * ArrayObject thin-AOT methods (#26823, ext/spl/spl_array.c).
 */
final class ArrayObjectMethod implements Call
{
    public function __construct(
        private readonly string $method,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return match (strtolower($this->method)) {
            'count' => ArrayObjectJitHelper::compileCount(
                $context,
                $args[0] ?? throw new \LogicException('ArrayObject::count() called without $this')
            ),
            'append' => ArrayObjectJitHelper::compileAppend(
                $context,
                $args[0] ?? throw new \LogicException('ArrayObject::append() called without $this'),
                $args[1] ?? throw new \ArgumentCountError(
                    'ArrayObject::append() expects exactly 1 argument, 0 given'
                )
            ),
            'getarraycopy' => $this->compileExact(
                $context,
                $args,
                'ArrayObject::getArrayCopy',
                0,
                static fn () => ArrayObjectJitHelper::compileGetArrayCopy(
                    $context,
                    $args[0] ?? throw new \LogicException('ArrayObject::getArrayCopy() called without $this')
                )
            ),
            'offsetget' => ArrayObjectJitHelper::compileOffsetGet(
                $context,
                $args[0] ?? throw new \LogicException('ArrayObject::offsetGet() called without $this'),
                $args[1] ?? throw new \ArgumentCountError(
                    'ArrayObject::offsetGet() expects exactly 1 argument, 0 given'
                )
            ),
            'offsetset' => ArrayObjectJitHelper::compileOffsetSet(
                $context,
                $args[0] ?? throw new \LogicException('ArrayObject::offsetSet() called without $this'),
                $args[1] ?? throw new \ArgumentCountError(
                    'ArrayObject::offsetSet() expects exactly 2 arguments, 0 given'
                ),
                $args[2] ?? throw new \ArgumentCountError(
                    'ArrayObject::offsetSet() expects exactly 2 arguments, 1 given'
                )
            ),
            'offsetexists' => ArrayObjectJitHelper::compileOffsetExists(
                $context,
                $args[0] ?? throw new \LogicException('ArrayObject::offsetExists() called without $this'),
                $args[1] ?? throw new \ArgumentCountError(
                    'ArrayObject::offsetExists() expects exactly 1 argument, 0 given'
                )
            ),
            'offsetunset' => ArrayObjectJitHelper::compileOffsetUnset(
                $context,
                $args[0] ?? throw new \LogicException('ArrayObject::offsetUnset() called without $this'),
                $args[1] ?? throw new \ArgumentCountError(
                    'ArrayObject::offsetUnset() expects exactly 1 argument, 0 given'
                )
            ),
            'getiteratorclass' => $this->compileExact(
                $context,
                $args,
                'ArrayObject::getIteratorClass',
                0,
                static fn () => ArrayObjectJitHelper::compileGetIteratorClass(
                    $context,
                    $args[0] ?? throw new \LogicException('ArrayObject::getIteratorClass() called without $this')
                )
            ),
            'getiterator' => ArrayObjectJitHelper::compileGetIterator(
                $context,
                $args[0] ?? throw new \LogicException('ArrayObject::getIterator() called without $this')
            ),
            default => throw new \LogicException(
                'ArrayObject JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }

    /**
     * php-src ZEND_PARSE_PARAMETERS_* — $args[0] is $this (#30965).
     *
     * @param callable(): Value $compile
     */
    private function compileExact(
        Context $context,
        array $args,
        string $function,
        int $expected,
        callable $compile
    ): Value {
        $given = max(0, \count($args) - 1);
        if ($given !== $expected) {
            // ZEND_PARSE_PARAMETERS_NONE / exact arity — "exactly N", not "at most".
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage($function, $expected, $given)
            );
            BasicBlockHelper::ensureOpenInsertBlock(
                $context,
                'ao_'.strtolower($this->method).'_argc_cont'
            );

            return VmClassMethod::jitArgcDummyReturn($context);
        }

        return $compile();
    }
}
