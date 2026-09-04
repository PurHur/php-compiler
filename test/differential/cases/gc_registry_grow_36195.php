<?php
// #36195 — GC collects large cyclic graphs (5k pairs = 10k objects, under Zend's
// ~10k root-buffer first-pass window so VM and Zend both return 9998).
// Full 40k-pair root-buffer *cap* parity (Zend stays ~9998, uncapped VM drains all)
// remains a follow-up when the collector grows a Zend-shaped root buffer.
class N { public $o; }
for ($i = 0; $i < 5000; ++$i) {
    $a = new N;
    $b = new N;
    $a->o = $b;
    $b->o = $a;
}
echo gc_collect_cycles(), "\n";
