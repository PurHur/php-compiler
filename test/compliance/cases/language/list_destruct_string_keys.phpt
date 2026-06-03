--TEST--
list() numeric destruct on string-key array warns per slot (Zend VM parity, #4841)
--FILE--
<?php
try {
    list($a, $b) = ['x' => 1, 'y' => 2];
    echo "a=$a b=$b\n";
} catch (TypeError $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
a= b=
