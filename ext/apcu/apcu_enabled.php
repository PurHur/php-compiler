<?php

declare(strict_types=1);

namespace PHPCompiler\ext\apcu;

use PHPCompiler\Frame;

/** apcu_enabled() — PECL apcu usability probe (#22253). */
final class apcu_enabled extends ApcuFunction
{
    public function __construct()
    {
        parent::__construct('apcu_enabled');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(
                'apcu_enabled() expects exactly 0 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmApcu::enabled());
    }
}
