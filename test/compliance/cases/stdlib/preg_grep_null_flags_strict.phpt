--TEST--
stdlib preg_grep(null $flags) TypeError under strict_types (#31385, ext/pcre/php_pcre.c)
--FILE--
<?php
declare(strict_types=1);
try {
    preg_grep('/a/', ['a', 'b'], null);
    echo "fail\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
// omit-arg still defaults to 0
$got = preg_grep('/a/', ['a', 'b', 'aa']);
echo implode(',', $got), "\n";
--EXPECT--
preg_grep(): Argument #3 ($flags) must be of type int, null given
a,aa
