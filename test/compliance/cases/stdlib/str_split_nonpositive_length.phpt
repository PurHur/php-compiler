--TEST--
str_split(): non-positive $length throws ValueError (#3749)
--FILE--
<?php
try {
    str_split('', 0);
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ValueError
str_split(): Argument #2 ($length) must be greater than 0
