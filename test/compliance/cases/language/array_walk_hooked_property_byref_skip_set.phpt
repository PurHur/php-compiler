--TEST--
Language: array_walk by-ref on hooked property skips set (#29703, zend_property_hooks.c)
--FILE--
<?php
class C {
    public string $x {
        get { echo "GET\n"; return "v"; }
        set { echo "SET\n"; $this->x = $value; }
    }
}
$o = new C();
$o->x = "a";
array_walk($o, function (&$v, $k) { echo "WALK $k=$v\n"; $v = "z"; });
echo $o->x, "\n";

class D {
    public string $x {
        get { echo "GET\n"; return $this->x; }
        set { echo "SET\n"; $this->x = $value; }
    }
}
$d = new D();
$d->x = "a";
array_walk($d, function (&$v, $k) { echo "WALK $k=$v\n"; $v = "z"; });
echo "FINAL=";
var_export($d->x);
echo "\n";
--EXPECT--
SET
WALK x=a
GET
v
SET
WALK x=a
FINAL=GET
'z'
