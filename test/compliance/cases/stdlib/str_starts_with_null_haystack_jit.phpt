--TEST--
stdlib str_starts_with()/str_ends_with()/str_contains() JIT — null haystack TypeError (#10989)
--JIT--
--FILE--
<?php
foreach (['str_starts_with', 'str_ends_with', 'str_contains'] as $fn) {
    try {
        $fn(null, 'a');
        echo "$fn: uncaught\n";
    } catch (TypeError $e) {
        echo "$fn: ", $e->getMessage(), "\n";
    }
}
?>
--EXPECTF--
%A
str_starts_with: str_starts_with(): Argument #1 ($haystack) must be of type string, null given
str_ends_with: str_ends_with(): Argument #1 ($haystack) must be of type string, null given
str_contains: str_contains(): Argument #1 ($haystack) must be of type string, null given
