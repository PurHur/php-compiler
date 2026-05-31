--TEST--
stdlib parse_str() without $result populates caller locals (issue #3708)
--FILE--
<?php
function t(): void {
    parse_str('a=1&b=2');
    echo (isset($a) ? 'y' : 'n'), ':', $a ?? '', ':', (isset($b) ? 'y' : 'n'), ':', $b ?? '', "\n";
}
t();

parse_str('route=home&page=3');
echo (isset($route) ? 'y' : 'n'), ':', $route ?? '', ':', (isset($page) ? 'y' : 'n'), ':', $page ?? '', "\n";
--EXPECT--
y:1:y:2
y:home:y:3
