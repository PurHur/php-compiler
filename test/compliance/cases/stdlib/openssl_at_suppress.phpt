--TEST--
openssl @-suppressed unknown cipher warnings record error_get_last (#14172)
--FILE--
<?php
@openssl_cipher_iv_length('nope');
$last = error_get_last();
echo str_contains($last['message'] ?? '', 'Unknown cipher algorithm') ? "last-ok\n" : "last-fail\n";
echo false === @openssl_cipher_iv_length('nope') ? "ret-false\n" : "ret-bad\n";
--EXPECT--
last-ok
ret-false
