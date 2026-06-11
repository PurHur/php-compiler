--TEST--
stdlib openssl_random_pseudo_bytes() JIT path (#4994)
--FILE--
<?php
$strong = false;
$bytes = openssl_random_pseudo_bytes(16, $strong);
echo strlen($bytes), "\n";
var_export($strong);
echo "\n";
--EXPECT--
16
true
