--TEST--
JIT: spl iterator_to_array(null) always TypeError — typed Traversable|array (#21893, ext/spl)
--JIT--
--FILE--
<?php
try {
    var_export(iterator_to_array(null));
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
?>
--EXPECT--
iterator_to_array(): Argument #1 ($iterator) must be of type Traversable|array, null given
