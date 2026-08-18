<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Builtin\Rand as RandBuiltin;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitLongArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\NamedOptionalCallArgs;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * mt_srand() — seed Mersenne Twister (php-src ext/random/random.c, #3295, #23596).
 *
 * php-src: function mt_srand(int $seed = 0, int $mode = MT_RAND_MT19937): void
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
        if ($argc > 2) {
            throw new \ArgumentCountError(
                \sprintf('mt_srand() expects at most 2 arguments, %d given', $argc)
            );
        }
        $hasSeed = isset($frame->calledArgs[0]);
        $hasMode = isset($frame->calledArgs[1]);
        $mode = VmMt19937::MT_RAND_MT19937;
        if ($hasMode) {
            $modeArg = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'mt_srand', 2, 'mode');
            $mode = VmMt19937::MT_RAND_PHP === $modeArg
                ? VmMt19937::MT_RAND_PHP
                : VmMt19937::MT_RAND_MT19937;
        }
        if (!$hasSeed && !$hasMode) {
            VmMt19937::resetForTests();
            VmMt19937::ensureSeeded();

            return;
        }
        $seed = $hasSeed
            ? VmMath::parseIntBuiltinArgForFrame($frame, 0, 'mt_srand', 1, 'seed')
            : 0;
        VmMt19937::seed($seed, $mode);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 2) {
            throw new \ArgumentCountError(
                \sprintf('mt_srand() expects at most 2 arguments, %d given', $argc)
            );
        }
        RandBuiltin::ensureLinked($context);
        $hasSeed = isset($args[0]) && !NamedOptionalCallArgs::isOmittedOptional($args[0]);
        $hasMode = isset($args[1]) && !NamedOptionalCallArgs::isOmittedOptional($args[1]);
        if (!$hasSeed && !$hasMode) {
            VmMt19937::resetForTests();
            VmMt19937::ensureSeeded();
        } else {
            $i64 = $context->getTypeFromString('int64');
            $seed = $hasSeed
                ? JitLongArg::lower($context, $args[0], 'mt_srand() seed')
                : $i64->constInt(0, false);
            $mode = $hasMode
                ? JitLongArg::lower($context, $args[1], 'mt_srand() mode')
                : $i64->constInt(VmMt19937::MT_RAND_MT19937, false);
            $context->builder->call(RandBuiltin::seedWithMode($context), $seed, $mode);
        }
        $slot = JitValueBox::alloc($context);
        $ptr = JitValueBox::pointer($context, $slot);
        $context->builder->call($context->lookupFunction('__value__writeNull'), $ptr);

        return $ptr;
    }
}
