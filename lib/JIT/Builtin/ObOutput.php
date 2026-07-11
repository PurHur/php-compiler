<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Builtin;
use PHPCompiler\JIT\Context;

/** Register LLVM declarations for JIT/AOT ob_*() runtime (issue #118, #1056). */
final class ObOutput
{
    public static function registerExternals(Context $context): void
    {
        ObStorageGlobals::ensureGlobals($context);

        $void = $context->context->voidType();
        $i8 = $context->getTypeFromString('int8');
        $i32 = $context->getTypeFromString('int32');
        $i64 = $context->getTypeFromString('int64');
        $i8p = $context->getTypeFromString('int8*');
        $sizeT = $context->getTypeFromString('size_t');
        $valuePtr = $context->getTypeFromString('__value__*');
        $strPtr = $context->getTypeFromString('__string__*');
        $doubleTy = $context->getTypeFromString('double');

        $decls = [
            '__phpc_ob_start' => [$void, false, []],
            '__phpc_ob_start_with_gzhandler' => [$void, false, []],
            '__compiler_ob_gzhandler' => [$strPtr, false, [$strPtr, $i64]],
            '__phpc_ob_gzhandler_flush' => [$strPtr, false, [$strPtr]],
            '__phpc_ob_get_level' => [$i32, false, []],
            '__phpc_ob_buffer_used_at' => [$i64, false, [$i64]],
            '__phpc_ob_get_clean' => [$i32, false, [$valuePtr]],
            '__phpc_ob_get_contents' => [$i32, false, [$valuePtr]],
            '__phpc_ob_get_length' => [$i32, false, [$valuePtr]],
            '__phpc_ob_end_clean' => [$i32, false, [$valuePtr]],
            '__phpc_ob_get_flush' => [$i32, false, [$valuePtr]],
            '__phpc_ob_end_flush' => [$i32, false, [$valuePtr]],
            '__phpc_ob_flush' => [$i32, false, [$valuePtr]],
            '__phpc_ob_clean' => [$i32, false, [$valuePtr]],
            '__phpc_flush' => [$void, false, []],
            '__phpc_ob_end_all' => [$void, false, []],
            '__phpc_ob_implicit_flush' => [$void, false, [$i32]],
            '__phpc_ob_echo_cstr' => [$void, false, [$i8p]],
            '__phpc_ob_echo_char' => [$void, false, [$i8]],
            '__phpc_ob_echo_ll' => [$void, false, [$i64]],
            '__phpc_ob_echo_double' => [$void, false, [$doubleTy]],
            '__phpc_ob_echo_substr' => [$void, false, [$i8p, $sizeT]],
            '__phpc_headers_sent' => [$i32, false, []],
            '__phpc_shutdown_mark_registered' => [$void, false, []],
        ];

        foreach ($decls as $name => [$ret, $vararg, $params]) {
            try {
                $linked = $context->lookupFunction($name);
                if ($linked->countBasicBlocks() > 0) {
                    continue;
                }
            } catch (\Throwable) {
            }
            $existing = $context->module->getNamedFunction($name);
            if (null !== $existing) {
                $context->registerFunction($name, $existing);

                continue;
            }
            $ft = $context->context->functionType($ret, $vararg, ...$params);
            $fn = $context->module->addFunction($name, $ft);
            $context->registerFunction($name, $fn);
        }
    }

    /** Emit php_output_end_all at standalone main return (issue #3675). */
    public static function emitEndAllForStandalone(Context $context): void
    {
        if (Builtin::LOAD_TYPE_STANDALONE !== $context->loadType) {
            return;
        }
        $userScriptAot = getenv('PHP_COMPILER_AOT_USER_SCRIPT');
        if ('1' === $userScriptAot || 'true' === strtolower((string) $userScriptAot)) {
            return;
        }
        $savedBlock = null;
        try {
            $savedBlock = $context->builder->getInsertBlock();
        } catch (\Throwable) {
        }
        ObOutputRuntime::ensureLinked($context);
        if (null !== $savedBlock) {
            $context->builder->positionAtEnd($savedBlock);
        }
        $context->builder->call($context->lookupFunction('__phpc_ob_end_all'));
    }
}
