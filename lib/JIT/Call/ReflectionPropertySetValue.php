<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPLLVM\Value;

/**
 * ReflectionProperty::setValue($object, $value) — AOT (#30910).
 *
 * php-src 8.1+ ignores accessible; reuse raw backing-store path (#27598 / #22091).
 */
final class ReflectionPropertySetValue implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        // setRawValue($object, $value) — same arity as setValue for instance props.
        return (new ReflectionPropertySetRawValue())->call($context, ...$args);
    }
}
