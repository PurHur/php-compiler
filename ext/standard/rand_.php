<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * rand() — Mersenne Twister PRNG (php-src ext/random/random.c, #11908).
 *
 * VM: {@see VmMt19937}; delegates to same engine as mt_rand() on Zend 8.2.
 */
final class rand_ extends Internal
{
    public function __construct()
    {
        parent::__construct('rand');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 0 || $argc > 2 || 1 === $argc) {
            throw new \ArgumentCountError(
                \sprintf('rand() expects at most 2 arguments, %d given', $argc)
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (0 === $argc) {
            $frame->returnVar->int(VmMt19937::mtRand31());

            return;
        }
        $min = VmMath::parseIntBuiltinArgForFrame($frame, 0, 'rand', 1, 'min');
        $max = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'rand', 2, 'max');
        $frame->returnVar->int(VmMt19937::randRange($min, $max));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitRand::call($context, false, ...$args);
    }
}
