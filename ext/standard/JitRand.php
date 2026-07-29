<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Builtin\Rand as RandBuiltin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** LLVM JIT helpers for rand() (issue #11908). */
final class JitRand
{
    /**
     * @param bool $mtRand when true, max<min is ValueError; rand() swaps bounds
     */
    public static function call(Context $context, bool $mtRand, string $fn = 'rand', JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (0 !== $argc && 2 !== $argc) {
            throw new \LogicException(\sprintf('%s() expects 0 or 2 arguments', $fn));
        }
        RandBuiltin::ensureLinked($context);
        if (0 === $argc) {
            return $context->builder->call(RandBuiltin::mtRand31($context));
        }
        $min = JitLongArg::lower($context, $args[0], 'rand() min');
        $max = JitLongArg::lower($context, $args[1], 'rand() max');
        $fn = $mtRand ? RandBuiltin::mtRandRange($context) : RandBuiltin::randRange($context);

        return $context->builder->call($fn, $min, $max);
    }
}
