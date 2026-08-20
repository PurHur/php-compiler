--TEST--
AOT: ?-> after prior nullsafe on same local then reassignment prints property (#32749)
--FILE--
<?php
class C {
    public int $x = 5;
}
$c = null;
echo $c?->x ?? 'n';
$c = new C();
echo $c?->x;
--EXPECT--
n5
