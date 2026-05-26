<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

use PHPLLVM\Value;

/**
 * Allocate Runtime in M3 emit-helper TU without PHP CFG `new Runtime` (#2540, #2442).
 */
final class RuntimeEmitTuAlloc
{
    public static function emit(Context $context): Value
    {
        $runtimeId = $context->type->object->lookup('PHPCompiler\\Runtime');

        return $context->type->object->allocateEmitTuShell($runtimeId);
    }
}
