--TEST--
openssl_csr_* enum operands TypeError (#6421, php-src-strict)
--FILE--
<?php
enum E: string { case A = 'x'; }
$key = openssl_pkey_new(['private_key_bits' => 512]);
$csr = openssl_csr_new(['commonName' => 'e.example'], $key, ['digest_alg' => 'sha256']);
$n = 0;
try {
    openssl_csr_export(E::A, $out);
} catch (TypeError $e) {
    $n++;
}
try {
    openssl_csr_get_subject(E::A);
} catch (TypeError $e) {
    $n++;
}
try {
    openssl_csr_get_public_key(E::A);
} catch (TypeError $e) {
    $n++;
}
try {
    openssl_csr_sign(E::A, null, $key, 1);
} catch (TypeError $e) {
    $n++;
}
try {
    openssl_csr_new(E::A, $key);
} catch (TypeError $e) {
    $n++;
}
echo $n === 5 && ($csr instanceof OpenSSLCertificateSigningRequest) ? "ok\n" : "fail:$n\n";
?>
--EXPECT--
ok
