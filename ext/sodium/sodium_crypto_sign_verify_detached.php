<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_sign_verify_detached() — verify detached signature (php-src ext/sodium/libsodium.c; #15541). */
final class sodium_crypto_sign_verify_detached extends SodiumSignVerifyDetachedFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_sign_verify_detached');
    }

    protected function invoke(string $signature, string $message, string $publickey): bool
    {
        return VmSodium::signVerifyDetached($signature, $message, $publickey);
    }
}
