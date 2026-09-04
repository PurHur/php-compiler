<?php

declare(strict_types=1);

namespace PHPCompiler\JIT;

/**
 * openssl extension surfaces needed by lib/JIT Builtin (#36204).
 *
 * Implemented in {@code ext/openssl/JitOpensslExtensionHooksFacade.php}; Builtin
 * OpensslEncrypt / OpensslSign runtimes must not import {@code ext\openssl}.
 */
interface OpensslExtensionHooks
{
    /** Host libcrypto FFI ready for cipher encrypt/decrypt. */
    public function cipherRuntimeAvailable(): bool;

    /** Host libcrypto FFI ready for sign/verify. */
    public function signRuntimeAvailable(): bool;

    /** NestedJIT EVP cipher leaves for openssl_encrypt/decrypt. */
    public function ensureCipherEvpLeaves(Context $context): void;

    /** NestedJIT EVP sign/verify leaves for openssl_sign/verify. */
    public function ensureSignEvpLeaves(Context $context): void;
}
