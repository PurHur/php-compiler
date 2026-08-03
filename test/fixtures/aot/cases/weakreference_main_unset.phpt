--TEST--
AOT: WeakReference::get null after {main} unset (#27118)
--FILE--
<?php
$o = new stdClass();
$w = WeakReference::create($o);
echo ($w->get() !== null) ? "live\n" : "dead\n";
unset($o);
echo ($w->get() === null) ? "dead\n" : "live\n";
--EXPECT--
live
dead
--EXPECT_EXIT--
0
