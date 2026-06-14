--TEST--
stdlib count_chars() — numeric-string mode coercion (#4204, ext/standard/string.c)
--FILE--
<?php
var_export(count_chars('ab', '1'));
echo "\n";
try {
    count_chars('ab', 'abc');
} catch (TypeError $e) {
    echo get_class($e), "\n";
}
--EXPECT--
array (
  97 => 1,
  98 => 1,
)
TypeError
