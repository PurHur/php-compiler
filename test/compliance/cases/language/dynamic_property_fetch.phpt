--TEST--
Dynamic property access $obj->$name (issue #1227)
--FILE--
<?php
class C {
    public int $x = 42;
    public string $label = 'ok';
}
$c = new C();
$prop = 'x';
echo $c->$prop, "\n";
$prop = 'label';
echo $c->$prop, "\n";
--EXPECT--
42
ok
