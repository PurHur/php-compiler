--TEST--
AOT gc_collect_cycles() sweeps prior loop-cycle pairs without segfault (#36245)
--FILE--
<?php
class N { public $o; }
for ($i = 0; $i < 2; ++$i) {
    $a = new N;
    $b = new N;
    $a->o = $b;
    $b->o = $a;
}
echo gc_collect_cycles(), "\n";
--EXPECT--
2
