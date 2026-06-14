--TEST--
stdlib unpack() — numeric-string offset coercion (#4204, ext/standard/pack.c)
--FILE--
<?php
var_export(unpack('C', 'x', '0'));
echo "\n";
try {
    unpack('C', 'x', 'abc');
} catch (TypeError $e) {
    echo get_class($e), "\n";
}
--EXPECT--
array (
  1 => 120,
)
TypeError
