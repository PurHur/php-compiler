<?php
// Repro #22335 — openssl_pkey_new EC/DH (php-src ext/openssl/openssl.c)
$key = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1',
]);
if ($key === false) {
    while ($e = openssl_error_string()) {
        echo "$e\n";
    }
    echo "FAIL\n";
    exit(1);
}
$d = openssl_pkey_get_details($key);
echo 'type=', $d['type'], ' bits=', $d['bits'], ' curve=', $d['ec']['curve_name'] ?? '?', "\n";

$p = hex2bin('FFFFFFFFFFFFFFFFC90FDAA22168C234C4C6628B80DC1CD129024E088A67CC74020BBEA63B139B22514A08798E3404DDEF9519B3CD3A431B302B0A6DF25F14374FE1356D6D51C245E485B576625E7EC6F44C42E9A637ED6B0BFF5CB6F406B7EDEE386BFB5A899FA5AE9F24117C4B1FE649286651ECE65381FFFFFFFFFFFFFFFF');
$g = hex2bin('02');
// Build options in a variable — VM nested-array+const call-arg quirk; Zend accepts either form.
$dhOpts = [
    'private_key_type' => OPENSSL_KEYTYPE_DH,
    'dh' => ['p' => $p, 'g' => $g],
];
$dh = openssl_pkey_new($dhOpts);
if ($dh === false) {
    echo "DH_FAIL\n";
    exit(1);
}
$dd = openssl_pkey_get_details($dh);
echo 'dh_type=', $dd['type'], ' dh_bits=', $dd['bits'], "\n";
