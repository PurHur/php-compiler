<?php
// #36245 / #36195 — orphan cyclic pairs; gc_collect_cycles must match Zend.
class N { public $o; }
for ($i = 0; $i < 2; $i++) {
    $a = new N;
    $b = new N;
    $a->o = $b;
    $b->o = $a;
}
echo gc_collect_cycles(), "\n";
