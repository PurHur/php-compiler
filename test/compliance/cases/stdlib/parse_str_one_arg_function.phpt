--TEST--
stdlib parse_str() one-arg populates function scope locals (PHP 8+, #12533)
--FILE--
<?php
function t(): void {
    parse_str('a=1&b=2');
    echo $a, "\n";
    echo $b, "\n";
}
t();
--EXPECT--
1
2

