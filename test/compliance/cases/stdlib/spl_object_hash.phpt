--TEST--
stdlib spl_object_hash() — 32-char hex handle string (#3172)
--FILE--
<?php
class A {}
$o = new A();
$hash = spl_object_hash($o);
echo (function_exists('spl_object_hash')) ? "exists\n" : "missing\n";
echo (32 === strlen($hash)) ? "len32\n" : "badlen\n";
echo (str_ends_with($hash, '0000000000000000')) ? "suffix\n" : "badsuffix\n";
echo ($hash === spl_object_hash($o)) ? "stable\n" : "changed\n";
--EXPECT--
exists
len32
suffix
stable
