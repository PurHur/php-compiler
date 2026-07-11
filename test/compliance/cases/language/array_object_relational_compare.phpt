--TEST--
Language: array/object/null relational compare — Zend zend_compare parity (#12033)
--FILE--
<?php
$o = new stdClass();

var_dump([] < $o);
var_dump([] > $o);
var_dump(null < []);
var_dump([] == $o);
var_dump([] <=> $o);
var_dump(null <=> []);
?>
--EXPECT--
bool(true)
bool(false)
bool(false)
bool(false)
int(-1)
int(0)
