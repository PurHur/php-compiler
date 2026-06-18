--TEST--
Language: isset()/empty() on property hooks probe backing only — no get hook (#9671, zend_property_hooks.c)
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
false
true
true
