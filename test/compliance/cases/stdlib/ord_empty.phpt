--TEST--
ord(): empty string throws ValueError (#4324)
--FILE--
<?php
try {
    ord('');
} catch (Throwable $e) {
    echo get_class($e), "\n";
    echo $e->getMessage(), "\n";
}
--EXPECT--
ValueError
ord(): Argument #1 ($string) must not be empty
