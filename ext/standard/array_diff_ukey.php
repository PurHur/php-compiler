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

/** array_diff_ukey() — key diff with user comparator (php-src ext/standard/array.c; #5644). */
final class array_diff_ukey extends Internal
{
    public function execute(Frame $frame): void
    {
        VmArrayUserSetOps::diffUkey($frame);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 3) {
            throw new \ArgumentCountError(
                'array_diff_ukey() expects at least 3 arguments, '.$argc.' given'
            );
        }
        $callback = $args[$argc - 1];
        if (!UsortCallbackPolicy::isJitLowerable($callback)) {
            throw new \LogicException(UsortCallbackPolicy::jitRejectionMessage());
        }
        $first = $args[0];
        $others = \array_slice($args, 1, -1);

        return JitArrayUserSetOps::arrayDiffUkey($context, $callback, $first, ...$others);
    }
}
