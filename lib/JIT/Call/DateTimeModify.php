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

/**
 * DateTime::add() / DateTimeImmutable::add() — JIT/AOT (#30760).
 *
 * Defined here because DateTimeModify is always registered (all profiles).
 * SSOT: {@see \PHPCompiler\ext\standard\JitDateMutation::invokeObjectAdd}
 *
 * php-src: ext/date/php_date.c — zim_DateTime_add / zim_DateTimeImmutable_add
 */
final class DateTimeAdd implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name;

    /** @var list<string> php-src ext/date/php_date.stub.php */
    public array $paramNames = ['interval'];

    public function __construct(
        private readonly bool $immutable = false,
    ) {
        $this->name = $immutable ? 'DateTimeImmutable::add' : 'DateTime::add';
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return JitDateMutation::invokeObjectAdd($context, $this->immutable, ...$args);
    }
}

/**
 * DateTime::sub() / DateTimeImmutable::sub() — JIT/AOT (#30760).
 *
 * SSOT: {@see \PHPCompiler\ext\standard\JitDateMutation::invokeObjectSub}
 * php-src: ext/date/php_date.c — zim_DateTime_sub / zim_DateTimeImmutable_sub
 */
final class DateTimeSub implements Call
{
    /** Qualified name for BuiltinParamNames / named-arg resolve. */
    public string $name;

    /** @var list<string> php-src ext/date/php_date.stub.php */
    public array $paramNames = ['interval'];

    public function __construct(
        private readonly bool $immutable = false,
    ) {
        $this->name = $immutable ? 'DateTimeImmutable::sub' : 'DateTime::sub';
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return JitDateMutation::invokeObjectSub($context, $this->immutable, ...$args);
    }
}
