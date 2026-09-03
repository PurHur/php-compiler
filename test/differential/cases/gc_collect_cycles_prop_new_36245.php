<?php
// #36245 — unnamed `new` stored into a property must not leak an extra addref.
class N { public $o; }
function f(): void {
    $a = new N;
    $a->o = new N;
    $a->o->o = $a;
}
f();
echo gc_collect_cycles(), "\n";
