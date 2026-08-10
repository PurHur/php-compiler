<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitDateIntervalCreateFromDateString;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DateInterval::createFromDateString(string $datetime) — JIT named-arg + Reflection parity (#24589).
 *
 * Delegates LLVM lowering to {@see JitDateIntervalCreateFromDateString} (same as the free-function
 * builtin). php-src: Zend/../ext/date/php_date.stub.php
 */
final class DateIntervalCreateFromDateString implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve (#24589). */
    public string $name = 'DateInterval::createFromDateString';

    /** @var list<string> php-src ext/date/php_date.stub.php — InternalArgInfo still says time */
    public array $paramNames = ['datetime'];

    /** Static factory — no implicit $this (#24589). */
    public int $namedArgsReceiverPrefix = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        return JitDateIntervalCreateFromDateString::invokeNamed($context, 'DateInterval::createFromDateString', ...$args);
    }
}
