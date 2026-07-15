--TEST--
str_contains/str_starts_with/str_ends_with — null haystack coerces to false on default profile (#18276; 8.4 TypeError is #19273)
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
