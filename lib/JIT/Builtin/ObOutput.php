<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** Register LLVM declarations for JIT/AOT ob_*() runtime (issue #118, #1056). */
final class ObOutput
{
    public static function registerExternals(Context $context): void
    {
        $void = $context->context->voidType();
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $valuePtr = $context->getTypeFromString('__value__*');
        $doubleTy = $context->getTypeFromString('double');

        $decls = [
            '__phpc_ob_start' => [$void, false, []],
            '__phpc_ob_get_level' => [$i32, false, []],
            '__phpc_ob_get_clean' => [$i32, false, [$valuePtr]],
            '__phpc_ob_end_flush' => [$i32, false, [$valuePtr]],
            '__phpc_ob_echo_cstr' => [$void, false, [$i8p]],
            '__phpc_ob_echo_char' => [$void, false, [$i8]],
            '__phpc_ob_echo_ll' => [$void, false, [$i64]],
            '__phpc_ob_echo_double' => [$void, false, [$doubleTy]],
            '__phpc_ob_echo_substr' => [$void, false, [$i8p, $sizeT]],
            '__phpc_headers_sent' => [$i32, false, []],
            '__phpc_shutdown_mark_registered' => [$void, false, []],
        ];

        foreach ($decls as $name => [$ret, $vararg, $params]) {
            $ft = $context->context->functionType($ret, $vararg, ...$params);
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }
}
