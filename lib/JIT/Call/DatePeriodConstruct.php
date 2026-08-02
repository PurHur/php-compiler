<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitDatePeriodConstruct;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\DatePeriodSupport;
use PHPLLVM\Value;

/**
 * DatePeriod::__construct() — JIT/AOT end-date form (#26772).
 *
 * php-src: ext/date/php_date.c — date_period_construct
 */
final class DatePeriodConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        $argc = \count($args);
        if ($argc < 2) {
            throw new \TypeError(DatePeriodSupport::CONSTRUCTOR_SIGNATURE_TYPE_ERROR);
        }
        $userArgs = $argc - 1;
        // ISO-8601 string form — factory / VM path.
        if ($userArgs <= 2) {
            throw new \LogicException(
                'DatePeriod::__construct(string $isostr) requires DatePeriod::createFromISO8601String() '
                .'or VM path in this compiler build (#26772)'
            );
        }
        if ($userArgs < 3) {
            throw new \TypeError(DatePeriodSupport::CONSTRUCTOR_SIGNATURE_TYPE_ERROR);
        }
        // After `new`, slots are often TYPE_VALUE-boxed objects — accept both (#26772).
        if (!self::isObjectish($args[1]) || !self::isObjectish($args[2])) {
            throw new \TypeError(DatePeriodSupport::CONSTRUCTOR_SIGNATURE_TYPE_ERROR);
        }
        // Recurrence-count form: 3rd user arg is int — not thin-AOT lowered yet.
        if (!self::isObjectish($args[3])) {
            throw new \LogicException(
                'DatePeriod::__construct(... int $recurrences) is not lowered for thin AOT in this build (#26772); '
                .'use end-date form'
            );
        }

        return JitDatePeriodConstruct::invokeFromEndDate($context, ...$args);
    }

    private static function isObjectish(Variable $arg): bool
    {
        return Variable::TYPE_OBJECT === $arg->type || Variable::TYPE_VALUE === $arg->type;
    }
}
