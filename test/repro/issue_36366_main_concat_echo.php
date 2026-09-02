<?php
// #36366 — {main} `$b = $runtimeString . "lit"` must echo/var_export the concat result.
// php-src: Zend/zend_operators.c zend_concat / zend_binary_assign_op_helper
$n = 1;
$a = str_repeat('Z', $n);
$b = $a . 'Y';
echo 'echo=[', $b, "]\n";
var_export($b);
echo "\n";
$fmt = '%s';
$c = sprintf($fmt, 'hi');
$d = $c . '!';
echo 'sprintf_concat=[', $d, "] len=", strlen($d), "\n";
