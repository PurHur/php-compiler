--TEST--
Language: array dim fetch on null/bool — TypeError Cannot use [] (#4713, zend_operators.c)
--FILE--
<?php
try {
    $x = null;
    echo $x[0];
} catch (TypeError $e) {
    echo 'read_null: TypeError: ', $e->getMessage(), "\n";
}
try {
    $y = null;
    $y['k'] = 1;
} catch (TypeError $e) {
    echo 'write_null: TypeError: ', $e->getMessage(), "\n";
}
try {
    $x = false;
    echo $x[0];
} catch (TypeError $e) {
    echo 'read_false: TypeError: ', $e->getMessage(), "\n";
}
try {
    $y = true;
    $y['k'] = 1;
} catch (TypeError $e) {
    echo 'write_true: TypeError: ', $e->getMessage(), "\n";
}
--EXPECT--
read_null: TypeError: Cannot use [] on null
write_null: TypeError: Cannot use [] on null
read_false: TypeError: Cannot use [] on bool
write_true: TypeError: Cannot use [] on bool
