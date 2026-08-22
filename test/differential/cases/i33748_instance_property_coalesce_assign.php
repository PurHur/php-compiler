<?php
// @differential-repeat: 10   AOT instance ??= store was a no-op (Undefined property, #33748)
class C33748
{
    public $p;
}

$o = new C33748();
$o->p ??= 5;
echo $o->p, "\n";
$o->p ??= 9;
echo $o->p, "\n";
