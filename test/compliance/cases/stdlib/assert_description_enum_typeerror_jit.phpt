--TEST--
Stdlib: assert() — enum case description TypeError on failure (JIT, #7171, ext/standard/assert.c)
--FILE--
<?php
ini_set('zend.assertions', '1');
enum E: string { case A = 'x'; }
try {
    assert(false, E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
ini_set('assert.exception', '0');
@assert(false, 'still works');
echo assert(true) ? "1\n" : "0\n";
--EXPECT--
assert(): Argument #2 ($description) must be of type string|Throwable, E given
1
