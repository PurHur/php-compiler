--TEST--
Stdlib: settype() on typed properties coerces to declared type (ext/standard/type.c, #11508)
--FILE--
<?php
class T {
    public int $x = 5;
    public static int $s = 2;
}
$o = new T();
settype($o->x, 'string');
echo gettype($o->x), '=', var_export($o->x, true), "\n";
settype(T::$s, 'string');
echo gettype(T::$s), '=', var_export(T::$s, true), "\n";
try {
    settype($o->x, 'array');
    echo "no_throw\n";
} catch (TypeError $e) {
    echo "TypeError\n";
}
--EXPECT--
integer=5
integer=2
TypeError
