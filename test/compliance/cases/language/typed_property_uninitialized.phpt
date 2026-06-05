--TEST--
Language: uninitialized typed property read throws Error (#3429)
--FILE--
<?php
class C {
    public int $x;
}
$c = new C;
$c2 = new C;
var_dump(isset($c->x));
try {
    var_dump(empty($c2->x));
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
try {
    echo $c->x;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
$c->x = 1;
var_dump(isset($c->x));
echo $c->x, "\n";
--EXPECT--
bool(false)
Typed property C::$x must not be accessed before initialization
Typed property C::$x must not be accessed before initialization
bool(true)
1
