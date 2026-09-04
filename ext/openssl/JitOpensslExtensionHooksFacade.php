<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\OpensslExtensionHooks;

/**
 * openssl surfaces for lib/JIT Builtin OpensslEncrypt / OpensslSign (#36204).
 *
 * php-src: ext/openssl/openssl.c — PHP_FUNCTION(openssl_encrypt/decrypt/sign/verify).
 * Registered from {@see Module::jitInit} so Builtin files do not import ext/openssl.
 */
final class JitOpensslExtensionHooksFacade implements OpensslExtensionHooks
{
    public function cipherRuntimeAvailable(): bool
    {
        return VmOpensslCipherNative::available();
    }

    public function signRuntimeAvailable(): bool
    {
        return VmOpensslSignNative::available();
    }

    public function ensureCipherEvpLeaves(Context $context): void
    {
        JitOpensslCipherKernel::ensureEvpLeaves($context);
    }

    public function ensureSignEvpLeaves(Context $context): void
    {
        JitOpensslSignKernel::ensureEvpLeaves($context);
    }
}
