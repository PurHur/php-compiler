--TEST--
list() destructuring from string — TypeError (#7461, supersedes #4308 silent null)
--FILE--
<?php
try {
    [$a] = 'ab';
    echo "no-exception\n";
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
}
try {
    [$b, $c] = 'xy';
    echo "no-exception\n";
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: Cannot use string as array
TypeError: Cannot use string as array
