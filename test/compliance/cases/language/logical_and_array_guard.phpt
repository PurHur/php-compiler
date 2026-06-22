--TEST--
language logical && with array guard and str_contains — bool not haystack (#10626, Zend/zend_operators.c)
--FILE--
<?php
$warnings = ['filetype(): Lstat failed for /no/such/path'];
var_export([] !== $warnings && str_contains($warnings[0], 'Lstat failed'));
echo "\n";
$r1 = [] !== $warnings;
$r2 = str_contains($warnings[0], 'Lstat failed');
var_export($r1 && $r2);
echo "\n";
?>
--EXPECT--
true
true
