--TEST--
array_key_exists() with nested subscript call argument (#10401, Zend/zend_execute.c)
--FILE--
<?php
$bt = [['file' => 'x', 'line' => 1]];
var_dump(array_key_exists('file', $bt[0]));
var_dump(array_key_exists('missing', $bt[0]));
$frame = $bt[0];
var_dump(array_key_exists('file', $frame));
--EXPECT--
bool(true)
bool(false)
bool(true)
