--TEST--
unset() on a local variable (issue #1224)
--FILE--
<?php
$x = 'gone';
unset($x);
echo isset($x) ? 'set' : 'unset', "\n";
--EXPECT--
unset
