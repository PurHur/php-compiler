--TEST--
stdlib array_find()/array_find_key() null callback — TypeError (#17133, ext/standard/array.c)
--FILE--
<?php
foreach (['array_find', 'array_find_key'] as $fn) {
    try {
        if ('array_find' === $fn) {
            array_find([1], null);
        } else {
            array_find_key(['a' => 1], null);
        }
        echo $fn, ": uncaught\n";
    } catch (TypeError $e) {
        echo $fn, ': ', get_class($e), "\n";
    }
}
var_export(array_find([1, 2, 3], static fn ($v) => 2 === $v));
echo "\n";
?>
--EXPECT--
array_find: TypeError
array_find_key: TypeError
2
