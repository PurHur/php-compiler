--TEST--
stdlib hash_hmac_algos() — HMAC-capable digest algorithms (#6229, #6365)
--FILE--
<?php
$algos = hash_hmac_algos();
echo is_array($algos) ? "array\n" : "not_array\n";
echo array_is_list($algos) ? "list\n" : "assoc\n";
echo in_array('sha256', $algos, true) ? "has_sha256\n" : "no_sha256\n";
echo in_array('sha512', $algos, true) ? "has_sha512\n" : "no_sha512\n";
echo in_array('md5', $algos, true) ? "has_md5\n" : "no_md5\n";
echo in_array('sha1', $algos, true) ? "has_sha1\n" : "no_sha1\n";
echo count($algos) === 44 ? "forty_four_algos\n" : "wrong_count\n";
--EXPECT--
array
list
has_sha256
has_sha512
has_md5
has_sha1
forty_four_algos
