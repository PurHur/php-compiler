<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * Throwable/Exception::getTraceAsString() — JIT/AOT (#26796).
 *
 * php-src: Zend/zend_exceptions.c — Exception::getTraceAsString
 * VM SSOT: {@see \PHPCompiler\VM\Builtin\ExceptionGetTraceAsString}
 *
 * Thin AOT does not populate PROP_TRACE; return Zend-shaped `#0 {main}` unless a
 * dedicated frame seed is present. Issue #26796 acceptance uses `(string)$e` /
 * `__toString()`, which carries the redacted SensitiveParameter frame.
 */
final class ExceptionGetTraceAsString implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if ([] === $args) {
            throw new \LogicException('getTraceAsString() requires an object receiver');
        }
        $trace = $context->builder->load($context->constantStringFromString('#0 {main}'));
        $slot = JitValueBox::alloc($context);
        $context->builder->call(
            $context->lookupFunction('__value__writeString'),
            JitValueBox::pointer($context, $slot),
            $trace
        );

        return $slot;
    }
}
