<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

/** sodium_crypto_kx_client_session_keys() — client rx/tx session keys (#20047). */
final class sodium_crypto_kx_client_session_keys extends SodiumKxSessionKeysFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_kx_client_session_keys');
    }

    protected function keypairParamName(): string
    {
        return 'client_key_pair';
    }

    protected function peerParamName(): string
    {
        return 'server_key';
    }

    protected function invoke(string $keypair, string $peerPublicKey): array
    {
        return VmSodium::kxClientSessionKeys($keypair, $peerPublicKey);
    }
}
