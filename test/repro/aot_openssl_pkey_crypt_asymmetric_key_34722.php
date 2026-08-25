<?php
/**
 * #34722 — AOT openssl_*_encrypt/decrypt accept OpenSSLAsymmetricKey (peer #34715).
 */
$k = openssl_pkey_new(['private_key_bits' => 512]);
$d = openssl_pkey_get_details($k);
$pubPem = $d['key'];
$msg = 'hello';

$ok = openssl_private_encrypt($msg, $ciph, $k);
$ok2 = $ok && openssl_public_decrypt($ciph, $plain, $pubPem);
echo 'priv_enc:', ($ok2 && $plain === $msg) ? 'ok' : 'bad', "\n";

$ok = openssl_public_encrypt($msg, $ciph2, $pubPem);
$ok2 = $ok && openssl_private_decrypt($ciph2, $plain2, $k);
echo 'pub_enc:', ($ok2 && $plain2 === $msg) ? 'ok' : 'bad', "\n";
