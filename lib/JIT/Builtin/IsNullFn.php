<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\ext\types\is_type;
use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM\Value;

final class IsNullFn implements Call
{
    private is_type $delegate;

    public function __construct()
    {
        $this->delegate = new is_type('is_null', VMVariable::TYPE_NULL);
    }

    public function call(Context $context, Variable ...$args): Value
    {
        return $this->delegate->call($context, ...$args);
    }
}
