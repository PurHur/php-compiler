<?php

declare(strict_types=1);

namespace PHPCompiler\ext\apcu;

use PHPCompiler\Frame;

/** apcu_clear_cache() — PECL apcu / php-src ext/apcu (#6574). */
final class apcu_clear_cache extends ApcuFunction
{
    public function __construct()
    {
        parent::__construct('apcu_clear_cache');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (0 !== $argc) {
            throw new \ArgumentCountError(
                'apcu_clear_cache() expects exactly 0 arguments, '.$argc.' given'
            );
        }
        if (null === $frame->returnVar) {
            return;
        }
        $frame->returnVar->bool(VmApcu::clear());
    }
}
