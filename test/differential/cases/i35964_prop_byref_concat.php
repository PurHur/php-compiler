<?php
// #35964 leftover of #35898: by-ref instance property concat writes through.
class ParentP
{
    public $p = 'a';
}
class ChildP extends ParentP {}
$c = new ChildP();
$r =& $c->p;
$r .= 'x';
echo $c->p, "\n";
