--TEST--
Language: never-returning function must TypeError on implicit return (issue #9240)
--FILE--
<?php
function g(): never {
    echo "bad\n";
}
try {
    g();
    echo "continued\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
bad
TypeError: g(): never-returning function must not implicitly return
