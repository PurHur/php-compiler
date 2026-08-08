--TEST--
ceil/floor/bindec/hexdec/random_*/password_verify argc → ArgumentCountError (#28476)
--FILE--
<?php
foreach (['ceil', 'floor', 'bindec', 'hexdec', 'random_bytes'] as $fn) {
    try {
        $fn();
        echo "$fn:ok\n";
    } catch (Throwable $e) {
        echo get_class($e), ':', $e->getMessage(), "\n";
    }
}
try {
    random_int(1);
    echo "random_int:ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    password_verify('a');
    echo "password_verify:ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
ArgumentCountError:ceil() expects exactly 1 argument, 0 given
ArgumentCountError:floor() expects exactly 1 argument, 0 given
ArgumentCountError:bindec() expects exactly 1 argument, 0 given
ArgumentCountError:hexdec() expects exactly 1 argument, 0 given
ArgumentCountError:random_bytes() expects exactly 1 argument, 0 given
ArgumentCountError:random_int() expects exactly 2 arguments, 1 given
ArgumentCountError:password_verify() expects exactly 2 arguments, 1 given
