--TEST--
Language: exit() rejects a second argument — ArgumentCountError (#23957, Zend/zend_builtin_functions.c)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    exit(1, "bye");
    echo "unreachable\n";
} catch (ArgumentCountError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
exit() expects at most 1 argument, 2 given
