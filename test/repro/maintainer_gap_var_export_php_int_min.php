<?php
/**
 * var_export(PHP_INT_MIN) must emit Zend's eval-safe form (#23690, ext/standard/var.c).
 */
$s = var_export(PHP_INT_MIN, true);
echo $s, "\n";
echo ($s === '-9223372036854775807-1') ? "form_ok\n" : "form_fail\n";
eval('$x = '.$s.';');
echo ($x === PHP_INT_MIN) ? "eval_ok\n" : "eval_fail\n";
