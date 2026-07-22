--TEST--
Language: empty()/isset() nested missing dims are quiet (true/false) (#21991, Zend/zend_execute.c)
--FILE--
<?php
$a = [];
var_export(empty($a['x']['y']));
echo "\n";
var_export(isset($a['x']['y']));
echo "\n";
$b = ['x' => ['y' => 1]];
var_export(empty($b['x']['y']));
echo "\n";
var_export(isset($b['x']['y']));
echo "\n";
$c = ['x' => null];
var_export(empty($c['x']['y']));
echo "\n";
var_export(isset($c['x']['y']));
echo "\n";
$d = ['x' => ['y' => ['z' => 0]]];
var_export(empty($d['x']['y']['z']));
echo "\n";
var_export(empty($d['x']['missing']['z']));
echo "\n";
--EXPECT--
true
false
false
true
true
false
true
true
