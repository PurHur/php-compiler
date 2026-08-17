<?php

declare(strict_types=1);

/**
 * LLVM JIT helper for header() — writes a response header line to stdout (CGI-style).
 */

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

final class JitHeader
{
    public static function emit(Context $context, Value $strPtr): void
    {
        // Module-local printf(3) after LibcExtern always-on drop (#31706).
        LibcExtern::ensurePrintf($context);
        $map = $context->structFieldMap['__string__'];
        $length = $context->builder->load(
            $context->builder->structGep($strPtr, $map['length'])
        );
        $data = $context->builder->structGep($strPtr, $map['value']);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString("%.*s\r\n"),
            $context->getTypeFromString('char*')
        );
        $context->builder->call(
            $context->lookupFunction('printf'),
            $fmt,
            $length,
            $data
        );
    }
}
