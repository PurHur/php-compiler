--TEST--
stdlib WeakMap — object key offsetSet/offsetGet (#4645)
--FILE--
<?php
$m = new WeakMap();
$o = new stdClass();
$m[$o] = 99;
echo $m[$o], "\n";
echo count($m), "\n";
--EXPECT--
99
1
