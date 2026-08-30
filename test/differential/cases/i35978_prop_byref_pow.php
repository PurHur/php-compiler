<?php
// #35978 leftover of #35964: by-ref instance property **= writes through.
class ParentN
{
    public $n = 3;
}
class ChildN extends ParentN {}
$c = new ChildN();
$r =& $c->n;
$r **= 2;
echo $c->n, "\n";
