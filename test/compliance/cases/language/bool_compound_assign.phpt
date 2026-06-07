--TEST--
bool compound assign: +=/-= promote to int; ++/-- stays bool (issue #7340, #7058)
--FILE--
<?php
$a = true;
$a += 1;
echo get_debug_type($a), ' ', var_export($a, true), "\n";

$b = false;
$b += 1;
echo get_debug_type($b), ' ', var_export($b, true), "\n";

$c = true;
$c -= 1;
echo get_debug_type($c), ' ', var_export($c, true), "\n";

$d = true;
$d *= 2;
echo get_debug_type($d), ' ', var_export($d, true), "\n";

$t = true;
$t++;
echo get_debug_type($t), ' ', var_export($t, true), "\n";
--EXPECT--
int 2
int 1
int 0
int 2
bool true
