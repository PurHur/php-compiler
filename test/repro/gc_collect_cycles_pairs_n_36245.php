<?php
class N { public $o; }
function make_pair(): void {
    $a = new N;
    $b = new N;
    $a->o = $b;
    $b->o = $a;
}
make_pair();
echo gc_collect_cycles(), "\n";
