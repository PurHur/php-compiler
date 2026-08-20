<?php
// Nullsafe ?-> must compile under thin AOT (no dominate failure) (#32988).
class A
{
    public $b = 1;
}
$a = null;
var_dump($a?->b);
$o = new A();
echo $o?->b, "\n";
echo ($a?->b ?? 'x'), "\n";
