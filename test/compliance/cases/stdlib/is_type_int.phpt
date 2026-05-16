--TEST--
stdlib is_int() type check
--FILE--
<?php
$a = 1;
echo is_int($a) ? 'y' : 'n', "\n";
echo is_int(0) ? 'y' : 'n', "\n";
--EXPECT--
y
y
