--TEST--
string substr_compare() JIT wrong argc — ArgumentCountError (#21769, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

try {
    substr_compare(null, 'a');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
ArgumentCountError: substr_compare() expects at least 3 arguments, 2 given
