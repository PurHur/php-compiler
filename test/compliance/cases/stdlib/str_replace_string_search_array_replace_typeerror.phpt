--TEST--
stdlib str_replace()/str_ireplace() string $search + array $replace TypeError (#22827, re-#11056)
--FILE--
<?php
echo str_replace(['a', 'b'], ['A', 'B'], 'ab'), "\n";
try {
    var_export(str_replace('a', ['x', 'y'], 'a'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
try {
    var_export(str_ireplace('A', ['x', 'y'], 'a'));
    echo "\n";
} catch (Throwable $e) {
    echo get_class($e), ':', $e->getMessage(), "\n";
}
// Single-element array $search + array $replace remains legal (php-src).
echo str_replace(['a'], ['x', 'y'], 'a'), "\n";
--EXPECT--
AB
TypeError:str_replace(): Argument #2 ($replace) must be of type string when argument #1 ($search) is a string
TypeError:str_ireplace(): Argument #2 ($replace) must be of type string when argument #1 ($search) is a string
x
