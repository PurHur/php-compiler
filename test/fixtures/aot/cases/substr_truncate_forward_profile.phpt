--TEST--
AOT: substr() rejects 4th arg on PHP 8.4 forward profile (#27749)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    echo substr('abcdef', 0, 3, true), "\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
echo substr('abcdef', 0, 3), "\n";
?>
--EXPECT--
substr() expects at most 3 arguments, 4 given
abc
