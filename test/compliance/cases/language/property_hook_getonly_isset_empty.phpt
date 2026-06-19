--TEST--
Language: isset()/empty() on get-only virtual property hooks invoke get (#9832, zend_property_hooks.c)
--FILE--
<?php
class C {
    public int $x {
        get => 1;
    }
}
$c = new C();
var_dump(isset($c->x));
var_dump(empty($c->x));
--EXPECT--
bool(true)
bool(false)
