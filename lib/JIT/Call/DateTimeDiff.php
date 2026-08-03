<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitDateMutation;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DateTime::diff() / DateTimeImmutable::diff() — JIT/AOT (#27309).
 *
 * php-src: ext/date/php_date.c — zim_DateTime_diff / date_diff
 * Shares lowering with {@see date_diff}.
 */
final class DateTimeDiff implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDateMutation::invokeDiffMethod($context, ...$args);
    }
}
