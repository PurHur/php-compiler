--TEST--
call argument spread rejects non-array operand (Zend VM parity, #4322)
--FILE--
<?php
function id($x) {
    return $x;
}
try {
    id(...42);
    echo "no error\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Only arrays and Traversables can be unpacked
