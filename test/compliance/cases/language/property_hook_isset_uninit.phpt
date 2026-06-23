--TEST--
Language: isset() on uninitialized get+set property hook invokes get then TypeError (#10680, zend_std_has_property)
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
--EXPECTF--
GET
Fatal error: Uncaught Error: Typed property %s::$x must not be accessed before initialization
