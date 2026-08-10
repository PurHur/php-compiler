--TEST--
mb_substitute_character(null) returns current subst under strict_types (#29919)
--FILE--
<?php
declare(strict_types=1);
error_reporting(E_ALL);
echo var_export(mb_substitute_character(null), true), "\n";
echo mb_substitute_character(0xFFFD) ? "set\n" : "set-fail\n";
echo var_export(mb_substitute_character(null), true), "\n";
echo mb_substitute_character('long') ? "set-long\n" : "set-long-fail\n";
echo var_export(mb_substitute_character(null), true), "\n";
echo mb_substitute_character(63) ? "reset\n" : "reset-fail\n";
echo var_export(mb_substitute_character(), true), "\n";
?>
--EXPECT--
63
set
65533
set-long
'long'
reset
63
