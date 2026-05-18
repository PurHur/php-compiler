--TEST--
stdlib empty() for arrays
--FILE--
<?php
$a = array();
echo empty($a) ? 'y' : 'n', "\n";
$b = array(1);
echo empty($b) ? 'y' : 'n', "\n";
echo empty(array()) ? 'y' : 'n', "\n";
--EXPECT--
y
n
y
