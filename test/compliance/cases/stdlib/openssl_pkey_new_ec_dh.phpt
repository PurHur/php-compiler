--TEST--
openssl_pkey_new EC/DH keygen + get_details (#22335, ext/openssl/openssl.c)
--FILE--
<?php
if (!function_exists('openssl_pkey_new') || !defined('OPENSSL_KEYTYPE_EC')) {
    echo "missing\n";
    exit(1);
}

$ec = openssl_pkey_new([
    'private_key_type' => OPENSSL_KEYTYPE_EC,
    'curve_name' => 'prime256v1',
]);
if (false === $ec) {
    echo "ec-fail\n";
    exit(1);
}
$ed = openssl_pkey_get_details($ec);
if (!is_array($ed)
    || ($ed['type'] ?? null) !== OPENSSL_KEYTYPE_EC
    || ($ed['bits'] ?? null) !== 256
    || !isset($ed['ec'])
    || !is_array($ed['ec'])
    || ($ed['ec']['curve_name'] ?? '') !== 'prime256v1'
) {
    echo "ec-details-fail\n";
    exit(1);
}
echo "ec-ok\n";

$p = hex2bin('FFFFFFFFFFFFFFFFC90FDAA22168C234C4C6628B80DC1CD129024E088A67CC74020BBEA63B139B22514A08798E3404DDEF9519B3CD3A431B302B0A6DF25F14374FE1356D6D51C245E485B576625E7EC6F44C42E9A637ED6B0BFF5CB6F406B7EDEE386BFB5A899FA5AE9F24117C4B1FE649286651ECE65381FFFFFFFFFFFFFFFF');
$g = hex2bin('02');
$dhOpts = [
    'private_key_type' => OPENSSL_KEYTYPE_DH,
    'dh' => ['p' => $p, 'g' => $g],
];
$dhKey = openssl_pkey_new($dhOpts);
if (false === $dhKey) {
    echo "dh-fail\n";
    exit(1);
}
$dd = openssl_pkey_get_details($dhKey);
if (!is_array($dd)
    || ($dd['type'] ?? null) !== OPENSSL_KEYTYPE_DH
    || ($dd['bits'] ?? null) !== 1024
    || !isset($dd['dh'])
    || !is_array($dd['dh'])
) {
    echo "dh-details-fail\n";
    exit(1);
}
echo "dh-ok\n";

$rsa = openssl_pkey_new(['private_key_bits' => 512, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
echo false !== $rsa && (openssl_pkey_get_details($rsa)['type'] ?? null) === OPENSSL_KEYTYPE_RSA ? "rsa-ok\n" : "rsa-fail\n";
?>
--EXPECT--
ec-ok
dh-ok
rsa-ok
