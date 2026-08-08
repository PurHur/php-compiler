--TEST--
stdlib ArrayObject/ArrayIterator missing dim — Undefined array key warning (#28820, ext/spl/spl_array.c)
--FILE--
<?php
error_reporting(E_ALL);
$a = new ArrayObject([1]);
var_dump(isset($a[5]));
var_dump($a[5]);
$i = new ArrayIterator([1]);
var_dump($i[5]);
$s = new ArrayObject(['x' => 1]);
var_dump($s['missing']);
--EXPECTF--
bool(false)
%AWarning: Undefined array key 5 in %s on line %d
NULL
%AWarning: Undefined array key 5 in %s on line %d
NULL
%AWarning: Undefined array key "missing" in %s on line %d
NULL
