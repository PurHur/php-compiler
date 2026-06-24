--TEST--
Language: isset() on uninitialized get+set property hook returns false without get hook (#11262, zend_object_handlers.c)
--FILE--
<?php
class C {
    public int $x {
        get { echo "GET\n"; return $this->x; }
        set => $this->x = $value;
    }
    private int $x;
}
$c = new C();
var_export(isset($c->x));
--EXPECT--
false
