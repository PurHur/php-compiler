--TEST--
foreach by-reference on property hooks — iteration backing + in-loop inc/dec (#8870, zend_property_hooks.c)
--FILE--
<?php
class Counter {
    private ?int $_count = null;
    public int $count {
        get => $this->_count ?? 0;
        set {
            $this->_count = $value;
        }
    }
}
$c = new Counter();
foreach ([10, 20] as &$c->count) {
    $c->count++;
}
echo $c->count, "\n";

class Acc {
    private static int $_n = 0;
    public static int $total {
        get => self::$_n;
        set => self::$_n = $value * 10;
    }
}
foreach ([1, 2, 3] as &Acc::$total) {
    Acc::$total++;
}
echo Acc::$total, "\n";
--EXPECT--
21
40
