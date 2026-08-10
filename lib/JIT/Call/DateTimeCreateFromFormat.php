<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitDateCreateFromFormat;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DateTime::createFromFormat() / DateTimeImmutable::createFromFormat() — JIT/AOT (#26788).
 *
 * SSOT: {@see JitDateCreateFromFormat} (same as date_create_from_format()).
 */
final class DateTimeCreateFromFormat implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name;

    /** @var list<string> php-src ext/date/php_date.stub.php */
    public array $paramNames = ['format', 'datetime', 'timezone='];

    /** Static factory — no implicit $this. */
    public int $namedArgsReceiverPrefix = 0;

    public function __construct(
        private readonly bool $immutable = false,
    ) {
        $this->name = $immutable ? 'DateTimeImmutable::createFromFormat' : 'DateTime::createFromFormat';
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return JitDateCreateFromFormat::invokeNamed(
            $context,
            $this->immutable,
            $this->name,
            ...$args
        );
    }
}
