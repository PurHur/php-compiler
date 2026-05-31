--TEST--
stdlib gc_enable/gc_disable/gc_enabled — toggle API (#3209)
--FILE--
<?php
var_export(gc_enabled());
echo "\n";
gc_disable();
var_export(gc_enabled());
echo "\n";
gc_enable();
var_export(gc_enabled());
echo "\n";

class Node { public $next; }
$a = new Node();
$b = new Node();
$a->next = $b;
$b->next = $a;
unset($a, $b);
gc_disable();
echo gc_collect_cycles();
echo "\n";
gc_enable();
echo gc_collect_cycles();
--EXPECT--
true
false
true
0
2
