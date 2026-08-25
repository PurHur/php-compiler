--TEST--
AOT: bool array dim coerces true→1 like Zend (Module verify; #34667)
--FILE--
<?php
$a = ['1' => 7];
echo $a[true], "\n";
$b = [1 => 9];
echo $b[true], "\n";
$k = true;
var_dump(isset($a[$k]));
var_dump(isset($b[false]));
--EXPECT--
7
9
bool(true)
bool(false)
