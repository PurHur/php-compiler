<?php
class N { public $o; }
for ($i = 0; $i < 2; ++$i) {
    $a = new N;
    $b = new N;
    $a->o = $b;
    $b->o = $a;
    unset($a, $b);
}
echo gc_collect_cycles(), "\n";
