--TEST--
stdlib gc_enable/gc_disable/gc_enabled/gc_collect_cycles — JIT toggle + cycle collect (#4023)
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

#[\AllowDynamicProperties]
class Node {
}
$a = new Node();
$b = new Node();
$a->next = $b;
$b->next = $a;
unset($a, $b);
echo gc_collect_cycles(), "\n";
--EXPECT--
true
false
true
2
