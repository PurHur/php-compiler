<?php
/**
 * re-#34800 — AOT base64_encode/decode runtime binary + openssl_decrypt base64.
 * NestedJIT small-shift packing (peer Bin2hex / JitOpensslCipherKernel).
 */
function enc($s)
{
    return base64_encode($s);
}
function dec($s)
{
    return base64_decode($s, true);
}

echo 'ascii=', var_export(enc('hello') === 'aGVsbG8=', true), "\n";
echo 'bin=', var_export(enc("\x80\x81\x82") === 'gIGC', true), "\n";
$literal = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
$png = dec($literal);
echo 'enc_match=', var_export(enc($png) === $literal, true), "\n";
$cipher = 'JrDjGHYK9CvRr0L0p1wckA==';
echo 'odec=', bin2hex((string) dec($cipher)), "\n";
$key = str_repeat('k', 16);
$iv = str_repeat('i', 16);
$p = openssl_decrypt($cipher, 'AES-128-CBC', $key, 0, $iv);
echo 'openssl=', var_export($p, true), "\n";
