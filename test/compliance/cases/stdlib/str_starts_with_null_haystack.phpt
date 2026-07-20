--TEST--
stdlib str_starts_with()/str_ends_with()/str_contains() — null haystack soft-null (#10989/#21187, ext/standard/string.c)
--FILE--
<?php
foreach (['str_starts_with', 'str_ends_with', 'str_contains'] as $fn) {
    try {
        echo "$fn: ", var_export($fn(null, 'a'), true), "\n";
    } catch (TypeError $e) {
        echo "$fn: ", $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
str_starts_with: false
str_ends_with: false
str_contains: false
