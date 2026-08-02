<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\standard\JitDateMicrosecond;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * DateTime::getMicrosecond() / DateTimeImmutable::getMicrosecond() — JIT/AOT (#26938).
 *
 * SSOT: {@see JitDateMicrosecond}
 */
final class DateTimeGetMicrosecond implements Call
{
    public function __construct(
        private readonly bool $immutable = false,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return JitDateMicrosecond::invokeGet($context, $this->immutable, ...$args);
    }
}
