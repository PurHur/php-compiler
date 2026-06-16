--TEST--
Language: enum case ->name / ->value on backed enums (#3420)
--FILE--
<?php
enum E: string { case A = 'a'; case B = 'bb'; }
echo E::A->name, '|', E::A->value, "\n";
echo E::B->name, '|', E::B->value, "\n";
var_dump(E::A);
try {
    echo E::A;
} catch (Error $e) {
    echo $e->getMessage(), "\n";
}
--EXPECT--
A|a
B|bb
enum(E::A)
Object of class E could not be converted to string
