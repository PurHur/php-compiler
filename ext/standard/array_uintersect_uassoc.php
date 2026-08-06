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

/** array_uintersect_uassoc() — key+value intersect with user comparators (php-src ext/standard/array.c; #5644, #27243). */
final class array_uintersect_uassoc extends Internal
{
    public function execute(Frame $frame): void
    {
        VmArrayUserSetOps::uintersectUassoc($frame);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 4) {
            throw new \ArgumentCountError(
                'array_uintersect_uassoc() expects at least 4 arguments, '.$argc.' given'
            );
        }
        $valueCallback = $args[$argc - 2];
        $keyCallback = $args[$argc - 1];
        if (!UsortCallbackPolicy::isJitLowerable($valueCallback)
            || !UsortCallbackPolicy::isJitLowerable($keyCallback)
        ) {
            throw new \LogicException(UsortCallbackPolicy::jitRejectionMessage());
        }
        $first = $args[0];
        $others = \array_slice($args, 1, -2);

        return JitArrayUserSetOps::arrayUintersectUassoc(
            $context,
            $valueCallback,
            $keyCallback,
            $first,
            ...$others
        );
    }
}
