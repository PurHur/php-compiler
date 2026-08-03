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
        // Use registeredConstants() so PKCS1/OAEP/NO padding + identity trio + TLSEXT reach defineConstant (#24071, #24070, #24084).
        foreach (OpensslConstants::registeredConstants() as $name => $value) {
            $var = new VM\Variable();
            if (\is_string($value)) {
                $var->string($value);
            } else {
                $var->int((int) $value);
            }
            $runtime->vmContext->defineConstant($name, $var);
        }
    }

    public function getFunctions(): array
    {
        return [
            new openssl_encrypt(),
            new openssl_decrypt(),
            new phpc_openssl_cipher_encrypt(),
            new phpc_openssl_cipher_decrypt(),
            new openssl_sign(),
            new openssl_verify(),
            new openssl_public_encrypt(),
            new openssl_private_decrypt(),
            new openssl_private_encrypt(),
            new openssl_public_decrypt(),
            new openssl_get_cipher_methods(),
            new openssl_get_md_methods(),
            new phpc_openssl_cipher_methods_kernel(),
            new phpc_openssl_md_methods_kernel(),
            new openssl_get_cert_locations(),
            new openssl_get_curve_names(),
            new openssl_pkey_new(),
            new openssl_pkey_get_private(),
            new openssl_get_privatekey(),
            new openssl_pkey_get_public(),
            new openssl_get_publickey(),
            new openssl_pkey_get_details(),
            new openssl_pkey_export(),
            new openssl_pkey_export_to_file(),
            new openssl_pkey_derive(),
            new openssl_dh_compute_key(),
            new openssl_cipher_iv_length(),
            new openssl_cipher_key_length(),
            new openssl_digest(),
            new openssl_pbkdf2(),
            new openssl_x509_read(),
            new openssl_x509_parse(),
            new openssl_x509_fingerprint(),
            new openssl_x509_export(),
            new openssl_x509_export_to_file(),
            new openssl_pkcs12_read(),
            new openssl_pkcs12_export(),
            new openssl_pkcs12_export_to_file(),
            new openssl_pkcs7_sign(),
            new openssl_pkcs7_verify(),
            new openssl_pkcs7_encrypt(),
            new openssl_pkcs7_decrypt(),
            new openssl_pkcs7_read(),
            new openssl_cms_sign(),
            new openssl_cms_verify(),
            new openssl_cms_encrypt(),
            new openssl_cms_decrypt(),
            new openssl_cms_read(),
            new openssl_x509_verify(),
            new openssl_x509_check_private_key(),
            new openssl_x509_checkpurpose(),
            new openssl_x509_free(),
            new openssl_csr_new(),
            new openssl_csr_export(),
            new openssl_csr_export_to_file(),
            new openssl_csr_sign(),
            new openssl_csr_get_subject(),
            new openssl_csr_get_public_key(),
            new openssl_free_key(),
            new openssl_pkey_free(),
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
