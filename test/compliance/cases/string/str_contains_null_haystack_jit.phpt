--TEST--
str_contains/str_starts_with/str_ends_with — null haystack coerces to false JIT (#18276, ext/standard/string.c)
--JIT--
--FILE--
<?php
foreach (['str_contains', 'str_starts_with', 'str_ends_with'] as $fn) {
    var_export($fn(null, 'a'));
    echo "\n";
}
--EXPECT--
false
false
false
