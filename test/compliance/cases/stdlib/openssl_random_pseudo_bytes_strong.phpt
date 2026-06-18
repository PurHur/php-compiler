--TEST--
stdlib openssl_random_pseudo_bytes() — crypto_strong by-ref in concat/ternary (issue #9159)
--FILE--
<?php
$strong = false;
$b = openssl_random_pseudo_bytes(8, $strong);
echo strlen($b) . ',' . ($strong ? 'strong' : 'weak') . "\n";
--EXPECT--
8,strong
