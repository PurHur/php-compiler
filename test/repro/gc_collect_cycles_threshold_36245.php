<?php
// Find segfault threshold for LLVM standalone GC sweep (#36245).
class N { public $o; }
$n = (int) ($argv[1] ?? 10);
for ($i = 0; $i < $n; ++$i) {
    $a = new N;
    $b = new N;
    $a->o = $b;
    $b->o = $a;
}
echo gc_collect_cycles(), "\n";
