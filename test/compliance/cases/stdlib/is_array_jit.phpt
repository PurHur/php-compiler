--TEST--
stdlib is_array() JIT on list and non-array
--FILE--
<?php
$a = array(1, 2);
echo is_array($a) ? 'y' : 'n', "\n";
echo is_array('x') ? 'y' : 'n', "\n";
echo is_array(null) ? 'y' : 'n', "\n";
--EXPECT--
y
n
n
