<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\Frame;
use PHPCompiler\Func\Internal;
use PHPCompiler\JIT\Context;
use PHPCompiler\JIT\Variable as JITVariable;
use PHPCompiler\VM\BuiltinExecute;
use PHPCompiler\VM\Variable;
use PHPLLVM\Value;

/** sodium_crypto_aead_aes256gcm_is_available() — hardware AES-GCM probe (php-src ext/sodium/libsodium.c; #15542). */
final class sodium_crypto_aead_aes256gcm_is_available extends Internal
{
    public function __construct()
    {
        parent::__construct('sodium_crypto_aead_aes256gcm_is_available');
    }

    public function execute(Frame $frame): void
    {
        $this->requireExactArgCount($frame, $this->getName(), 0);
        $available = VmSodium::aeadAes256gcmIsAvailable();
        BuiltinExecute::writeReturn($frame, static function (Variable $ret) use ($available): void {
            $ret->bool($available);
        });
    }

    public function call(Context $context, JITVariable ...$args): Value
    {
        throw new \LogicException($this->getName().'() JIT is not supported in this compiler build');
    }
}
