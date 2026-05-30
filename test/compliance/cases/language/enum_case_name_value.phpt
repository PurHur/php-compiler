--TEST--
Language: enum case ->name / ->value on backed enums (#3420)
--FILE--
<?php
enum E: string { case A = 'a'; case B = 'bb'; }
echo E::A->name, '|', E::A->value, "\n";
echo E::B->name, '|', E::B->value, "\n";
echo E::A;
echo "\n";
--EXPECT--
A|a
B|bb
a
