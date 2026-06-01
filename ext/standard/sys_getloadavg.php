<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** sys_getloadavg() — 1/5/15 minute load averages (ext/standard/syslog.c, issue #3464). */
final class sys_getloadavg extends Internal
{
    public function __construct()
    {
        parent::__construct('sys_getloadavg');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 0) {
            throw new \ArgumentCountError('sys_getloadavg() expects exactly 0 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }
        $avg = VmSys::getLoadavg();
        if (false === $avg) {
            $frame->returnVar->bool(false);
        } else {
            $frame->returnVar->array(VmSys::loadavgToHashTable($avg));
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (0 !== \count($args)) {
            throw new \ArgumentCountError('sys_getloadavg() expects exactly 0 arguments, '.\count($args).' given');
        }

        throw new \LogicException('sys_getloadavg() is not available in JIT/AOT in this compiler build');
    }
}
