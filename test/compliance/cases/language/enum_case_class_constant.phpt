--TEST--
Language: enum case ::class via var_dump returns enum FQCN string (zend_enum.c, #16030)
--FILE--
<?php
enum E: string { case A = 'a'; }
var_dump(E::A::class);
var_dump(E::A::class === E::class);
enum U { case B; }
var_dump(U::B::class);
--EXPECT--
string(1) "E"
bool(true)
string(1) "U"
