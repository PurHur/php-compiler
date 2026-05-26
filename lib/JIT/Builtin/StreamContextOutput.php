<?php

declare(strict_types=1);

namespace PHPCompiler\JIT\Builtin;

use PHPCompiler\JIT\Context;

/** Register LLVM declarations for JIT/AOT stream_context_create runtime (#1377, #2457). */
final class StreamContextOutput
{
    public static function registerExternals(Context $context): void
    {
        $htPtr = $context->getTypeFromString('__hashtable__*');

        $ft = $context->context->functionType($htPtr, false, $htPtr, $htPtr);
        $fn = $context->module->addFunction('__phpc_stream_context_create', $ft);
        $context->registerFunction('__phpc_stream_context_create', $fn);
    }
}
