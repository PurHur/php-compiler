--TEST--
stdlib is_array()
--FILE--
<?php
$a = array(1, 2);
echo is_array($a) ? 'y' : 'n', "\n";
echo is_array('x') ? 'y' : 'n', "\n";
--EXPECT--
y
n
