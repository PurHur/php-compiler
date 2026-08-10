--TEST--
Language: foreach() on hooked properties invokes get once (#29702, zend_property_hooks.c)
--FILE--
<?php
class C {
    public string $x {
        get { echo "GET\n"; return "v"; }
        set { $this->x = $value; }
    }
    public int $y = 2;
}
$o = new C();
$o->x = "a";
foreach ($o as $k => $v) {
    echo "BODY $k=$v\n";
}
--EXPECT--
GET
BODY x=v
BODY y=2
