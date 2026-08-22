<?php
// Direct call-site literals — foreach/array loads are TYPE_VALUE at runtime (#32852 residual).
$pem = "-----BEGIN PUBLIC KEY-----\nMFkwEwYHKoZIzj0CAQYIKoZIzj0DAQcDQgAE\n-----END PUBLIC KEY-----\n";

echo 'null=', var_export(@openssl_pkey_derive(null, $pem), true), "\n";
echo 'ff=', var_export(@openssl_pkey_derive(false, false), true), "\n";
echo 'tt=', var_export(@openssl_pkey_derive(true, true), true), "\n";
echo 'ii=', var_export(@openssl_pkey_derive(0, 0), true), "\n";
echo 'fl=', var_export(@openssl_pkey_derive(1.5, 1.5), true), "\n";
echo 'done', "\n";
