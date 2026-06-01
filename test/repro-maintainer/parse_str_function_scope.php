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
