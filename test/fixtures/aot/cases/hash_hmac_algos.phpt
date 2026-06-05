--TEST--
AOT hash_hmac_algos() — sha256 algorithm listed (#6229)
--FILE--
<?php
$algos = hash_hmac_algos();
echo is_array($algos) ? "array\n" : "not_array\n";
echo array_is_list($algos) ? "list\n" : "assoc\n";
echo in_array('sha256', $algos, true) ? "has_sha256\n" : "no_sha256\n";
echo count($algos) === 3 ? "three_algos\n" : "wrong_count\n";
--EXPECT--
array
list
has_sha256
three_algos
