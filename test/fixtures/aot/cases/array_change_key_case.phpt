--TEST--
AOT array_change_key_case() lower and upper (ASCII string keys)
--FILE--
<?php
$a = array('FirSt' => 1, 'SecOnd' => 2);
$lo = array_change_key_case($a);
echo $lo['first'], "\n";
echo $lo['second'], "\n";
$hi = array_change_key_case($a, 1);
echo $hi['FIRST'], "\n";
echo $hi['SECOND'], "\n";
--EXPECT--
1
2
1
2
