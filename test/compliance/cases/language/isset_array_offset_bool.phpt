--TEST--
Language: isset()/empty() on array offset as call arg returns bool (#11498, Zend/zend_operators.c)
--FILE--
<?php
$a = ['k' => 1, 'null' => null];
echo var_export(isset($a['k']), true), "\n";
echo var_export(isset($a['missing']), true), "\n";
echo var_export(empty($a['k']), true), "\n";
echo var_export(empty($a['null']), true), "\n";
$b = ['nested' => ['x' => 1]];
echo var_export(isset($b['nested']['x']), true), "\n";
echo var_export(isset($b['nested']['missing']), true), "\n";
--EXPECT--
true
false
false
true
true
false
