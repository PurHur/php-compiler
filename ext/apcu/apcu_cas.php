<?php

declare(strict_types=1);

namespace PHPCompiler\ext\apcu;

use PHPCompiler\Frame;
use PHPCompiler\ext\standard\VmMath;

/** apcu_cas() — PECL apcu compare-and-swap (#22253). */
final class apcu_cas extends ApcuFunction
{
    public function __construct()
    {
        parent::__construct('apcu_cas');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError(
                'apcu_cas() expects exactly 3 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }

        $key = self::parseKey($frame, 'apcu_cas', 0, 'key');
        $old = VmMath::parseIntBuiltinArgForFrame($frame, 1, 'apcu_cas', 2, 'old');
        $new = VmMath::parseIntBuiltinArgForFrame($frame, 2, 'apcu_cas', 3, 'new');
        $frame->returnVar->bool(VmApcu::cas($key, $old, $new));
    }
}
