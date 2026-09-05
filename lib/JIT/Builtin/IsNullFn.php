<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\TypesVmRuntimeSupport;
use PHPLLVM\Value;

/**
 * is_null() JIT proxy — delegates to ext/types via {@see TypesVmRuntimeSupport} (#36204).
 *
 * php-src: ext/standard/type.c — PHP_FUNCTION(is_null).
 */
final class IsNullFn implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        return TypesVmRuntimeSupport::callIsNull($context, ...$args);
    }
}
