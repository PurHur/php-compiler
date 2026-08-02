<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitDateIntervalConstruct;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DateInterval::__construct() — JIT/AOT (#26772, ext/date/php_date.c). */
final class DateIntervalConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDateIntervalConstruct::invoke($context, ...$args);
    }
}
