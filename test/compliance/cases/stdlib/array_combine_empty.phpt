--TEST--
array_combine(): empty keys and values return [] (ext/standard/array.c #4523)
--FILE--
<?php
var_export(array_combine([], []));
echo "\n";
try {
    array_combine(['a'], []);
    echo "no throw\n";
} catch (ValueError $e) {
    echo get_class($e), "\n";
}
try {
    array_combine([], ['x']);
    echo "no throw\n";
} catch (ValueError $e) {
    echo get_class($e), "\n";
}
--EXPECT--
array (
)
ValueError
ValueError
