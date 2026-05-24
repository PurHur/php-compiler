--TEST--
unset() on a local variable
--FILE--
<?php
$x = 1;
unset($x);
echo isset($x) ? "set" : "unset", "\n";
--EXPECT--
unset
