<?php
// #35984 leftover of #35978: by-ref dim **= writes through (local + property array).
$a = ['a' => 3];
$r =& $a['a'];
$r **= 2;
echo $r, '|', $a['a'], '|';
class C
{
    public $p = ['a' => 3];
}
$o = new C();
$s =& $o->p['a'];
$s **= 2;
echo $s, '|', $o->p['a'], "\n";
