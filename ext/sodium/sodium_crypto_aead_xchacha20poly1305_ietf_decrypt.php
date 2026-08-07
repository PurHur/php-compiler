<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** sodium_crypto_aead_xchacha20poly1305_ietf_decrypt() — XChaCha20-Poly1305 AEAD open (php-src ext/sodium/libsodium.c; #15429, #27318). */
final class sodium_crypto_aead_xchacha20poly1305_ietf_decrypt extends SodiumAeadDecryptFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_aead_xchacha20poly1305_ietf_decrypt');
    }

    protected function invoke(string $ciphertext, string $additionalData, string $nonce, string $key): string|false
    {
        return VmSodium::aeadXchacha20poly1305IetfDecrypt($ciphertext, $additionalData, $nonce, $key);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, $this->getName(), 4)) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

            return $ptr;
        }
        $ciphertext = JitStringBuiltinArg::lower($context, $args[0], $this->getName(), 0, 'ciphertext');
        $additionalData = JitStringBuiltinArg::lower($context, $args[1], $this->getName(), 1, 'additional_data');
        $nonce = JitStringBuiltinArg::lower($context, $args[2], $this->getName(), 2, 'nonce');
        $key = JitStringBuiltinArg::lower($context, $args[3], $this->getName(), 3, 'key');

        return JitSodium::invokeAeadXchachaIetfDecrypt($context, $ciphertext, $additionalData, $nonce, $key);
    }
}
