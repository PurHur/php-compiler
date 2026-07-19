--TEST--
stdlib openssl_encrypt()/openssl_decrypt() AEAD tag/aad round-trip (#21135, ext/openssl/openssl.c)
--SKIPIF--
<?php
if (!extension_loaded('ffi')) {
    echo "skip ffi";
}
if (!function_exists('openssl_encrypt')) {
    echo "skip openssl_encrypt";
}
--FILE--
<?php
declare(strict_types=1);

$key = str_repeat('k', 32);
$iv = str_repeat('i', 12);
$tag = 'unset';
$ct = openssl_encrypt('secret', 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'aad', 16);
echo 'tag_len=', is_string($tag) ? strlen($tag) : -1, "\n";
$pt = openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'aad');
var_export($pt);
echo "\n";

$bad = @openssl_decrypt($ct, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag, 'wrong-aad');
var_export($bad);
echo "\n";

$rf = new ReflectionFunction('openssl_encrypt');
echo 'enc_params=', $rf->getNumberOfParameters(), "\n";
foreach ($rf->getParameters() as $p) {
    echo $p->getName(), $p->isPassedByReference() ? '&' : '', ' ';
}
echo "\n";

$rd = new ReflectionFunction('openssl_decrypt');
echo 'dec_params=', $rd->getNumberOfParameters(), "\n";

$tag2 = '';
$ct2 = openssl_encrypt('x', 'chacha20-poly1305', $key, OPENSSL_RAW_DATA, $iv, $tag2);
echo 'chacha_tag_len=', strlen($tag2), ' pt=', openssl_decrypt($ct2, 'chacha20-poly1305', $key, OPENSSL_RAW_DATA, $iv, $tag2), "\n";

// CBC still works; unused &$tag becomes null
$tag3 = 'keep';
$cbcKey = str_repeat('0', 16);
$cbcIv = str_repeat('1', 16);
$cbc = openssl_encrypt('data', 'aes-128-cbc', $cbcKey, OPENSSL_RAW_DATA, $cbcIv, $tag3);
echo 'cbc_ok=', is_string($cbc) ? 1 : 0, ' tag_null=', null === $tag3 ? 1 : 0, "\n";
echo openssl_decrypt($cbc, 'aes-128-cbc', $cbcKey, OPENSSL_RAW_DATA, $cbcIv), "\n";
--EXPECT--
tag_len=16
'secret'
false
enc_params=8
data cipher_algo passphrase options iv tag& aad tag_length 
dec_params=7
chacha_tag_len=16 pt=x
cbc_ok=1 tag_null=1
data
