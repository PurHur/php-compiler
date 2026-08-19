--TEST--
AOT: (bool) array — zend_hash_num_elements ? true : false (#32455, Zend/zend_operators.c)
--FILE--
<?php
var_dump((bool) []);
var_dump((bool) [1, 2]);
$empty = [];
$full = [9];
var_dump((bool) $empty);
var_dump((bool) $full);
?>
--EXPECT--
bool(false)
bool(true)
bool(false)
bool(true)
