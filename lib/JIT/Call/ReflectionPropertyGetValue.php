<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * ReflectionProperty::getValue($object) — AOT (#30910).
 *
 * php-src 8.1+ ignores accessible; reuse raw backing-store path (#27598 / #22091).
 */
final class ReflectionPropertyGetValue implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return (new ReflectionPropertyGetRawValue())->call($context, ...$args);
    }
}
