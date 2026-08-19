--TEST--
preg_grep() argc → ArgumentCountError (#28476 sibling)
--FILE--
<?php
try {
    preg_grep();
    echo "zero:ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    preg_grep('/a/', [], 0, 1);
    echo "four:ok\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
--EXPECT--
ArgumentCountError:preg_grep() expects at least 2 arguments, 0 given
ArgumentCountError:preg_grep() expects at most 3 arguments, 4 given
