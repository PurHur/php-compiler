--TEST--
Language: list/array destructuring from string — TypeError (#7461, zend_execute.c)
--FILE--
<?php
try {
    list($a, $b) = 'ab';
    echo "no-exception\n";
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
}
try {
    [$x, $y] = 'xy';
    echo "no-exception\n";
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: Cannot use string as array
TypeError: Cannot use string as array
