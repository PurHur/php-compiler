--TEST--
Error hierarchy: TypeError / ValueError catchable on VM (#3371)
--FILE--
<?php
try {
    throw new TypeError('bad type');
} catch (Error $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}

try {
    throw new ValueError('bad value');
} catch (TypeError $e) {
    echo "wrong\n";
} catch (Error $e) {
    echo get_class($e), "\n";
}

try {
    throw new ArgumentCountError('too few');
} catch (Throwable $e) {
    echo get_class($e), "\n";
}
?>
--EXPECT--
TypeError
bad type
ValueError
ArgumentCountError
