--TEST--
stdlib floor()/ceil()/round()/fmod() JIT — numeric-string coercion (#4350)
--FILE--
<?php
var_dump(floor("3.7"));
var_dump(ceil("3.1"));
var_dump(round("3.5"));
var_dump(fmod("5", "2"));
try {
    floor("abc");
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
float(3)
float(4)
float(4)
float(1)
floor(): Argument #1 ($num) must be of type int|float, string given
