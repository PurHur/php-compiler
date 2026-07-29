<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * mt_rand() — Mersenne Twister PRNG (php-src ext/random/random.c, #3295).
 *
 * VM: {@see VmMt19937}; max &lt; min is ValueError (unlike rand() which swaps bounds).
 */
final class mt_rand extends Internal
{
    public function __construct()
    {
        parent::__construct('mt_rand');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        // Zend: 0 or 2 args; wrong arity uses "exactly 2" even though 0 is valid (php_mt_rand.c / #24641).
        if (0 !== $argc && 2 !== $argc) {
            throw new \ArgumentCountError(
                \sprintf('mt_rand() expects exactly 2 arguments, %d given', $argc)
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        if (0 === $argc) {
            $frame->returnVar->int(VmMt19937::mtRand31());

            return;
        }
        $min = VmMath::parseIntBuiltinArgForFrame($frame, 0, 'mt_rand', 1, 'min');
        $max = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'mt_rand', 2, 'max');
        $frame->returnVar->int(VmMt19937::range($min, $max));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitRand::call($context, true, 'mt_rand', ...$args);
    }
}
