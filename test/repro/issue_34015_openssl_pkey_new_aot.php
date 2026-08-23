<?php
// Repro #34015 — openssl_pkey_new() happy-path OpenSSLAsymmetricKey under AOT.
$k = openssl_pkey_new();
echo get_class($k), PHP_EOL;
$k2 = openssl_pkey_new(null);
echo get_class($k2), PHP_EOL;
