<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\Rand as RandBuiltin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * mt_srand() — seed Mersenne Twister (php-src ext/random/random.c, #3295).
 */
final class mt_srand extends Internal
{
    public function __construct()
    {
        parent::__construct('mt_srand');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 0 || $argc > 2) {
            throw new \ArgumentCountError(
                \sprintf('mt_srand() expects at most 2 arguments, %d given', $argc)
            );
        }
        $mode = VmMt19937::MT_RAND_MT19937;
        if (2 === $argc) {
            $modeArg = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'mt_srand', 2, 'mode');
            $mode = VmMt19937::MT_RAND_PHP === $modeArg
                ? VmMt19937::MT_RAND_PHP
                : VmMt19937::MT_RAND_MT19937;
        }
        if (0 === $argc) {
            VmMt19937::resetForTests();
            VmMt19937::ensureSeeded();
        } else {
            $seed = VmMath::parseIntBuiltinArgForFrame($frame, 0, 'mt_srand', 1, 'seed');
            VmMt19937::seed($seed, $mode);
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 0 || $argc > 2) {
            throw new \LogicException('mt_srand() expects at most 2 arguments');
        }
        RandBuiltin::ensureLinked($context);
        if (0 === $argc) {
            VmMt19937::resetForTests();
            VmMt19937::ensureSeeded();
        } else {
            $seed = JitLongArg::lower($context, $args[0], 'mt_srand() seed');
            $context->builder->call(RandBuiltin::seed($context), $seed);
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return $ptr;
    }
}
