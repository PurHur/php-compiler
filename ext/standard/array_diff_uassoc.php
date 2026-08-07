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

/** array_diff_uassoc() — exact-key diff with user key comparator (php-src ext/standard/array.c; #5644, #27218). */
final class array_diff_uassoc extends Internal
{
    public function execute(Frame $frame): void
    {
        VmArrayUserSetOps::diffUassoc($frame);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2) {
            throw new \ArgumentCountError(
                'array_diff_uassoc() expects at least 2 arguments, '.$argc.' given'
            );
        }
        if ($argc < 3) {
            VmArraySortCallback::requireUassocCallbackJitArg($args[$argc - 1], 'array_diff_uassoc', $argc);
        }
        $callback = $args[$argc - 1];
        if (!UsortCallbackPolicy::isJitLowerable($callback)) {
            throw new \LogicException(UsortCallbackPolicy::jitRejectionMessage());
        }
        $first = $args[0];
        $others = \array_slice($args, 1, -1);

        return JitArrayUserSetOps::arrayDiffUassoc($context, $callback, $first, ...$others);
    }
}
