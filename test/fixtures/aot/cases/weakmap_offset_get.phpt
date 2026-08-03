--TEST--
AOT: WeakMap object-key offsetSet/offsetGet (#27244)
--FILE--
<?php
$wm = new WeakMap();
$o = new stdClass();
$wm[$o] = 42;
echo $wm[$o], "\n";
--EXPECT--
42
--EXPECT_EXIT--
0
