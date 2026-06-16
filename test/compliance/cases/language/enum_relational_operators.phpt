--TEST--
Language: backed enum relational operators use object order not backing scalars (#8897, zend_enum.c)
--FILE--
<?php
declare(strict_types=1);
enum E: int { case A = 1; case B = 2; }
enum S: string { case X = 'a'; case Y = 'b'; }
var_dump(E::A < E::B);
var_dump(E::A <=> E::B);
var_dump(E::B <=> E::A);
var_dump(S::X < S::Y);
var_dump(S::X <=> S::Y);
?>
--EXPECT--
bool(false)
int(1)
int(1)
bool(false)
int(1)
