<?php
// Status probe for #36245 — roots count after make_pair().
class N { public $o; }
function make_pair(): void {
    $a = new N;
    $b = new N;
    $a->o = $b;
    $b->o = $a;
}
make_pair();
$s = gc_status();
echo 'roots=', $s['roots'], ' collected=', gc_collect_cycles(), "\n";
