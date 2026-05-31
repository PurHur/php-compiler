--TEST--
array_combine(): mismatched key/value counts throw ValueError (#3561)
--FILE--
<?php
try {
    array_combine([1, 2], [3]);
    echo "no throw\n";
} catch (ValueError $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
echo array_combine([], []) === [] ? "empty ok\n" : "empty fail\n";
--EXPECT--
ValueError
array_combine(): Argument #1 ($keys) and argument #2 ($values) must have the same number of elements
empty ok
