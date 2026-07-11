--TEST--
stdlib array_find()/array_find_key() empty array ValueError (#12519, ext/standard/array.c)
--FILE--
<?php
foreach (['array_find', 'array_find_key'] as $fn) {
    try {
        $fn([], static fn ($v) => true);
        echo $fn, ": uncaught\n";
    } catch (ValueError $e) {
        echo $fn, ': ', $e->getMessage(), "\n";
    }
}
var_export(array_find([1, 2, 3], static fn ($v) => 2 === $v));
echo "\n";
?>
--EXPECT--
array_find: array_find(): Argument #1 ($array) must not be empty
array_find_key: array_find_key(): Argument #1 ($array) must not be empty
2
