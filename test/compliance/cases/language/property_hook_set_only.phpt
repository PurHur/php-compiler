--TEST--
Language: set-only property hook read returns backing after write (#19163, zend_object_handlers.c)
--FILE--
<?php
final class Counter {
    public int $x {
        set {
            $this->x = max(0, $value);
        }
    }
}
$c = new Counter();
$c->x = -1;
echo $c->x, "\n";
--EXPECT--
0
