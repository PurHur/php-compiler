--TEST--
stdlib array_change_key_case() lower and upper (ASCII string keys, CASE_* constants)
--FILE--
<?php
$a = array('FirSt' => 1, 'SecOnd' => 2);
$lo = array_change_key_case($a, CASE_LOWER);
echo $lo['first'], "\n";
echo $lo['second'], "\n";
$hi = array_change_key_case($a, CASE_UPPER);
echo $hi['FIRST'], "\n";
echo $hi['SECOND'], "\n";
$b = array(10 => 'x', 20 => 'y');
$c = array_change_key_case($b, CASE_LOWER);
echo $c[10], "\n";
echo $c[20], "\n";
--EXPECT--
1
2
1
2
x
y
