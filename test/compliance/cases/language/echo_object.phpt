--TEST--
language: echo object without __toString throws Error (issue #3564, Zend parity)
--FILE--
<?php
class C {}
try {
    echo new C();
    echo "no error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Object of class C could not be converted to string
