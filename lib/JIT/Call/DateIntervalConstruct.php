<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitDateIntervalConstruct;
use PHPCompiler\ext\standard\JitDateIntervalFormat;
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

/**
 * DateInterval::format() — JIT/AOT (#32699). Defined here so PSR-4 loads with
 * {@see DateIntervalConstruct} (peer DateTimeAdd in DateTimeModify.php).
 *
 * php-src: ext/date/php_date.c — zim_DateInterval_format
 */
final class DateIntervalFormat implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name = 'DateInterval::format';

    /** @var list<string> php-src ext/date/php_date.stub.php */
    public array $paramNames = ['format'];

    public function call(Context $context, Variable ...$args): Value
    {
        return JitDateIntervalFormat::invokeMethod($context, ...$args);
    }
}
