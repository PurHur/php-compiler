<?php

declare(strict_types=1);

namespace PHPCompiler\ext\openssl;

use PHPCompiler\ModuleAbstract;
use PHPCompiler\Runtime;
use PHPCompiler\VM;

/**
 * openssl extension module entry (php-src ext/openssl/openssl.c; issue #7000, #11859).
 *
 * Crypto algorithms land in #3324; PKCS#7 in #6804; key APIs in #6295.
 * Logical {@code openssl} extension is withheld until {@see OpensslExtensionPolicy}.
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
            new openssl_verify(),
            new openssl_get_cipher_methods(),
            new openssl_get_md_methods(),
            new openssl_get_cert_locations(),
            new openssl_get_curve_names(),
            new openssl_pkey_new(),
            new openssl_pkey_get_private(),
            new openssl_pkey_export(),
            new openssl_pkey_derive(),
            new openssl_cipher_iv_length(),
            new openssl_cipher_key_length(),
            new openssl_digest(),
            new openssl_pbkdf2(),
            new openssl_x509_read(),
            new openssl_x509_parse(),
            new openssl_x509_fingerprint(),
            new openssl_pkcs12_read(),
            new openssl_pkcs12_export(),
            new openssl_pkcs12_export_to_file(),
            new openssl_free_key(),
            new openssl_spki_new(),
            new openssl_spki_verify(),
            new openssl_spki_export(),
            new openssl_spki_export_challenge(),
            new openssl_seal(),
            new openssl_open(),
            new openssl_error_string(),
        ];
    }
}
