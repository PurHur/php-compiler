<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitDateMutation;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DateTime::modify() / DateTimeImmutable::modify() — JIT/AOT (#26789).
 *
 * php-src: ext/date/php_date.c — zim_DateTime_modify / zim_DateTimeImmutable_modify
 *
 * Mutable: in-place timestamp update via {@see JitDateMutation}.
 * Immutable: allocate+copy then update so the original stays unchanged under thin AOT
 * (ExternalMethod null stub previously segfaulted on the chained format()).
 */
final class DateTimeModify implements Call
{
    public function __construct(
        private readonly bool $immutable
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        $label = $this->immutable ? 'DateTimeImmutable::modify' : 'DateTime::modify';

        return JitDateMutation::invokeObjectModify($context, $this->immutable, $label, ...$args);
    }
}
