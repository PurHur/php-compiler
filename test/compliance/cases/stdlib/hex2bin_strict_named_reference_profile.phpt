--TEST--
stdlib hex2bin() — strict: unknown named parameter (#27763 / #16177, ext/standard/string.c)
--FILE--
<?php
try {
    hex2bin('zz', strict: true);
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Unknown named parameter $strict
