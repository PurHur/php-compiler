--TEST--
Language: (string) cast on unit enum case — Error (zend_enum.c, #5852)
--FILE--
<?php
enum E { case A; }
try {
    var_dump((string) E::A);
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
echo E::A->name, "\n";
--EXPECT--
Object of class E could not be converted to string
A
