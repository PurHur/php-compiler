--TEST--
Language: empty() on uninitialized typed property throws Error (#4912, zend_object_handlers.c)
--FILE--
<?php
class C {
    public int $x;
}
$c = new C();
try {
    var_dump(empty($c->x));
    echo "no throw\n";
} catch (\Error $e) {
    echo $e->getMessage(), "\n";
}
var_export(isset($c->x));
echo "\n";
$c->x = 0;
var_export(empty($c->x));
echo "\n";
$c->x = 1;
var_export(empty($c->x));
echo "\n";
--EXPECT--
Typed property C::$x must not be accessed before initialization
false
true
false
