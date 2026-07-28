--TEST--
language WeakMap — offsetSet after gc_collect_cycles clears weak keys (#24270)
--FILE--
<?php
$o = new stdClass();
$w = new WeakMap();
$w[$o] = 42;
unset($o);
gc_collect_cycles();
$o2 = new stdClass();
$w[$o2] = 99;
echo 'val='.$w[$o2].' count='.$w->count()."\n";
foreach ($w as $k => $v) {
    echo 'iter='.$v."\n";
}
--EXPECT--
val=99 count=1
iter=99
