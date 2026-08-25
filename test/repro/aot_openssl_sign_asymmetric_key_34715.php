<?php
/**
 * #34715 — AOT openssl_sign/verify must accept OpenSSLAsymmetricKey (not only PEM strings).
 */
$k = openssl_pkey_new(['private_key_bits' => 512]);
$d = openssl_pkey_get_details($k);
$ok = openssl_sign('hi', $sig, $k);
$v = (is_string($sig) && isset($d['key'])) ? openssl_verify('hi', $sig, $d['key']) : -99;
echo ($ok && $v === 1 && is_string($sig) && strlen($sig) > 0) ? "ok\n" : "bad\n";
