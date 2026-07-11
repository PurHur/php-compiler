<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;

/** fmin()/fmax() variadic float reduction (php-src ext/standard/math.c; #11728). */
final class VmFminMax
{
    public static function fmin(Frame $frame): void
    {
        self::reduce($frame, 'fmin', true);
    }

    public static function fmax(Frame $frame): void
    {
        self::reduce($frame, 'fmax', false);
    }

    private static function reduce(Frame $frame, string $name, bool $pickMin): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                \sprintf('%s() expects at least 2 arguments, %d given', $name, $argc)
            );
        }
        $best = VmMath::parseDoubleBuiltinArg(
            $frame->calledArgs[0]->resolveIndirect(),
            $name,
            1,
            'num'
        );
        for ($i = 1; $i < $argc; ++$i) {
            $candidate = VmMath::parseDoubleBuiltinArg(
                $frame->calledArgs[$i]->resolveIndirect(),
                $name,
                $i + 1,
                'nums'
            );
            $best = $pickMin
                ? VmMath::fminPair($best, $candidate)
                : VmMath::fmaxPair($best, $candidate);
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->float($best);
    }
}
