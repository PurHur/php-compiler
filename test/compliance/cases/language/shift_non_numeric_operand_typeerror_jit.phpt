--TEST--
Non-numeric string/object shift throws Zend TypeError JIT (#30138, zend_operators.c)
--FILE--
<?php
try {
    "a" << 1;
} catch (TypeError $e) {
    echo 'string <<: TypeError:', $e->getMessage(), "\n";
}
try {
    "a" >> 1;
} catch (TypeError $e) {
    echo 'string >>: TypeError:', $e->getMessage(), "\n";
}
try {
    (new stdClass) << 1;
} catch (TypeError $e) {
    echo 'object <<: TypeError:', $e->getMessage(), "\n";
}
echo '1 << 1 = ', 1 << 1, "\n";
echo 'null << 1 = ', null << 1, "\n";
echo 'false << 1 = ', false << 1, "\n";
?>
--EXPECT--
string <<: TypeError:Unsupported operand types: string << int
string >>: TypeError:Unsupported operand types: string >> int
object <<: TypeError:Unsupported operand types: stdClass << int
1 << 1 = 2
null << 1 = 0
false << 1 = 0
