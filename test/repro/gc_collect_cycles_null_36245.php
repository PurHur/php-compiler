<?php
class N { public $o; }
$a = new N;
$b = new N;
$a->o = $b;
$b->o = $a;
$a = null;
$b = null;
echo gc_collect_cycles(), "\n";
