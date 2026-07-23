--TEST--
AOT set-only property hook — read returns backing value (#22452, re-#19163)
--FILE--
<?php
class Counter {
    public int $x {
        set(int $v) => $this->x = $v < 0 ? 0 : $v;
    }
}
$c = new Counter();
$c->x = -1;
echo $c->x, "\n";
--EXPECT--
0
