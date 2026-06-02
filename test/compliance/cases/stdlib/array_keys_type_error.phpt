--TEST--
stdlib array_keys() / array_values() TypeError on non-array (#4138)
--FILE--
<?php
$o = new stdClass();
try {
    array_keys($o);
} catch (Throwable $e) {
    echo "array_keys: ", get_class($e), "\n";
}
try {
    array_values($o);
} catch (Throwable $e) {
    echo "array_values: ", get_class($e), "\n";
}
--EXPECT--
array_keys: TypeError
array_values: TypeError
