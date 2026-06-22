--TEST--
language logical && with array guard and str_contains — JIT (#10626, Zend/zend_operators.c)
--FILE--
<?php
$warnings = ['filetype(): Lstat failed for /no/such/path'];
var_export([] !== $warnings && str_contains($warnings[0], 'Lstat failed'));
echo "\n";
?>
--EXPECT--
true
