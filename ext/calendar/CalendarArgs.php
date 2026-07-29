<?php

declare(strict_types=1);

namespace PHPCompiler\ext\calendar;

use PHPCompiler\ext\standard\VmMath;
use PHPCompiler\Frame;
use PHPCompiler\VM\Variable;

/** Shared VM argument parsing for ext/calendar builtins (php-src ext/calendar/calendar.c). */
final class CalendarArgs
{
    /**
     * Z_PARAM_LONG — null deprecates and coerces to 0 under php-src-strict (incl. PROFILE=8.4;
     * TypeError is PHP 9.0). Matches Zend ext/calendar/*.c (#24864).
     */
    public static function requireInt(Frame $frame, string $fn, int $position, string $name): int
    {
        return VmMath::parseZParamLongBuiltinArgForFrame(
            $frame,
            $position - 1,
            $fn,
            $position,
            $name
        );
    }

    /**
     * Z_PARAM_LONG_OR_NULL — omitted/null stay null (unixtojd ?int $timestamp = null; #24863).
     */
    public static function optionalInt(Frame $frame, string $fn, int $position, string $name): ?int
    {
        if (\count($frame->calledArgs) < $position) {
            return null;
        }
        $var = $frame->calledArgs[$position - 1]->resolveIndirect();
        if (Variable::TYPE_NULL === $var->type) {
            return null;
        }

        return self::requireInt($frame, $fn, $position, $name);
    }

    public static function assertValidCalendarId(int $calendar, string $fn, int $position = 1): void
    {
        if ($calendar < 0 || $calendar >= CalendarConstants::CAL_NUM_CALS) {
            throw new \ValueError(\sprintf(
                '%s(): Argument #%d ($calendar) must be a valid calendar ID',
                $fn,
                $position
            ));
        }
    }
}
