<?php
/**
 * #35051 — AOT base64_encode/decode NestedJIT must match Zend on runtime binary
 * (multiply/intdiv packing and isset length walks corrupt high bytes).
 */
$bin = "\x80\x81\x82";
$enc = base64_encode($bin);
echo 'enc=', $enc, "\n";
echo 'enc_ok=', var_export($enc === 'gIGC', true), "\n";
$dec = base64_decode($enc);
echo 'dec_ok=', var_export($dec === $bin, true), "\n";

$literal = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';
$png = base64_decode($literal);
$reenc = base64_encode($png);
echo 'png_enc_match=', var_export($reenc === $literal, true), "\n";

$key = '0123456789abcdef';
$iv = 'fedcba9876543210';
// Ciphertext from Zend openssl_encrypt('hi', AES-128-CBC, …) — non-RAW uses base64.
$ct = 'eDWsfWGiZ3hEH0elhoepiw==';
$pt = openssl_decrypt($ct, 'AES-128-CBC', $key, 0, $iv);
echo 'openssl_b64=', var_export($pt, true), "\n";
$ptRaw = openssl_decrypt(base64_decode($ct), 'AES-128-CBC', $key, OPENSSL_RAW_DATA, $iv);
echo 'openssl_raw=', var_export($ptRaw, true), "\n";
