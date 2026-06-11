<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** syslog() — generate a system log message (ext/standard/syslog.c; #3676). */
final class syslog extends Internal
{
    public function __construct()
    {
        parent::__construct('syslog');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (2 !== $argc) {
            throw new \ArgumentCountError('syslog() expects exactly 2 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $priority = VmMath::parseIntBuiltinArg($frame->calledArgs[0], 'syslog', 0, 'priority');
        $message = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'syslog', 1, 'message');
        $frame->returnVar->bool(VmSyslog::syslog($priority, $message));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException('syslog() is not implemented for JIT in this compiler build (issue #3676)');
    }
}
