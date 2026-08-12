<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\InternalStrictArg;
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
        if (InternalStrictArg::isCallerStrict($frame)) {
            InternalStrictArg::rejectNullString($frame->calledArgs[1], 'syslog', 'message', 1, $frame);
        }
        $message = VmString::coerceStringBuiltinArg($frame->calledArgs[1], 'syslog', 1, 'message');
        $frame->returnVar->bool(VmSyslog::syslog($priority, $message));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (2 !== $argc) {
            return JitSyslog::emitArgumentCountError(
                $context,
                'syslog() expects exactly 2 arguments, '.$argc.' given'
            );
        }

        return JitSyslog::syslog($context, $args[0], $args[1]);
    }
}
