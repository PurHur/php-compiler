<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitArrayUserSetOps;
use PHPCompiler\JIT\UsortCallbackPolicy;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** array_udiff_assoc() — exact-key diff with user value comparator (php-src ext/standard/array.c; #5644, #27218). */
final class array_udiff_assoc extends Internal
{
    public function execute(Frame $frame): void
    {
        VmArrayUserSetOps::udiffAssoc($frame);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 3) {
            throw new \ArgumentCountError(
                'array_udiff_assoc() expects at least 3 arguments, '.$argc.' given'
            );
        }
        $callback = $args[$argc - 1];
        if (!UsortCallbackPolicy::isJitLowerable($callback)) {
            throw new \LogicException(UsortCallbackPolicy::jitRejectionMessage());
        }
        $first = $args[0];
        $others = \array_slice($args, 1, -1);

        return JitArrayUserSetOps::arrayUdiffAssoc($context, $callback, $first, ...$others);
    }
}
