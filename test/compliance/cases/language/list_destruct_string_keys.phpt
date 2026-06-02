--TEST--
list() destructuring rejects arrays with string keys (Zend VM parity, #4298)
--FILE--
<?php
try {
    list($a, $b) = ['x' => 1, 'y' => 2];
    echo "no error\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Cannot unpack array with string keys
