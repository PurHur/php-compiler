--TEST--
Language: string concat with backed enum case — Error (zend_operators.c, #5508, #5806)
--FILE--
<?php
enum E: string { case A = 'a'; }
try {
    echo 'x' . E::A;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
echo E::A->name, '|', E::A->value, "\n";
--EXPECT--
Object of class E could not be converted to string
A|a
