--TEST--
stdlib settype() on typed properties under strict_types throws TypeError (#14218, ext/standard/type.c)
--FILE--
<?php
declare(strict_types=1);

class T {
    public int $x = 5;
    public static int $s = 2;
}
$o = new T();

try {
    settype($o->x, 'string');
    echo "inst_string ok\n";
} catch (TypeError $e) {
    echo "inst_string TypeError\n";
}

try {
    settype(T::$s, 'string');
    echo "static_string ok\n";
} catch (TypeError $e) {
    echo "static_string TypeError\n";
}
--EXPECT--
inst_string TypeError
static_string TypeError
