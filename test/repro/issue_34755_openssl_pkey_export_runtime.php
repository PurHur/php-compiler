<?php
$key = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$out = '';
$ok = openssl_pkey_export($key, $out);
echo ($ok ? 'true' : 'false'), "\n";
echo (is_string($out) && str_contains($out, 'BEGIN') ? 'pem_ok' : 'pem_bad'), "\n";
echo strlen($out), "\n";
