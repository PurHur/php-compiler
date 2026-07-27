--TEST--
Language: die(message:) unknown named parameter — Zend only has $status (#23957, Zend/zend_builtin_functions.stub.php)
--ENV--
PHP_COMPILER_PROFILE=8.4
--FILE--
<?php
try {
    die(message: 'bye');
    echo "unreachable\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Unknown named parameter $message
