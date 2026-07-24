--TEST--
Language: PHP 8.3+ arbitrary static init from parameter (#22923)
--ENV--
PHP_COMPILER_PROFILE=8.3
--FILE--
<?php
function f($x) {
    static $a = $x;
    return $a;
}
echo f(1), ",", f(2), "\n";
--EXPECT--
1,1
