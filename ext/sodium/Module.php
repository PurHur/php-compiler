<?php

declare(strict_types=1);

namespace PHPCompiler\ext\sodium;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * sodium extension module entry (php-src ext/sodium/sodium.c; issue #13078, #3438).
 *
 * Probe + secretbox surface; full ext/sodium matrix tracked in #3438.
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        if (!SodiumExtensionPolicy::advertisesExtension()) {
            return;
        }
        foreach ([
            'SODIUM_CRYPTO_SECRETBOX_KEYBYTES' => VmSodium::CRYPTO_SECRETBOX_KEYBYTES,
            'SODIUM_CRYPTO_SECRETBOX_NONCEBYTES' => VmSodium::CRYPTO_SECRETBOX_NONCEBYTES,
            'SODIUM_CRYPTO_SECRETBOX_MACBYTES' => VmSodium::CRYPTO_SECRETBOX_MACBYTES,
        ] as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        if (!SodiumExtensionPolicy::advertisesExtension()) {
            return [];
        }

        return [
            new sodium_crypto_secretbox(),
            new sodium_crypto_secretbox_open(),
        ];
    }
}
