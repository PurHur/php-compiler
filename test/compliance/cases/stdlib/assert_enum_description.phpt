--TEST--
Stdlib: assert() — enum case description with default zend.assertions (#9550, ext/standard/assert.c)
--FILE--
<?php
enum E: string { case A = 'x'; }
try {
    assert(false, E::A);
    echo "no TypeError\n";
} catch (TypeError $e) {
    echo 'TypeError: ', $e->getMessage(), "\n";
} catch (Throwable $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
no TypeError
