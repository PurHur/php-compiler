--TEST--
chunk_split(): invalid length throws ValueError (#3763)
--FILE--
<?php
try {
    chunk_split('abc', 0);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ValueError
chunk_split(): Argument #2 ($length) must be greater than 0
