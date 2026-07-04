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
 * srand() — legacy alias seeding Mersenne Twister (php-src ext/random/random.c, #3295).
 */
final class srand extends Internal
{
    public function __construct()
    {
        parent::__construct('srand');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 0 || $argc > 1) {
            throw new \ArgumentCountError(
                \sprintf('srand() expects at most 1 argument, %d given', $argc)
            );
        }
        if (0 === $argc) {
            VmMt19937::resetForTests();
            VmMt19937::ensureSeeded();

            return;
        }
        $seed = VmMath::parseIntBuiltinArgForFrame($frame, 0, 'srand', 1, 'seed');
        VmMt19937::seed($seed);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 0 || $argc > 1) {
            throw new \LogicException('srand() expects at most 1 argument');
        }
        RandBuiltin::ensureLinked($context);
        if (0 === $argc) {
            VmMt19937::resetForTests();
            VmMt19937::ensureSeeded();
        } else {
            $seed = JitLongArg::lower($context, $args[0], 'srand() seed');
            $context->builder->call(RandBuiltin::seed($context), $seed);
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return $ptr;
    }
}
