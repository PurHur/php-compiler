--TEST--
Language: $a[E::A] = 1 throws TypeError (re-#9512, #16986, zend_hash.c)
--FILE--
<?php
enum E: int { case A = 1; }
try {
    $a = [];
    $a[E::A] = 1;
    echo "accepted\n";
} catch (TypeError $e) {
    echo get_class($e), ': ', $e->getMessage(), "\n";
}
--EXPECT--
TypeError: Illegal offset type
