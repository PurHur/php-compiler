--TEST--
stdlib str_contains()/str_starts_with()/str_ends_with() JIT — TypeError for int operands (#5018)
--JIT--
--FILE--
<?php
foreach (['str_contains', 'str_starts_with', 'str_ends_with'] as $fn) {
    try {
        $fn(1, 'a');
        echo "$fn: no throw\n";
    } catch (TypeError $e) {
        echo "$fn: ", $e->getMessage(), "\n";
    }
}
?>
--EXPECT--
str_contains: str_contains(): Argument #1 ($haystack) must be of type string, int given
str_starts_with: str_starts_with(): Argument #1 ($haystack) must be of type string, int given
str_ends_with: str_ends_with(): Argument #1 ($haystack) must be of type string, int given
