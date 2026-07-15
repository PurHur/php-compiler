--TEST--
stdlib php_uname() null/int operands coerce to string like Zend (ext/standard/info.c, #18970)
--FILE--
<?php
$full = php_uname('a');
$null = php_uname(null);
$int = php_uname(123);
echo $null === $full ? "null_matches_a\n" : "null_mismatch\n";
echo $int === $full ? "int_matches_a\n" : "int_mismatch\n";
echo php_uname('s') === php_uname('s') ? "known_mode_ok\n" : "known_mode_fail\n";
?>
--EXPECT--
null_matches_a
int_matches_a
known_mode_ok
