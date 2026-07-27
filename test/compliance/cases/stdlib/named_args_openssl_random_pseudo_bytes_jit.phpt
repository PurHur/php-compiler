--TEST--
openssl_random_pseudo_bytes named length/strong_result arguments (JIT, issue #23626)
--FILE--
<?php
$strong = false;
$bytes = openssl_random_pseudo_bytes(length: 4, strong_result: $strong);
echo strlen($bytes), ' ', (int)$strong, PHP_EOL;
--EXPECT--
4 1
