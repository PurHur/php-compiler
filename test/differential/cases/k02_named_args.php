<?php
// Named arguments (PHP 8.0), including out-of-order and skipping a defaulted param.
function tag(string $body, string $name = 'p', string $cls = 'x'): string {
    return $name . $cls . $body;
}
echo tag('A', cls: 'B'), ' ', tag(name: 'Z', body: 'C'), "\n";
