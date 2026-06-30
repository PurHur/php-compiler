<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\DateTimeSupport;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/**
 * timezone_transitions_get() — procedural DateTimeZone DST transitions (ext/date/php_date.c, #6041).
 *
 * php-src: ext/date/php_date.c — PHP_FUNCTION(timezone_transitions_get)
 */
final class timezone_transitions_get extends Internal
{
    public function __construct()
    {
        parent::__construct('timezone_transitions_get');
    }

    public function execute(Frame $frame): void
    {
        $argc = \count($frame->calledArgs);
        if ($argc < 1 || $argc > 3) {
            throw new \ArgumentCountError(
                \sprintf('timezone_transitions_get() expects between 1 and 3 arguments, %d given', $argc)
            );
        }
        $zone = DateTimeSupport::requireDateTimeZone(
            $frame->calledArgs[0],
            'timezone_transitions_get(): Argument #1 ($object)'
        );
        $begin = \PHP_INT_MIN;
        $end = \PHP_INT_MAX;
        if ($argc >= 2) {
            $begin = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[1],
                'timezone_transitions_get',
                2,
                'timestamp_begin'
            );
        }
        if ($argc >= 3) {
            $end = VmMath::parseIntBuiltinArg(
                $frame->calledArgs[2],
                'timezone_transitions_get',
                3,
                'timestamp_end'
            );
        }
        $transitions = VmDateTimeNative::timezoneTransitions(
            DateTimeSupport::timezoneName($zone),
            $begin,
            $end
        );
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($transitions): void {
            DateTimeSupport::timezoneTransitionsInto($transitions, $ret);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        return JitTimezoneTransitionsGet::invoke($context, ...$args);
    }
}
