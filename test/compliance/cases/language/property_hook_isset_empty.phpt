--TEST--
Language: isset()/empty() on get+set property hooks — isset probes backing; empty invokes get (#11262, #13055, zend_object_handlers.c)
--FILE--
<?php
class D {
    public int $x {
        get { echo "GET\n"; return $this->x; }
        set => $this->x = $value;
    }
    private int $x = 0;
}
$d = new D();
var_export(empty($d->x));
echo "\n";

class E {
    public int $x {
        get { echo "GET\n"; return $this->x; }
        set => $this->x = $value;
    }
    private int $x = 42;
}
$e = new E();
var_export(isset($e->x));
echo "\n";
--EXPECT--
GET
true
true
