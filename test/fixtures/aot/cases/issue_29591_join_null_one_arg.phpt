--TEST--
AOT: join(null) dual-arg TypeError on PROFILE=8.4 (#29591)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// Separate AOT unit from implode(null); no post-throw statements in try.
error_reporting(E_ALL & ~E_DEPRECATED);
try {
    join(null);
} catch (TypeError $t) {
    echo $t->getMessage(), "\n";
}
--EXPECT--
join(): If argument #1 ($separator) is of type string, argument #2 ($array) must be of type array, null given
