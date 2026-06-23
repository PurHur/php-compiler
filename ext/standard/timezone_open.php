<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\InternalStrictArg;
use PHPCompiler\VM\NativeDateInvalidTimeZoneException;
use PHPLLVM\Value;

/**
 * timezone_open() — procedural DateTimeZone factory (ext/date/php_date.c, #4634).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(timezone_open)
 */
final class timezone_open extends Internal
{
    public function __construct()
    {
        parent::__construct('timezone_open');
    }

    public function execute(Frame $frame): void
    {
        if (1 !== \count($frame->calledArgs)) {
            throw new \ArgumentCountError(
                \sprintf('timezone_open() expects exactly 1 argument, %d given', \count($frame->calledArgs))
            );
        }
        if (null === $frame->vmContext) {
            throw new \LogicException('timezone_open() requires VM context in this compiler build');
        }
        InternalStrictArg::rejectNullString($frame->calledArgs[0], 'timezone_open', 'timezone', 0);
        $timezone = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'timezone_open',
            0,
            'timezone'
        );
        try {
            $zone = DateTimeSupport::newDateTimeZoneVariable($frame->vmContext, $timezone);
            BuiltinExecute::writeReturn($frame, static function ($ret) use ($zone): void {
                $ret->copyFrom($zone);
            });
        } catch (NativeDateInvalidTimeZoneException) {
            if (null !== $frame->vmContext) {
                $frame->vmContext->errors->triggerError(
                    "timezone_open(): Unknown or bad timezone ({$timezone})",
                    ErrorReporter::E_WARNING,
                    '' !== $frame->scriptPath ? $frame->scriptPath : null,
                    $frame->vmContext,
                    $frame
                );
            }
            BuiltinExecute::writeReturn($frame, static function ($ret): void {
                $ret->bool(false);
            });
        }
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitTimezoneOpen::invoke($context, ...$args);
    }
}
