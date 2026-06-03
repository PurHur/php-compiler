--TEST--
Language: echo backed enum case throws Error (zend_enum.c, #4891)
--FILE--
<?php
enum E: string { case A = 'a'; }
try {
    echo E::A;
    echo "no error\n";
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
Object of class E could not be converted to string
