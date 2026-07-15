--TEST--
stdlib random_bytes(null) — ValueError on 8.4 forward profile (#19054, ext/random/random.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
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
