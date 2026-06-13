--TEST--
stdlib str_contains()/str_starts_with()/str_ends_with() JIT — int operands coerce like Zend (#5018)
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
str_contains: no throw
str_starts_with: no throw
str_ends_with: no throw
