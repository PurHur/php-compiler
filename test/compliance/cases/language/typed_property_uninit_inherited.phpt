--TEST--
Language: uninitialized inherited typed property — declaring class (#31785 / #4614)
--FILE--
<?php
class Base { public int $x; }
class Child extends Base {}

$c = new Child();
var_export(isset($c->x));
echo "\n";
try {
    echo $c->x;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
false
Typed property Base::$x must not be accessed before initialization
