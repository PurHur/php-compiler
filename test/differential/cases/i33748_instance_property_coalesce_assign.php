<?php
// @differential-repeat: 10   AOT instance ??= store was a no-op (Undefined property, #33748)
class A33748Diff
{
    public $p;
}

$o = new A33748Diff();
$o->p ??= 5;
echo $o->p, "\n";
$o->p ??= 9;
echo $o->p, "\n";
