--TEST--
stdlib parse_str() one-arg inside function throws ArgumentCountError (#4034)
--FILE--
<?php
function t(): void {
    try {
        parse_str('a=1&b=2');
        echo "no throw\n";
    } catch (ArgumentCountError $e) {
        echo $e->getMessage(), "\n";
    }
}
t();

parse_str('route=home&page=3');
echo (isset($route) ? 'y' : 'n'), ':', $route ?? '', ':', (isset($page) ? 'y' : 'n'), ':', $page ?? '', "\n";
--EXPECT--
parse_str() expects exactly 2 arguments, 1 given
y:home:y:3
