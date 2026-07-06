--TEST--
stdlib array_all()/array_any() inline [] literal vacuous results; array_find() empty ValueError (#10827, #12519, ext/standard/array.c)
--FILE--
<?php
echo array_all([], fn ($v) => (bool) $v) ? 'all' : 'notall', "\n";
echo array_any([], fn ($v) => (bool) $v) ? 'any' : 'notany', "\n";
try {
    array_find([], fn ($v) => (bool) $v);
    echo "find uncaught\n";
} catch (ValueError $e) {
    echo 'find: ', $e->getMessage(), "\n";
}
--EXPECT--
all
notany
find: array_find(): Argument #1 ($array) must not be empty
