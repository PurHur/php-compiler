--TEST--
unset() on a local variable
--FILE--
<?php
$x = 42;
unset($x);
echo isset($x) ? "set\n" : "unset\n";
--EXPECT--
unset
