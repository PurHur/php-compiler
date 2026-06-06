--TEST--
foreach by-reference on array-literal ref to property hooks invokes set hook (#6475, zend_property_hooks.c)
--FILE--
<?php
class C {
    private int $x = 1;
    public int $y {
        get => $this->x;
        set => $this->x = $value;
    }
}
$c = new C();
foreach ([&$c->y] as &$v) {
    $v = 5;
}
echo $c->y, "\n";

$ref =& $c->y;
$ref = 9;
echo $c->y, "\n";

class G {
    private int $v = 1;
    public int $x {
        get => $this->v;
    }
}
$g = new G();
try {
    foreach ([&$g->x] as &$v) {
        echo "loop\n";
    }
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
--EXPECT--
5
9
Error
