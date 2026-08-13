<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitDateMutation;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DateTimeImmutable::createFromMutable() — JIT/AOT (#30762).
 *
 * php-src: ext/date/php_date.c — zim_DateTimeImmutable_createFromMutable
 * SSOT: {@see JitDateMutation::invokeCreateFromMutable}
 */
final class DateTimeImmutableCreateFromMutable implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name = 'DateTimeImmutable::createFromMutable';

    /** @var list<string> php-src ext/date/php_date.stub.php */
    public array $paramNames = ['object'];

    /** Static factory — no implicit $this. */
    public int $namedArgsReceiverPrefix = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        return JitDateMutation::invokeCreateFromMutable($context, ...$args);
    }
}
