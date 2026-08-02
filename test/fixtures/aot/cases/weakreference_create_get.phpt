--TEST--
AOT: WeakReference::create/get null after unset (#26795)
--FILE--
<?php
class Box
{
    public $x = 1;
}
function wr_probe(): void
{
    $o = new Box();
    $r = WeakReference::create($o);
    echo is_object($r) ? "1\n" : "0\n";
    unset($o);
    echo ($r->get() === null) ? "null\n" : "obj\n";
}
wr_probe();
--EXPECT--
1
null
--EXPECT_EXIT--
0
