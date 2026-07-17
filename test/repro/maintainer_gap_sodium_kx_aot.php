<?php
declare(strict_types=1);

/**
 * AOT repro for #20047 — sodium_crypto_kx_* registration + constants.
 * Crypto ops (keypair/session) are VM-first like sodium_crypto_box_keypair (#15515).
 */
echo 'kx_keypair=', function_exists('sodium_crypto_kx_keypair') ? '1' : '0', "\n";
echo 'kx_publickey=', function_exists('sodium_crypto_kx_publickey') ? '1' : '0', "\n";
echo 'kx_secretkey=', function_exists('sodium_crypto_kx_secretkey') ? '1' : '0', "\n";
echo 'kx_seed=', function_exists('sodium_crypto_kx_seed_keypair') ? '1' : '0', "\n";
echo 'kx_client=', function_exists('sodium_crypto_kx_client_session_keys') ? '1' : '0', "\n";
echo 'kx_server=', function_exists('sodium_crypto_kx_server_session_keys') ? '1' : '0', "\n";
echo 'KEYPAIRBYTES=', SODIUM_CRYPTO_KX_KEYPAIRBYTES, "\n";
echo 'SESSIONKEYBYTES=', SODIUM_CRYPTO_KX_SESSIONKEYBYTES, "\n";
echo 'SEEDBYTES=', SODIUM_CRYPTO_KX_SEEDBYTES, "\n";
