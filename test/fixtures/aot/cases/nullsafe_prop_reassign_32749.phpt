--TEST--
AOT: nullsafe ?-> after reassigning a prior nullsafe receiver prints property (#32749)
--FILE--
<?php
class C {
    public $x = 5;
}

$c = null;
echo $c?->x ?? 'n';
$c = new C;
echo $c?->x;
--EXPECT--
n5
