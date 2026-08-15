--TEST--
Stdlib: assert() — enum case description TypeError with zend.assertions=1 (#9550 / #28823 / #31195)
--INI--
zend.assertions=1
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
TypeError: assert(): Argument #2 ($description) must be of type string|Throwable, E given
