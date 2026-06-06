--TEST--
Instance property hooks — post/pre inc/dec dispatch get+set hooks (#6452, #6309, zend_property_hooks.c)
--FILE--
<?php
class Counter {
    private int $n = 0;
    public int $count {
        get => $this->n;
        set (int $v) { $this->n = $v; }
    }
}
$c = new Counter();
echo $c->count++, "\n";
var_export($c->count);
echo "\n";
$c->count = 0;
echo ++$c->count, "\n";
var_export($c->count);
--EXPECT--
0
1
1
1
