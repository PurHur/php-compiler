<?php
// #36245 — unset cyclic locals inside a loop must keep all iterations' orphans for collect.
class N { public $o; }
for ($i = 0; $i < 2; ++$i) {
    $a = new N;
    $b = new N;
    $a->o = $b;
    $b->o = $a;
    unset($a, $b);
}
echo gc_collect_cycles(), "\n";
