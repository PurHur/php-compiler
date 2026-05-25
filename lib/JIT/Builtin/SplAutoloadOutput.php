<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** Register LLVM declarations for JIT/AOT spl_autoload_* runtime (issue #1776, #1492). */
final class SplAutoloadOutput
{
    public static function registerExternals(Context $context): void
    {
        $void = $context->context->voidType();
        $i32 = $context->getTypeFromString('int32');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');

        $decls = [
            '__phpc_spl_autoload_register_apply' => [$void, false, [$i8p, $i32]],
            '__phpc_spl_autoload_dispatch' => [$i32, false, [$i8p, $sizeT]],
        ];

        foreach ($decls as $name => [$ret, $vararg, $params]) {
            $ft = $context->context->functionType($ret, $vararg, ...$params);
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }
}
