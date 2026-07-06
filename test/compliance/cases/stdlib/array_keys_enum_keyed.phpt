--TEST--
Stdlib: enum case array keys rejected at write (re-#9512, #16986, zend_hash.c)
--FILE--
<?php
enum E: int { case A = 1; case B = 2; }
try {
    $a = [];
    $a[E::A] = 'x';
    echo "accepted\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: Illegal offset type
