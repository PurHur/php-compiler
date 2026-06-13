--TEST--
stdlib str_contains()/str_starts_with()/str_ends_with() — int operands coerce like Zend (ext/standard/string.c)
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
try {
    str_contains([], 'a');
    echo "array: no throw\n";
} catch (TypeError $e) {
    echo "array: TypeError\n";
}
?>
--EXPECT--
str_contains: no throw
str_starts_with: no throw
str_ends_with: no throw
array: TypeError
