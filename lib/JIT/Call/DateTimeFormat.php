<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\DateTimeFormatJitHelper;
use PHPLLVM\Value;

/** DateTime/DateTimeImmutable::format(string $format) — JIT/AOT (#4043, ext/date/php_datetime.c). */
final class DateTimeFormat implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (\count($args) < 2) {
            throw new \LogicException('DateTime::format() requires $this and a format argument');
        }

        return DateTimeFormatJitHelper::compileFormat($context, $args[0], $args[1]);
    }
}
