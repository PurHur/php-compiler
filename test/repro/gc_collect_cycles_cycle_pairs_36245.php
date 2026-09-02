<?php
// Repro for #36245 — cyclic object pairs + gc_collect_cycles (Zend: 1998/2000 for 1k pairs × 2 rounds).
class N { public $o; }
for ($r = 0; $r < 2; $r++) {
    for ($i = 0; $i < 1000; $i++) {
        $a = new N;
        $b = new N;
        $a->o = $b;
        $b->o = $a;
    }
    echo gc_collect_cycles(), "\n";
}
