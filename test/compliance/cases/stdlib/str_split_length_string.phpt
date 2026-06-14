--TEST--
stdlib str_split() — numeric-string length coercion (#4204, ext/standard/string.c)
--FILE--
<?php
var_export(str_split('hi', '2'));
echo "\n";
try {
    str_split('hi', 'abc');
} catch (TypeError $e) {
    echo get_class($e), "\n";
}
--EXPECT--
array (
  0 => 'hi',
)
TypeError
