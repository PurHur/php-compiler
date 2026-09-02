<?php
// Refcount probe for #36245 — global keeps one leg of the cycle alive.
class N { public $o; }
$g = null;
function make_pair(): void {
    global $g;
    $a = new N;
    $b = new N;
    $a->o = $b;
    $b->o = $a;
    $g = $a;
}
make_pair();
echo "collect=", gc_collect_cycles(), "\n";
