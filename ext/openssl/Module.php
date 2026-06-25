<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * openssl extension module entry (php-src ext/openssl/openssl.c; issue #7000).
 *
 * Crypto algorithms land in #3324; PKCS#7 in #6804; key APIs in #6295.
 */
class Module extends ModuleAbstract
{
    public function init(Runtime $runtime): void
    {
        parent::init($runtime);
        BuiltinClasses::register($runtime->vmContext);
        foreach (array_merge([
            'OPENSSL_RAW_DATA' => OpensslConstants::OPENSSL_RAW_DATA,
            'OPENSSL_ZERO_PADDING' => OpensslConstants::OPENSSL_ZERO_PADDING,
        ], OpensslConstants::algorithmConstants()) as $name => $value) {
            $var = new VM\Variable();
            $var->int($value);
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        return [
            new openssl_encrypt(),
            new openssl_decrypt(),
            new openssl_sign(),
            new openssl_get_cipher_methods(),
            new openssl_get_md_methods(),
            new openssl_pkey_new(),
            new openssl_cipher_iv_length(),
            new openssl_cipher_key_length(),
            new openssl_digest(),
            new openssl_x509_read(),
            new openssl_free_key(),
        ];
    }
}
