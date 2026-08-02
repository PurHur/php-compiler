<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitDateMicrosecond;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DateTime::setMicrosecond() / DateTimeImmutable::setMicrosecond() — JIT/AOT (#26938).
 *
 * SSOT: {@see JitDateMicrosecond}
 * php-src stub: setMicrosecond(int $microsecond): static
 */
final class DateTimeSetMicrosecond implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name;

    /** @var list<string> php-src ext/date/php_date.stub.php */
    public array $paramNames = ['microsecond'];

    public function __construct(
        private readonly bool $immutable = false,
    ) {
        $this->name = $immutable
            ? 'DateTimeImmutable::setMicrosecond'
            : 'DateTime::setMicrosecond';
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return JitDateMicrosecond::invokeSet($context, $this->immutable, ...$args);
    }
}
