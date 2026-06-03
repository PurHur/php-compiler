--TEST--
Language: isset()/empty() with dynamic brace key on array (issue #5117, zend_hash.c)
--FILE--
<?php
$a = ['x' => 1];
$k = 'x';
var_export(isset($a->{$k}));
echo "\n";
var_export(isset($a[$k]));
echo "\n";
var_export(empty($a->{$k}));
echo "\n";
$k = 'missing';
var_export(isset($a->{$k}));
echo "\n";
var_export(isset($a[$k]));
echo "\n";
--EXPECT--
false
true
true
false
false
