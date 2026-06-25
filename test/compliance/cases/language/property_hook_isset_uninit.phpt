--TEST--
Language: isset()/empty() on uninitialized get+set property hook — no get hook (#11262, #11617, zend_object_api.c)
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
echo "\n";
var_export(empty($c->x));
--EXPECT--
false
true
