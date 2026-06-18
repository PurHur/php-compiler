--TEST--
Language: throw non-Throwable raises Error (JIT, #5223, #5727, Zend/zend_exceptions.c)
--FILE--
<?php
try {
    throw 'x';
} catch (TypeError $e) {
    echo 'string: TypeError: ', $e->getMessage(), "\n";
} catch (Error $e) {
    echo 'string: Error: ', $e->getMessage(), "\n";
}

try {
    throw 1;
} catch (TypeError $e) {
    echo 'int: TypeError: ', $e->getMessage(), "\n";
} catch (Error $e) {
    echo 'int: Error: ', $e->getMessage(), "\n";
}

try {
    throw new stdClass();
} catch (TypeError $e) {
    echo 'object: TypeError: ', $e->getMessage(), "\n";
} catch (Error $e) {
    echo 'object: Error: ', $e->getMessage(), "\n";
}
--EXPECT--
string: Error: Can only throw objects
int: Error: Can only throw objects
object: Error: Cannot throw objects that do not implement Throwable
