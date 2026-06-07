--TEST--
Stdlib: assert() — enum case description TypeError on failure (#7171, ext/standard/assert.c, php-src-strict)
--FILE--
<?php
enum E: string { case A = 'x'; }
try {
    assert(false, E::A);
    echo "uncaught\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
@assert(false, 'still works');
echo assert(true) ? "1\n" : "0\n";
--EXPECT--
assert(): Argument #2 ($description) must be of type string|Throwable, E given
1
