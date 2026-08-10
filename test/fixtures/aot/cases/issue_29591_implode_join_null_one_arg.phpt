--TEST--
AOT: implode(null) dual-arg TypeError on PROFILE=8.4 (#29591)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
// No statements after the throwing call in try — ExceptionBridge terminates the block.
error_reporting(E_ALL & ~E_DEPRECATED);
try {
    implode(null);
} catch (TypeError $t) {
    echo $t->getMessage(), "\n";
}
--EXPECT--
implode(): If argument #1 ($separator) is of type string, argument #2 ($array) must be of type array, null given
