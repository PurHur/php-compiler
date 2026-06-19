--TEST--
Language: backed/unit enum <=> parity (#4554, Zend/zend_enum.c)
--FILE--
<?php
enum Color: string { case Red = 'red'; case Blue = 'blue'; }
var_dump(Color::Red <=> Color::Blue);
var_dump(Color::Red <=> 'red');
var_dump(Color::Red <=> Color::Red);

enum Size: int { case S = 1; case M = 2; }
var_dump(Size::S <=> Size::M);
var_dump(Size::S <=> 1);
var_dump(Size::S <=> 'x');

enum Unit { case A; case B; case C; }
var_dump(Unit::A <=> Unit::B);
--EXPECT--
int(1)
int(1)
int(0)
int(1)
int(1)
int(1)
int(1)
