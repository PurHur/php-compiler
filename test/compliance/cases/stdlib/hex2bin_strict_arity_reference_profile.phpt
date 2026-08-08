--TEST--
stdlib hex2bin() — second argument rejected on all profiles (#27763, ext/standard/string.c)
--FILE--
<?php
try {
    hex2bin('ab', true);
    echo "fail: accepted second argument\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
hex2bin() expects exactly 1 argument, 2 given
