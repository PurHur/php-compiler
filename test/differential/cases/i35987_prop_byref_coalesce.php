<?php
// #35987 leftover of #35898: by-ref instance property ??= writes through.
class ParentN
{
    public $n = null;
}
class ChildN extends ParentN {}
$c = new ChildN();
$r =& $c->n;
$r ??= 5;
echo $c->n, "\n";
