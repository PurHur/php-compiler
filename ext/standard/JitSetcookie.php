<?php

declare(strict_types=1);

namespace PHPCompiler\ext\standard;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\LibcExtern;
use PHPLLVM\Value;

/**
 * LLVM JIT/AOT helper for setcookie() — pending queue (AOT) or printf fallback (JIT).
 */
final class JitSetcookie
{
    public static function emitPending(
        Context $context,
        Value $namePtr,
        Value $valuePtr,
        Value $expiresI64,
        Value $pathPtr,
        Value $domainPtr,
        Value $secureI32,
        Value $httponlyI32,
        Value $samesitePtr,
        Value $partitionedI32
    ): void {
        $context->builder->call(
            $context->lookupFunction('__phpc_setcookie_add'),
            $namePtr,
            $valuePtr,
            $expiresI64,
            $pathPtr,
            $domainPtr,
            $secureI32,
            $httponlyI32,
            $samesitePtr,
            $partitionedI32
        );
    }

    public static function emitPrintf(
        Context $context,
        Value $namePtr,
        Value $valuePtr,
        ?Value $pathPtr = null
    ): void {
        // Module-local printf(3) after LibcExtern always-on drop (#31706).
        LibcExtern::ensurePrintf($context);
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
