<?php
class N { public $o; }
$a = new N;
$b = new N;
$a->o = $b;
$b->o = $a;
gc_collect_cycles();
unset($a, $b);
echo gc_collect_cycles(), "\n";
