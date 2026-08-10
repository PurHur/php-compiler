--TEST--
Language: arrow fn(): never => expr TypeErrors at call time, not compile Fatal (#30020)
--FILE--
<?php
try {
    $f = fn(): never => 1;
    $f();
    echo "ok\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
try {
    $g = fn(): never => null;
    $g();
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: {closure}(): never-returning function must not implicitly return
TypeError: {closure}(): never-returning function must not implicitly return
