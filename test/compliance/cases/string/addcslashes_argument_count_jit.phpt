--TEST--
string addcslashes() JIT wrong argc — ArgumentCountError (#21756, ext/standard/string.c)
--FILE--
<?php
declare(strict_types=1);

try {
    addcslashes('only');
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
?>
--EXPECT--
ArgumentCountError: addcslashes() expects exactly 2 arguments, 1 given
