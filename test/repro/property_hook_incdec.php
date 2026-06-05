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
