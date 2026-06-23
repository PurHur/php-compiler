--TEST--
Language: isset()/empty() on property hooks invoke get when present (#10680, zend_std_has_property)
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
GET
true
