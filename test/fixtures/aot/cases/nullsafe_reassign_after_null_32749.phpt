--TEST--
AOT: nullsafe ?-> after reassigning a prior null receiver keeps user class (#32749)
--FILE--
<?php
class C
{
    public $x = 5;
}
$c = null;
echo $c?->x ?? 'n';
$c = new C;
echo $c?->x;
echo "\n";
--EXPECT--
n5
