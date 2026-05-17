--TEST--
stdlib is_float() / is_double()
--FILE--
<?php
$f = 1.5;
echo is_float($f) ? 'y' : 'n', "\n";
echo is_double($f) ? 'y' : 'n', "\n";
echo is_float(1) ? 'y' : 'n', "\n";
--EXPECT--
y
y
n
