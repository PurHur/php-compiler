--TEST--
language bool pre/post increment (issue #3552, #7058)
--FILE--
<?php
$b = true;
$b++;
echo get_debug_type($b), " ", var_export($b, true), "\n";
$b = false;
$b++;
echo get_debug_type($b), " ", var_export($b, true), "\n";
$b = true;
$b--;
echo get_debug_type($b), " ", var_export($b, true), "\n";
$b = false;
$b--;
echo get_debug_type($b), " ", var_export($b, true), "\n";
--EXPECT--
bool true
bool false
bool true
bool false
