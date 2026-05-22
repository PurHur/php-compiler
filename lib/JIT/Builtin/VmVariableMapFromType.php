<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\Variable as VMVariable;
use PHPLLVM\Value;

/**
 * Compile-time subset of {@see VMVariable::mapFromType()} for self-host JIT.
 */
final class VmVariableMapFromType implements Call
{
    public function call(Context $context, JITVariable ...$args): Value
    {
        return $context->constantFromInteger(VMVariable::TYPE_INTEGER);
    }
}
