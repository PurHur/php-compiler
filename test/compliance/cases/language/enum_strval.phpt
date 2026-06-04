--TEST--
Language: strval() on backed enum case — Error (zend_enum.c, #5508, #5615)
--FILE--
<?php
enum E: string { case A = 'a'; }
enum I: int { case A = 1; }
foreach ([E::A, I::A] as $case) {
    try {
        strval($case);
        echo "fail\n";
    } catch (Error $e) {
        echo $e->getMessage(), "\n";
    }
}
echo E::A->name, '|', E::A->value, "\n";
echo I::A->name, '|', I::A->value, "\n";
--EXPECT--
Object of class E could not be converted to string
Object of class I could not be converted to string
A|a
A|1
