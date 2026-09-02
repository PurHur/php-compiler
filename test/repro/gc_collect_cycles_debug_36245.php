<?php
// Debug repro for #36245 — 2 orphan cyclic pairs; Zend collects 2.
class N { public $o; }
for ($i = 0; $i < 2; $i++) {
    $a = new N;
    $b = new N;
    $a->o = $b;
    $b->o = $a;
}
echo gc_collect_cycles(), "\n";
