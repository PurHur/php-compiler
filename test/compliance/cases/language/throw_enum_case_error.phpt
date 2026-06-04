--TEST--
Language: throw enum case raises Error not TypeError (#5727, Zend/zend_exceptions.c)
--FILE--
<?php
enum E: int { case A = 1; }
enum U { case B; }

try {
    throw E::A;
} catch (TypeError $e) {
    echo 'backed: TypeError: ', $e->getMessage(), "\n";
} catch (Error $e) {
    echo 'backed: Error: ', $e->getMessage(), "\n";
}

try {
    throw U::B;
} catch (TypeError $e) {
    echo 'unit: TypeError: ', $e->getMessage(), "\n";
} catch (Error $e) {
    echo 'unit: Error: ', $e->getMessage(), "\n";
}
--EXPECT--
backed: Error: Cannot throw objects that do not implement Throwable
unit: Error: Cannot throw objects that do not implement Throwable
