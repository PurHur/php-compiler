--TEST--
AOT password_algos() — bcrypt algorithm listed (#6195)
--FILE--
<?php
$algos = password_algos();
echo is_array($algos) ? "array\n" : "not_array\n";
echo array_is_list($algos) ? "list\n" : "assoc\n";
echo in_array('2y', $algos, true) ? "has_2y\n" : "no_2y\n";
echo count($algos) === 1 ? "one_algo\n" : "many_algos\n";
--EXPECT--
array
list
has_2y
one_algo
