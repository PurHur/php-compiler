<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitTimezoneIdentifiersList;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DateTimeZone::listIdentifiers(?int $timezoneGroup, ?string $countryCode) — JIT/AOT (#29735).
 *
 * php-src: ext/date/php_date.c — PHP_METHOD(DateTimeZone, listIdentifiers)
 * Static — no implicit $this (peer NumberFormatter::create). Shares baking with
 * {@see timezone_identifiers_list}.
 */
final class DateTimeZoneListIdentifiers implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name = 'DateTimeZone::listIdentifiers';

    /** @var list<string> php-src php_date.stub.php */
    public array $paramNames = ['timezoneGroup', 'countryCode'];

    /** Static method — no implicit $this. */
    public int $namedArgsReceiverPrefix = 0;

    public function call(Context $context, Variable ...$args): Value
    {
        return JitTimezoneIdentifiersList::invoke($context, ...$args);
    }
}
