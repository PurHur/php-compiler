<?php
// @differential-repeat: 10   AOT ??= store of Assign.result was 0/garbage (#35998)
class C35998Diff
{
    public $n = null;
}

$o = new C35998Diff();
$o->n ??= $x = 7;
echo $o->n, '|', $x, "\n";
$o->n ??= $x = 9;
echo $o->n, '|', $x, "\n";
