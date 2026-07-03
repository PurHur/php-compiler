<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_sign_open() — verify attached signature (php-src ext/sodium/libsodium.c; #15541). */
final class sodium_crypto_sign_open extends SodiumSignOpenFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_sign_open');
    }

    protected function invoke(string $signedMessage, string $publickey): string|false
    {
        return VmSodium::signOpen($signedMessage, $publickey);
    }
}
