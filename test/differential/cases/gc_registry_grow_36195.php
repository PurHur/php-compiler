<?php
// #36195 — GC root buffer must match Zend on large cyclic graphs (40k pairs).
class N { public $o; }
for ($i = 0; $i < 40000; ++$i) {
    $a = new N;
    $b = new N;
    $a->o = $b;
    $b->o = $a;
}
echo gc_collect_cycles(), "\n";
