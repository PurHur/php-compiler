<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/**
 * openlog() — open connection to system logger (ext/standard/syslog.c; #3676).
 *
 * Z_PARAM_STRING $prefix — soft-null DEP+coerce; caller strict_types → TypeError (#30372).
 *
 * @see https://github.com/php/php-src/blob/master/ext/standard/syslog.c PHP_FUNCTION(openlog)
 */
final class openlog extends Internal
{
    public function __construct()
    {
        parent::__construct('openlog');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (3 !== $argc) {
            throw new \ArgumentCountError('openlog() expects exactly 3 arguments, '.$argc.' given');
        }
        if (null === $frame->returnVar) {
            return;
        }

        $ident = VmString::stringBuiltinArgForFrame(
            $frame,
            0,
            'openlog',
            0,
            'prefix',
            false
        );
        $option = VmMath::parseIntBuiltinArg($frame->calledArgs[1], 'openlog', 1, 'option');
        $facility = VmMath::parseIntBuiltinArg($frame->calledArgs[2], 'openlog', 2, 'facility');
        $frame->returnVar->bool(VmSyslog::openlog($ident, $option, $facility));
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        $argc = \count($args);
        if (3 !== $argc) {
            return JitSyslog::emitArgumentCountError(
                $context,
                'openlog() expects exactly 3 arguments, '.$argc.' given'
            );
        }

        return JitSyslog::openlog($context, $args[0], $args[1], $args[2]);
    }
}
