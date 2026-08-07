<?php
// repro #27030 — AOT unserialize(serialize(new C))->a must print 1 (no segfault)
class C { public $a = 1; }
$o = unserialize(serialize(new C));
echo $o->a, PHP_EOL;
