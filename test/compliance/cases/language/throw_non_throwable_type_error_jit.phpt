--TEST--
Language: throw non-Throwable raises Error (JIT, #5223, #5727, Zend/zend_exceptions.c)
--FILE--
<?php
try {
    throw new stdClass();
} catch (TypeError $e) {
    echo 'object: TypeError: ', $e->getMessage(), "\n";
} catch (Error $e) {
    echo 'object: Error: ', $e->getMessage(), "\n";
}
--EXPECT--
object: Error: Cannot throw objects that do not implement Throwable
