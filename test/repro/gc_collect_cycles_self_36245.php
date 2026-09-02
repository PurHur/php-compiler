<?php
class N { public $o; }
$a = new N;
$a->o = $a;
$a = null;
echo gc_collect_cycles(), "\n";
