<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\ext\bcmath\JitBcMathNumberMethods;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * BcMath\Number::{add,mul,compare} — JIT/AOT (#26803).
 *
 * php-src: ext/bcmath/bcmath.c — PHP_METHOD(BcMath_Number, …)
 * VM SSOT: {@see \PHPCompiler\ext\bcmath\NumberAdd} et al.
 */
final class BcMathNumberMethod implements Call
{
    /** @var array{value: string, scale: int}|null */
    public ?array $lastCompileTimeBcmathNumber = null;

    public function __construct(
        private readonly string $method,
    ) {
    }

    public function call(Context $context, Variable ...$args): Value
    {
        $this->lastCompileTimeBcmathNumber = null;
        $result = JitBcMathNumberMethods::call($context, $this->method, ...$args);
        $this->lastCompileTimeBcmathNumber = JitBcMathNumberMethods::takeLastCompileTimeResult();

        return $result;
    }
}
