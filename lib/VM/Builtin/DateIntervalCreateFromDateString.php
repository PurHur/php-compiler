<?php

declare(strict_types=1);

namespace PHPCompiler\VM\Builtin;

use PHPCompiler\ext\standard\VmDateInterval;
use PHPCompiler\ext\standard\VmString;
use PHPCompiler\Frame;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\DateIntervalSupport;
use PHPCompiler\VM\ErrorReporter;
use PHPCompiler\VM\Variable;

/**
 * DateInterval::createFromDateString() — OOP alias of date_interval_create_from_date_string() (#9993).
 *
 * php-src: ext/date/php_date.c — PHP_METHOD(DateInterval, createFromDateString)
 */
final class DateIntervalCreateFromDateString extends VmClassMethod
{
    public function __construct()
    {
        parent::__construct('createFromDateString');
    }

    public function execute(Frame $frame): void
    {
        if (null === $frame->vmContext) {
            throw new \LogicException('DateInterval::createFromDateString() requires VM context');
        }
        $argc = \count($frame->calledArgs);
        if (1 !== $argc) {
            throw new \ArgumentCountError(\sprintf(
                'DateInterval::createFromDateString() expects exactly 1 argument, %d given',
                $argc
            ));
        }

        $datetime = VmString::coerceStringBuiltinArg(
            $frame->calledArgs[0],
            'DateInterval::createFromDateString',
            0,
            'datetime'
        );

        $warning = null;
        $parsed = VmDateInterval::parseFromDateString($datetime, $warning);
        if (null === $parsed) {
            $frame->vmContext->errors->triggerError(
                'DateInterval::createFromDateString(): '.$warning,
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

        $interval = DateIntervalSupport::createFromState(
            $frame->vmContext,
            [...$parsed, 'days' => false]
        );
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($interval): void {
            $ret->object($interval);
        });
    }
}
