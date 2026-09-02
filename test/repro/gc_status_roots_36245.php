<?php
class N { public $o; }
$a = new N;
$b = new N;
$a->o = $b;
$b->o = $a;
$st = gc_status();
echo 'roots_before=', $st['roots'], "\n";
unset($a, $b);
$st = gc_status();
echo 'roots_after=', $st['roots'], "\n";
echo 'collect=', gc_collect_cycles(), "\n";
