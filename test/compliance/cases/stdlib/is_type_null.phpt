--TEST--
stdlib is_null() type check
--FILE--
<?php
$a = null;
echo is_null($a) ? 'y' : 'n', "\n";
echo is_null(null) ? 'y' : 'n', "\n";
echo is_null(0) ? 'y' : 'n', "\n";
--EXPECT--
y
y
n
