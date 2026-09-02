<?php
class N { public $o; }
$a = new N;
$b = new N;
$a->o = $b;
$b->o = $a;
echo "live=", gc_collect_cycles(), "\n";
unset($a, $b);
echo "orphan=", gc_collect_cycles(), "\n";
