<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitDateMutation;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DateTime::createFromInterface() / DateTimeImmutable::createFromInterface() — JIT/AOT (#30762).
 *
 * php-src: ext/date/php_date.c — zim_DateTime_createFromInterface / Immutable sibling.
 * SSOT: {@see JitDateMutation::invokeCreateFromInterface}
 */
final class DateTimeCreateFromInterface implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name;

    /** @var list<string> php-src ext/date/php_date.stub.php */
    public array $paramNames = ['object'];

    /** Static factory — no implicit $this. */
    public int $namedArgsReceiverPrefix = 0;

    public function __construct(
        private readonly bool $immutable = false,
    ) {
        $this->name = $immutable
            ? 'DateTimeImmutable::createFromInterface'
            : 'DateTime::createFromInterface';
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return JitDateMutation::invokeCreateFromInterface($context, $this->immutable, ...$args);
    }
}
