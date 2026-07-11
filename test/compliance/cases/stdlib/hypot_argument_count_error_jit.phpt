--TEST--
stdlib hypot() JIT wrong arity throws ArgumentCountError (#12260)
--FILE--
<?php
declare(strict_types=1);

try {
    hypot(3, 4, 5);
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
ArgumentCountError: hypot() expects exactly 2 arguments, 3 given
