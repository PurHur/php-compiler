--TEST--
Language: throw enum case raises Error not TypeError (JIT, #5727)
--FILE--
<?php
enum E: int { case A = 1; }

try {
    throw E::A;
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
} catch (Error $e) {
    echo 'Error: ', $e->getMessage(), "\n";
}
--EXPECT--
Error: Cannot throw objects that do not implement Throwable
