--TEST--
AOT set-only property hook — read returns backing value (#19163)
--FILE--
<?php
class Counter {
    public int $x {
        set { $this->x = $value < 0 ? 0 : $value; }
    }
}
$c = new Counter();
$c->x = -1;
echo $c->x, "\n";
--EXPECT--
0
