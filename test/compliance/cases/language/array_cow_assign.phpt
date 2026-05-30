--TEST--
Runtime: array copy-on-write on assignment (Zend zend_variables.c parity, #3760)
--FILE--
<?php
$a = [1, 2, 3];
$b = $a;
$b[0] = 99;
echo "assign:", $a[0], " ", $b[0], "\n";

$a2 = ['x' => 1];
$b2 = $a2;
$b2['x'] = 2;
echo "assoc:", $a2['x'], " ", $b2['x'], "\n";

$a3 = [1];
$b3 = $a3;
$b3[] = 2;
echo "append:", count($a3), " ", count($b3), "\n";
?>
--EXPECT--
assign:1 99
assoc:1 2
append:1 2
