--TEST--
Language: set-only property hook read returns backing after write — JIT (#22452, re-#19163, zend_object_handlers.c)
--FILE--
<?php
final class Counter {
    public int $x {
        set(int $v) => $this->x = max(0, $v);
    }
}
$c = new Counter();
$c->x = -1;
echo $c->x, "\n";
--EXPECT--
0
