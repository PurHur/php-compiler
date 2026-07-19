--TEST--
AOT: openssl_get_cipher_methods() / openssl_get_md_methods() (#21103)
--FILE--
<?php
declare(strict_types=1);

// Avoid foreach over both registries in one AOT binary (free(): invalid pointer under
// HELPER_RUNTIME_O=0; JIT compliance covers in_array for both).
echo count(openssl_get_cipher_methods()) >= 100 ? "ciphers_ok\n" : "ciphers_bad\n";
$hasSha = false;
foreach (openssl_get_md_methods() as $name) {
    if ((string) $name === 'sha256') {
        $hasSha = true;
        break;
    }
}
echo $hasSha ? "has_sha\n" : "no_sha\n";
--EXPECT--
ciphers_ok
has_sha
