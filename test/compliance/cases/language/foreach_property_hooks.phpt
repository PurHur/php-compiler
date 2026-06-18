--TEST--
Language: foreach() on objects with property hooks invokes get hook (#9470, zend_property_hooks.c)
--FILE--
<?php
class C {
    public string $x {
        get { return 'hooked'; }
    }
    public int $y = 42;
}
$c = new C();
foreach ($c as $k => $v) {
    echo "$k=$v\n";
}
--EXPECT--
x=hooked
y=42
