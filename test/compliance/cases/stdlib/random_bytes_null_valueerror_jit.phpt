--TEST--
stdlib random_bytes(null) JIT — ValueError not TypeError (#19054, ext/random/random.c)
--FILE--
<?php
try {
    random_bytes(null);
} catch (ValueError $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ValueError
random_bytes(): Argument #1 ($length) must be greater than 0
