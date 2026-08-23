<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\BasicBlockHelper;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\ExceptionBridge;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPCompiler\VM\CachingIteratorJitHelper;
use PHPLLVM\Value;

/**
 * CachingIterator thin-AOT getFlags/setFlags — `__flags` slot (#31694 AOT leftover).
 *
 * php-src: ext/spl/spl_iterators.c
 */
final class CachingIteratorMethod implements Call
{
    public function __construct(
        private readonly string $method,
    ) {
    }

    public function methodName(): string
    {
        return $this->method;
    }

    public function call(Context $context, Variable ...$args): Value
    {
        $qualified = 'CachingIterator::'.$this->method;

        return match (strtolower($this->method)) {
            'getflags' => $this->compileExact(
                $context,
                $args,
                $qualified,
                0,
                fn () => CachingIteratorJitHelper::compileGetFlags(
                    $context,
                    $args[0] ?? throw new \LogicException($qualified.'() called without $this')
                )
            ),
            'setflags' => $this->compileExact(
                $context,
                $args,
                $qualified,
                1,
                fn () => CachingIteratorJitHelper::compileSetFlags(
                    $context,
                    $args[0] ?? throw new \LogicException($qualified.'() called without $this'),
                    $args[1] ?? throw new \LogicException($qualified.'() expects exactly 1 argument, 0 given')
                )
            ),
            default => throw new \LogicException(
                'CachingIterator JIT lowering is not implemented for '.$this->method.'()'
            ),
        };
    }

    /**
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
                'caching_it_'.strtolower($this->method).'_argc_cont'
            );

            return VmClassMethod::jitArgcDummyReturn($context);
        }

        return $compile();
    }
}
