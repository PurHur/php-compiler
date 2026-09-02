<?php
// Minimal gc_collect_cycles probes for #36245
class N { public $o; }

$case = (int) ($argv[1] ?? 0);
switch ($case) {
    case 0:
        echo gc_collect_cycles(), "\n";
        break;
    case 1:
        $a = new N;
        $b = new N;
        $a->o = $b;
        $b->o = $a;
        echo gc_collect_cycles(), "\n";
        break;
    case 2:
        $a1 = new N;
        $b1 = new N;
        $a1->o = $b1;
        $b1->o = $a1;
        $a2 = new N;
        $b2 = new N;
        $a2->o = $b2;
        $b2->o = $a2;
        echo gc_collect_cycles(), "\n";
        break;
    default:
        fwrite(STDERR, "usage: probe.php 0|1|2\n");
        exit(2);
}
