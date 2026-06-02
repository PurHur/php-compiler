<?php
// Zend: duplicate array literal keys — last value wins (zend_compile.c / zend_hash).
$a = ['a' => 1, 'a' => 2];
echo $a['a'], "\n";
$b = [0 => 'first', 0 => 'last'];
echo $b[0], "\n";
