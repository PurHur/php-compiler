--TEST--
foreach by-reference on property hooks — iteration assign + in-loop inc/dec (JIT) (#9761, zend_property_hooks.c)
--FILE--
<?php
class Acc {
    private int $_n = 0;
    public int $total {
        get => $this->_n;
        set => $this->_n = $value * 10;
    }
}
$c = new Acc();
foreach ([1, 2, 3] as &$c->total) {
    $c->total++;
}
echo $c->total, "\n";

class GetOnly {
    private int $_n = 0;
    public int $x {
        get => $this->_n;
    }
}
$g = new GetOnly();
try {
    foreach ([1] as &$g->x) {
        echo "loop\n";
    }
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
40
Error
