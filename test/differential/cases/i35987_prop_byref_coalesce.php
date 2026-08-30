<?php
// @differential-repeat: 3 by-ref property ??= write-through (#35987)
class C { public $n = null; }
$o = new C;
$r =& $o->n;
$r ??= 5;
echo "$r|{$o->n}\n";
