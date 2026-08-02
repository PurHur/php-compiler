<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitDateCreateFromTimestamp;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DateTime::createFromTimestamp() / DateTimeImmutable::createFromTimestamp() — JIT/AOT (#26936).
 *
 * SSOT: {@see JitDateCreateFromTimestamp} (php-src ext/date/php_date.c).
 */
final class DateTimeCreateFromTimestamp implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name;

    /** @var list<string> php-src ext/date/php_date.stub.php */
    public array $paramNames = ['timestamp'];

    /** Static factory — no implicit $this. */
    public int $namedArgsReceiverPrefix = 0;

    public function __construct(
        private readonly bool $immutable = false,
    ) {
        $this->name = $immutable
            ? 'DateTimeImmutable::createFromTimestamp'
            : 'DateTime::createFromTimestamp';
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return JitDateCreateFromTimestamp::invoke($context, $this->immutable, ...$args);
    }
}
