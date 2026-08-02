<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitDateTimeConstruct;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/** DateTime::__construct() — JIT/AOT (#26772, ext/date/php_date.c). */
final class DateTimeConstruct implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return JitDateTimeConstruct::invoke($context, false, ...$args);
    }
}
