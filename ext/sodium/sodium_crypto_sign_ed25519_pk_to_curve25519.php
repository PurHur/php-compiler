<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/**
 * sodium_crypto_sign_ed25519_pk_to_curve25519() — Ed25519 public → Curve25519 (php-src ext/sodium/libsodium.c; #20573).
 */
final class sodium_crypto_sign_ed25519_pk_to_curve25519 extends SodiumOneStringFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_sign_ed25519_pk_to_curve25519');
    }

    protected function argName(): string
    {
        return 'public_key';
    }

    protected function invoke(string $value): string
    {
        return VmSodium::signEd25519PkToCurve25519($value);
    }
}
