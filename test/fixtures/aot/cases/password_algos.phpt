--TEST--
AOT password_algos() — bcrypt + argon2 names (#6195, #27658)
--FILE--
<?php
$algos = password_algos();
echo is_array($algos) ? "array\n" : "not_array\n";
echo array_is_list($algos) ? "list\n" : "assoc\n";
echo in_array('2y', $algos, true) ? "has_2y\n" : "no_2y\n";
echo in_array('argon2i', $algos, true) ? "has_argon2i\n" : "no_argon2i\n";
echo in_array('argon2id', $algos, true) ? "has_argon2id\n" : "no_argon2id\n";
echo implode(',', $algos), "\n";
--EXPECT--
array
list
has_2y
has_argon2i
has_argon2id
2y,argon2i,argon2id
