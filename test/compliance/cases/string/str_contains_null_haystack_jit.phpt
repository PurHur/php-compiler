--TEST--
str_contains/str_starts_with/str_ends_with — null haystack coerces to false JIT on default profile (#18276; soft-null also on 8.4 — #21187)
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
