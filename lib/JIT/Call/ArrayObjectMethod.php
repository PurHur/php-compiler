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
 * ArrayObject thin-AOT methods (#26823, #33606, #33613, #33616, ext/spl/spl_array.c).
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
            'exchangearray' => $this->compileExact(
                $context,
                $args,
                'ArrayObject::exchangeArray',
                1,
                static fn () => ArrayObjectJitHelper::compileExchangeArray(
                    $context,
                    $args[0] ?? throw new \LogicException('ArrayObject::exchangeArray() called without $this'),
                    $args[1]
                )
            ),
            'offsetget' => $this->compileExact(
                $context,
                $args,
                'ArrayObject::offsetGet',
                1,
                static fn () => ArrayObjectJitHelper::compileOffsetGet(
                    $context,
                    $args[0] ?? throw new \LogicException('ArrayObject::offsetGet() called without $this'),
                    $args[1]
                )
            ),
            'offsetset' => $this->compileExact(
                $context,
                $args,
                'ArrayObject::offsetSet',
                2,
                static fn () => ArrayObjectJitHelper::compileOffsetSet(
                    $context,
                    $args[0] ?? throw new \LogicException('ArrayObject::offsetSet() called without $this'),
                    $args[1],
                    $args[2]
                )
            ),
            'offsetexists' => $this->compileExact(
                $context,
                $args,
                'ArrayObject::offsetExists',
                1,
                static fn () => ArrayObjectJitHelper::compileOffsetExists(
                    $context,
                    $args[0] ?? throw new \LogicException('ArrayObject::offsetExists() called without $this'),
                    $args[1]
                )
            ),
            'offsetunset' => $this->compileExact(
                $context,
                $args,
                'ArrayObject::offsetUnset',
                1,
                static fn () => ArrayObjectJitHelper::compileOffsetUnset(
                    $context,
                    $args[0] ?? throw new \LogicException('ArrayObject::offsetUnset() called without $this'),
                    $args[1]
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
            // php-src zim_ArrayObject_asort/ksort — at most 1 flags arg (#33606 / #19480).
            'asort' => $this->compileSortOptionalFlags(
                $context,
                $args,
                'ArrayObject::asort',
                static fn (?Variable $flags) => ArrayObjectJitHelper::compileAsort(
                    $context,
                    $args[0] ?? throw new \LogicException('ArrayObject::asort() called without $this'),
                    $flags,
                    'ArrayObject::asort'
                )
            ),
            'ksort' => $this->compileSortOptionalFlags(
                $context,
                $args,
                'ArrayObject::ksort',
                static fn (?Variable $flags) => ArrayObjectJitHelper::compileKsort(
                    $context,
                    $args[0] ?? throw new \LogicException('ArrayObject::ksort() called without $this'),
                    $flags,
                    'ArrayObject::ksort'
                )
            ),
            'natsort' => $this->compileExact(
                $context,
                $args,
                'ArrayObject::natsort',
                0,
                static fn () => ArrayObjectJitHelper::compileNatsort(
                    $context,
                    $args[0] ?? throw new \LogicException('ArrayObject::natsort() called without $this')
                )
            ),
            'natcasesort' => $this->compileExact(
                $context,
                $args,
                'ArrayObject::natcasesort',
                0,
                static fn () => ArrayObjectJitHelper::compileNatcasesort(
                    $context,
                    $args[0] ?? throw new \LogicException('ArrayObject::natcasesort() called without $this')
                )
            ),
            // php-src zim_ArrayObject_uasort/uksort — exactly 1 callback (#33613 / #30965).
            'uasort' => $this->compileExact(
                $context,
                $args,
                'ArrayObject::uasort',
                1,
                static fn () => ArrayObjectJitHelper::compileUasort(
                    $context,
                    $args[0] ?? throw new \LogicException('ArrayObject::uasort() called without $this'),
                    $args[1]
                )
            ),
            'uksort' => $this->compileExact(
                $context,
                $args,
                'ArrayObject::uksort',
                1,
                static fn () => ArrayObjectJitHelper::compileUksort(
                    $context,
                    $args[0] ?? throw new \LogicException('ArrayObject::uksort() called without $this'),
                    $args[1]
                )
            ),
            // php-src zim_ArrayObject_getFlags/setFlags — thin AOT was a silent no-op (#33616).
            'getflags' => $this->compileExact(
                $context,
                $args,
                'ArrayObject::getFlags',
                0,
                static fn () => ArrayObjectJitHelper::compileGetFlags(
                    $context,
                    $args[0] ?? throw new \LogicException('ArrayObject::getFlags() called without $this'),
                    'ArrayObject'
                )
            ),
            'setflags' => $this->compileExact(
                $context,
                $args,
                'ArrayObject::setFlags',
                1,
                static fn () => ArrayObjectJitHelper::compileSetFlags(
                    $context,
                    $args[0] ?? throw new \LogicException('ArrayObject::setFlags() called without $this'),
                    $args[1],
                    'ArrayObject',
                    'ArrayObject::setFlags'
                )
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

    /**
     * php-src zim_ArrayObject_asort/ksort — optional $flags (#33606).
     *
     * @param callable(?Variable): Value $compile
     */
    private function compileSortOptionalFlags(
        Context $context,
        array $args,
        string $function,
        callable $compile
    ): Value {
        $given = max(0, \count($args) - 1);
        if ($given > 1) {
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                $function.'() expects at most 1 argument, '.$given.' given'
            );
            BasicBlockHelper::ensureOpenInsertBlock(
                $context,
                'ao_'.strtolower($this->method).'_argc_cont'
            );

            return VmClassMethod::jitArgcDummyReturn($context);
        }

        return $compile(1 === $given ? $args[1] : null);
    }
}
