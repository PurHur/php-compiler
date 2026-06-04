--TEST--
Language: strval() on backed enum case — Error (zend_enum.c, #5508)
--FILE--
<?php
enum E: string { case A = 'a'; }
try {
    echo strval(E::A);
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
echo E::A->name, '|', E::A->value, "\n";
--EXPECT--
Object of class E could not be converted to string
A|a
