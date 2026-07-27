--TEST--
stdlib var_export() PHP_INT_MIN eval-safe form (#23690)
--FILE--
<?php
$s = var_export(PHP_INT_MIN, true);
echo $s, "\n";
echo ($s === '-9223372036854775807-1') ? 'form_ok' : 'form_fail';
echo "\n";
eval('$x = '.$s.';');
echo ($x === PHP_INT_MIN) ? 'eval_ok' : 'eval_fail';
echo "\n";
--EXPECT--
-9223372036854775807-1
form_ok
eval_ok
