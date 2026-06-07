--TEST--
Language: union typed instance property uninitialized read throws Error (#6701)
--FILE--
<?php
class C {
    public int|string $p;
}
$c = new C();
var_export(isset($c->p));
echo "\n";
try {
    echo $c->p;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
false
Typed property C::$p must not be accessed before initialization
