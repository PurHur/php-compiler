--TEST--
stdlib is_string()
--FILE--
<?php
$s = 'x';
echo is_string($s) ? 'y' : 'n', "\n";
echo is_string('') ? 'y' : 'n', "\n";
echo is_string(1) ? 'y' : 'n', "\n";
--EXPECT--
y
y
n
