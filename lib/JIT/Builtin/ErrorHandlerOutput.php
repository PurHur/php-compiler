<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** Register LLVM declarations for JIT/AOT error_handler_* runtime (issue #1379, #1492). */
final class ErrorHandlerOutput
{
    public static function registerExternals(Context $context): void
    {
        $void = $context->context->voidType();
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $valuePtr = $context->getTypeFromString('__value__*');

        $decls = [
            '__phpc_error_handler_set_apply' => [$void, false, [$valuePtr, $i8p, $sizeT, $i8p, $i32]],
            '__phpc_error_handler_restore_apply' => [$void, false, [$valuePtr]],
        ];

        foreach ($decls as $name => [$ret, $vararg, $params]) {
            $ft = $context->context->functionType($ret, $vararg, ...$params);
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }
}
