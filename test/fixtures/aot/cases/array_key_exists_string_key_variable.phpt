--TEST--
AOT: array_key_exists()/key_exists() string key on named array (#9456)
--FILE--
<?php
$a = ['k' => 1, '' => 2];
var_export(array_key_exists('k', $a));
echo "\n";
var_export(key_exists('k', $a));
echo "\n";
--EXPECT--
true
true
