<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\JitStringBuiltinArg;
use PHPCompiler\JIT\JitValueBox;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPLLVM\Value;

/** sodium_crypto_aead_xchacha20poly1305_ietf_encrypt() — XChaCha20-Poly1305 AEAD (php-src ext/sodium/libsodium.c; #15429, #27318). */
final class sodium_crypto_aead_xchacha20poly1305_ietf_encrypt extends SodiumAeadEncryptFunction
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_aead_xchacha20poly1305_ietf_encrypt');
    }

    protected function invoke(string $message, string $additionalData, string $nonce, string $key): string
    {
        return VmSodium::aeadXchacha20poly1305IetfEncrypt($message, $additionalData, $nonce, $key);
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        if (!$this->requireExactJitArgCount($context, $args, $this->getName(), 4)) {
            $slot = JitValueBox::alloc($context);
            $ptr = JitValueBox::pointer($context, $slot);
            JitValueBox::writeBool($context, $slot, $context->constantFromBool(false));

            return $ptr;
        }
        $message = JitStringBuiltinArg::lower($context, $args[0], $this->getName(), 0, 'message');
        $additionalData = JitStringBuiltinArg::lower($context, $args[1], $this->getName(), 1, 'additional_data');
        $nonce = JitStringBuiltinArg::lower($context, $args[2], $this->getName(), 2, 'nonce');
        $key = JitStringBuiltinArg::lower($context, $args[3], $this->getName(), 3, 'key');

        return JitSodium::invokeAeadXchachaIetfEncrypt($context, $message, $additionalData, $nonce, $key);
    }
}
