<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\DateIntervalSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * date_interval_create_from_date_string() — parse relative interval spec (#4606, ext/date/php_date.c).
 *
 * VM: {@see VmDateInterval::parseFromDateString}. JIT/AOT: {@see JitDateIntervalCreateFromDateString}.
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(date_interval_create_from_date_string)
 */
final class date_interval_create_from_date_string extends Internal
{
    public function __construct()
    {
        parent::__construct('date_interval_create_from_date_string');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'date_interval_create_from_date_string() expects exactly 1 argument, %d given',
                $argc
            ));
        }
        if (null === $frame->returnVar || null === $frame->vmContext) {
            return;
        }

        // Z_PARAM_STR — caller strict_types → TypeError on null (#29843).
        $datetime = VmString::zparamStrBuiltinArgForFrame(
            $frame,
            0,
            'date_interval_create_from_date_string',
            0,
            'datetime'
        );

        $warning = null;
        $parsed = VmDateInterval::parseFromDateString($datetime, $warning);
        if (null === $parsed) {
            $frame->vmContext->errors->triggerError(
                'date_interval_create_from_date_string(): '.$warning,
                ErrorReporter::E_WARNING,
                '' !== $frame->scriptPath ? $frame->scriptPath : null,
                $frame->vmContext,
                $frame
            );
            BuiltinExecute::writeReturn($frame, static function (Variable $ret): void {
                $ret->bool(false);
            });

            return;
        }

        $interval = DateIntervalSupport::createFromDateString(
            $frame->vmContext,
            $datetime,
            $parsed
        );
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($interval): void {
            $ret->object($interval);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitDateIntervalCreateFromDateString::invoke($context, ...$args);
    }
}
