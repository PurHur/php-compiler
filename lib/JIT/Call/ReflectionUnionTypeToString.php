<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Call;

use PHPCompiler\JIT\Call;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable;
use PHPCompiler\VM\Builtin\VmClassMethod;
use PHPLLVM\Value;

/** ReflectionUnionType::__toString() — JIT/AOT (#28780). */
final class ReflectionUnionTypeToString implements Call
{
    public function call(Context $context, Variable ...$args): Value
    {
        if (!VmClassMethod::requireExactJitUserArgCount($context, $args, 'ReflectionUnionType::__toString()', 0)) {
            return VmClassMethod::jitArgcDummyReturn($context);
        }

        return ReflectionNamedTypeToString::typeString($context, $args[0], 'ReflectionUnionType');
    }
}
