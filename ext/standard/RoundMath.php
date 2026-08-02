<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

/**
 * Pure float round() facade — delegates to {@see RoundJitHelper::roundArgv} (#26800).
 *
 * Kept so VmRound / tests can name the algorithm without pulling JIT helper vocabulary
 * into every call site. NestedJIT AOT path compiles RoundJitHelper alone (same-class).
 */
final class RoundMath
{
    public static function mathRound(float $value, int $places, int $mode): float
    {
        return RoundJitHelper::roundArgv($value, $places, $mode);
    }
}
