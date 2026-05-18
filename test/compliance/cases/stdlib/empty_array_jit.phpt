--TEST--
stdlib empty() for arrays (JIT)
--FILE--
<?php
$a = array();
echo empty($a) ? 'y' : 'n', "\n";
$b = array(1);
echo empty($b) ? 'y' : 'n', "\n";
--EXPECT--
y
n
