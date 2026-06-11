<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** closelog() — close connection to system logger (ext/standard/syslog.c; #3676). */
final class closelog extends Internal
{
    public function __construct()
    {
        parent::__construct('closelog');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc > 0) {
            throw new \ArgumentCountError('closelog() expects exactly 0 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $frame->returnVar->bool(VmSyslog::closelog());
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if ($argc > 0) {
            return JitSyslog::emitArgumentCountError(
                $context,
                'closelog() expects exactly 0 arguments, '.$argc.' given'
            );
        }

        return JitSyslog::closelog($context);
    }
}
