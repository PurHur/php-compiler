--TEST--
AOT: var_dump(null) and var_dump(string) match Zend (#24220)
--FILE--
<?php
var_dump('hi');
var_dump(null);
$x = null;
var_dump($x);
--EXPECT--
string(2) "hi"
NULL
NULL
