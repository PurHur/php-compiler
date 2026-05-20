<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPLLVM\Value;

/**
 * LLVM JIT helper for setcookie() — emits Set-Cookie to stdout (CGI-style).
 */
final class JitSetcookie
{
    public static function emit(
        Context $context,
        Value $namePtr,
        Value $valuePtr,
        ?Value $pathPtr = null
    ): void {
        $map = $context->structFieldMap['__string__'];
        $nameLen = $context->builder->load(
            $context->builder->structGep($namePtr, $map['length'])
        );
        $nameData = $context->builder->structGep($namePtr, $map['value']);
        $valueLen = $context->builder->load(
            $context->builder->structGep($valuePtr, $map['length'])
        );
        $valueData = $context->builder->structGep($valuePtr, $map['value']);

        if (null === $pathPtr) {
            $fmt = $context->builder->pointerCast(
                $context->constantFromString("Set-Cookie: %.*s=%.*s\r\n"),
                $context->getTypeFromString('char*')
            );
            $context->builder->call(
                $context->lookupFunction('printf'),
                $fmt,
                $nameLen,
                $nameData,
                $valueLen,
                $valueData
            );

            return;
        }

        $pathLen = $context->builder->load(
            $context->builder->structGep($pathPtr, $map['length'])
        );
        $pathData = $context->builder->structGep($pathPtr, $map['value']);
        $fmt = $context->builder->pointerCast(
            $context->constantFromString("Set-Cookie: %.*s=%.*s; path=%.*s\r\n"),
            $context->getTypeFromString('char*')
        );
        $context->builder->call(
            $context->lookupFunction('printf'),
            $fmt,
            $nameLen,
            $nameData,
            $valueLen,
            $valueData,
            $pathLen,
            $pathData
        );
    }
}
