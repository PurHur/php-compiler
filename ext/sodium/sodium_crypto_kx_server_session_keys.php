<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_kx_server_session_keys() — server rx/tx session keys (#20047). */
final class sodium_crypto_kx_server_session_keys extends SodiumKxSessionKeysFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_kx_server_session_keys');
    }

    protected function keypairParamName(): string
    {
        return 'server_key_pair';
    }

    protected function peerParamName(): string
    {
        return 'client_key';
    }

    protected function invoke(string $keypair, string $peerPublicKey): array
    {
        return VmSodium::kxServerSessionKeys($keypair, $peerPublicKey);
    }
}
