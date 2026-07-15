--TEST--
str_contains/str_starts_with/str_ends_with — null needle coerces to true on non-empty haystack JIT (#18344, ext/standard/string.c)
--JIT--
--FILE--
<?php
foreach (['str_contains', 'str_starts_with', 'str_ends_with'] as $fn) {
    var_export($fn('abc', null));
    echo "\n";
}
--EXPECT--
true
true
true
