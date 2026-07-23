--TEST--
Language: set-only property hook read returns backing after write (#22452, re-#19163, zend_object_handlers.c)
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

class Upper {
    public string $x {
        set => $this->x = strtoupper($value);
    }
}
$u = new Upper();
$u->x = 'hi';
echo $u->x, "\n";
--EXPECT--
0
HI
