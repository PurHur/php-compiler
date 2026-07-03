--TEST--
Language: var_dump(property_exists(), isset()) on uninitialized typed property (#15646)
--FILE--
<?php
class C {
    public int $x;
}
$o = new C;
var_dump(property_exists($o, 'x'), isset($o->x));
--EXPECT--
bool(true)
bool(false)
