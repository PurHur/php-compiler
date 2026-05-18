--TEST--
stdlib is_null()
--FILE--
<?php
$x = null;
echo is_null($x) ? 'y' : 'n', "\n";
echo is_null(null) ? 'y' : 'n', "\n";
echo is_null(0) ? 'y' : 'n', "\n";
echo is_null('') ? 'y' : 'n', "\n";
--EXPECT--
y
y
n
n
