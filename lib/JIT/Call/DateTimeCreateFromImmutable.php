<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitDateMutation;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DateTime::createFromImmutable() — JIT/AOT (#30762).
 *
 * php-src: ext/date/php_date.c — zim_DateTime_createFromImmutable
 * SSOT: {@see JitDateMutation::invokeCreateFromImmutable}
 */
final class DateTimeCreateFromImmutable implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name = 'DateTime::createFromImmutable';

    /** @var list<string> php-src ext/date/php_date.stub.php */
    public array $paramNames = ['object'];

    /** Static factory — no implicit $this. */
    public int $namedArgsReceiverPrefix = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        return JitDateMutation::invokeCreateFromImmutable($context, ...$args);
    }
}
