<?php
// Scope probe for #36245 — outer collect after make_pair() locals die.
class N { public $o; }
function make_pair(): void {
    $a = new N;
    $b = new N;
    $a->o = $b;
    $b->o = $a;
    echo "inner=", gc_collect_cycles(), "\n";
}
make_pair();
echo "outer=", gc_collect_cycles(), "\n";
