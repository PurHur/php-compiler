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
 * ArrayIterator / RecursiveArrayIterator thin-AOT methods (#32910, #33606, #33613, #33616, ext/spl/spl_array.c).
 *
 * Storage is the same `__spl_ht` layout as ArrayObject (#26783 / #26823) — reuse
 * {@see ArrayObjectJitHelper} IR with ArrayIterator ACE names.
 */
final class ArrayIteratorMethod implements Call
{
    public function __construct(
        private readonly string $method,
        private readonly string $className = 'ArrayIterator',
    ) {
    }

    public function methodName(): string
    {
        return $this->method;
    }

    public function call(Context $context, Variable ...$args): Value
    {
        $qualified = $this->className.'::'.$this->method;

        return match (strtolower($this->method)) {
            'count' => $this->compileExact(
                $context,
                $args,
                $qualified,
                0,
                static fn () => ArrayObjectJitHelper::compileCount(
                    $context,
                    $args[0] ?? throw new \LogicException($qualified.'() called without $this')
                )
            ),
            'append' => $this->compileExact(
                $context,
                $args,
                $qualified,
                1,
                static fn () => ArrayObjectJitHelper::compileAppend(
                    $context,
                    $args[0] ?? throw new \LogicException($qualified.'() called without $this'),
                    $args[1]
                )
            ),
            // php-src zim_ArrayIterator_getArrayCopy — thin AOT was silent null (#34002).
            'getarraycopy' => $this->compileExact(
                $context,
                $args,
                $qualified,
                0,
                static fn () => ArrayObjectJitHelper::compileGetArrayCopy(
                    $context,
                    $args[0] ?? throw new \LogicException($qualified.'() called without $this')
                )
            ),
            'offsetget' => $this->compileExact(
                $context,
                $args,
                $qualified,
                1,
                static fn () => ArrayObjectJitHelper::compileOffsetGet(
                    $context,
                    $args[0] ?? throw new \LogicException($qualified.'() called without $this'),
                    $args[1]
                )
            ),
            'offsetset' => $this->compileExact(
                $context,
                $args,
                $qualified,
                2,
                static fn () => ArrayObjectJitHelper::compileOffsetSet(
                    $context,
                    $args[0] ?? throw new \LogicException($qualified.'() called without $this'),
                    $args[1],
                    $args[2]
                )
            ),
            'offsetexists' => $this->compileExact(
                $context,
                $args,
                $qualified,
                1,
                static fn () => ArrayObjectJitHelper::compileOffsetExists(
                    $context,
                    $args[0] ?? throw new \LogicException($qualified.'() called without $this'),
                    $args[1]
                )
            ),
            'offsetunset' => $this->compileExact(
                $context,
                $args,
                $qualified,
                1,
                static fn () => ArrayObjectJitHelper::compileOffsetUnset(
                    $context,
                    $args[0] ?? throw new \LogicException($qualified.'() called without $this'),
                    $args[1]
                )
            ),
            'asort' => $this->compileSortOptionalFlags(
                $context,
                $args,
                $qualified,
                static fn (?Variable $flags) => ArrayObjectJitHelper::compileAsort(
                    $context,
                    $args[0] ?? throw new \LogicException($qualified.'() called without $this'),
                    $flags,
                    $qualified
                )
            ),
            'ksort' => $this->compileSortOptionalFlags(
                $context,
                $args,
                $qualified,
                static fn (?Variable $flags) => ArrayObjectJitHelper::compileKsort(
                    $context,
                    $args[0] ?? throw new \LogicException($qualified.'() called without $this'),
                    $flags,
                    $qualified
                )
            ),
            'natsort' => $this->compileExact(
                $context,
                $args,
                $qualified,
                0,
                static fn () => ArrayObjectJitHelper::compileNatsort(
                    $context,
                    $args[0] ?? throw new \LogicException($qualified.'() called without $this')
                )
            ),
            'natcasesort' => $this->compileExact(
                $context,
                $args,
                $qualified,
                0,
                static fn () => ArrayObjectJitHelper::compileNatcasesort(
                    $context,
                    $args[0] ?? throw new \LogicException($qualified.'() called without $this')
                )
            ),
            // php-src zim_ArrayIterator_uasort/uksort — exactly 1 callback (#33613 / #9356).
            'uasort' => $this->compileExact(
                $context,
                $args,
                $qualified,
                1,
                static fn () => ArrayObjectJitHelper::compileUasort(
                    $context,
                    $args[0] ?? throw new \LogicException($qualified.'() called without $this'),
                    $args[1]
                )
            ),
            'uksort' => $this->compileExact(
                $context,
                $args,
                $qualified,
                1,
                static fn () => ArrayObjectJitHelper::compileUksort(
                    $context,
                    $args[0] ?? throw new \LogicException($qualified.'() called without $this'),
                    $args[1]
                )
            ),
            // php-src zim_ArrayIterator_getFlags/setFlags — thin AOT was a silent no-op (#33616).
            'getflags' => $this->compileExact(
                $context,
                $args,
                $qualified,
                0,
                fn () => ArrayObjectJitHelper::compileGetFlags(
                    $context,
                    $args[0] ?? throw new \LogicException($qualified.'() called without $this'),
                    $this->className
                )
            ),
            'setflags' => $this->compileExact(
                $context,
                $args,
                $qualified,
                1,
                fn () => ArrayObjectJitHelper::compileSetFlags(
                    $context,
                    $args[0] ?? throw new \LogicException($qualified.'() called without $this'),
                    $args[1],
                    $this->className,
                    $qualified
                )
            ),
            // php-src zim_ArrayIterator_serialize/unserialize — thin AOT silent-null (#579 / #35111)
            'serialize' => $this->compileExact(
                $context,
                $args,
                $qualified,
                0,
                fn () => ArrayObjectJitHelper::compileLegacySerialize(
                    $context,
                    $args[0] ?? throw new \LogicException($qualified.'() called without $this'),
                    $this->className
                )
            ),
            'unserialize' => $this->compileExact(
                $context,
                $args,
                $qualified,
                1,
                fn () => ArrayObjectJitHelper::compileLegacyUnserialize(
                    $context,
                    $args[0] ?? throw new \LogicException($qualified.'() called without $this'),
                    $args[1],
                    $this->className
                )
            ),
            default => throw new \LogicException(
                $this->className.' JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }

    /**
     * php-src ZEND_PARSE_PARAMETERS_* — $args[0] is $this (#30963, #30911).
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
            ExceptionBridge::emitArgumentCountErrorAndAbort(
                $context,
                VmClassMethod::exactUserArgCountMessage($function, $expected, $given)
            );
            BasicBlockHelper::ensureOpenInsertBlock(
                $context,
                'ai_'.strtolower($this->method).'_argc_cont'
            );

            return VmClassMethod::jitArgcDummyReturn($context);
        }

        return $compile();
    }

    /**
     * php-src zim_ArrayIterator_asort/ksort — optional $flags (#33606).
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
                'ai_'.strtolower($this->method).'_argc_cont'
            );

            return VmClassMethod::jitArgcDummyReturn($context);
        }

        return $compile(1 === $given ? $args[1] : null);
    }
}
