--TEST--
stdlib array_fill_keys() inline [new stdClass()] keys (#10849, ext/standard/array.c)
--FILE--
<?php
try {
    array_fill_keys([new stdClass()], 1);
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
$o = new stdClass();
try {
    array_fill_keys([$o], 1);
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Object of class stdClass could not be converted to string
Object of class stdClass could not be converted to string
