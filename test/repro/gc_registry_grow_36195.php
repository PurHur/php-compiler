<?php
// #36195 — GC registry must track >65536 objects on user-script AOT (Zend: ~80k per round).
class N { public $o; }
for ($i = 0; $i < 40000; ++$i) {
    $a = new N;
    $b = new N;
    $a->o = $b;
    $b->o = $a;
}
echo gc_collect_cycles(), "\n";
