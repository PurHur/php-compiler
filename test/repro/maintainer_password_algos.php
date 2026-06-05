<?php
// Zend parity: ext/standard/password.c password_algos() (#6195).
$algos = password_algos();
echo is_array($algos) ? "array\n" : "not_array\n";
echo array_is_list($algos) ? "list\n" : "assoc\n";
echo in_array('2y', $algos, true) ? "has_2y\n" : "no_2y\n";
echo count($algos) >= 1 ? "non_empty\n" : "empty\n";
