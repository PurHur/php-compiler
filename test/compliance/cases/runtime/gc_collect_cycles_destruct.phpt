--TEST--
runtime gc_collect_cycles() — cyclic objects invoke __destruct (#6519)
--FILE--
<?php
#[\AllowDynamicProperties]
class Node {
    public function __destruct() {
        echo "dtor\n";
    }
}
$a = new Node();
$b = new Node();
$a->next = $b;
$b->next = $a;
unset($a, $b);
gc_collect_cycles();
--EXPECT--
dtor
dtor
