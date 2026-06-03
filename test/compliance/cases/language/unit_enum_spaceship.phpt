--TEST--
Language: unit enum <=> / == comparison parity (#4700, Zend/zend_enum.c)
--FILE--
<?php
enum Unit { case A; case B; case C; }
var_dump(Unit::A <=> Unit::B);
var_dump(Unit::B <=> Unit::A);
var_dump(Unit::A <=> Unit::A);
var_dump(Unit::A == Unit::B);
var_dump(Unit::A === Unit::A);
var_dump(Unit::A == Unit::cases()[0]);
var_dump(Unit::A === Unit::cases()[0]);

enum Color: string { case Red = 'red'; case Blue = 'blue'; }
var_dump(Color::Red <=> Color::Blue);
var_dump(Color::Red == Color::Blue);
--EXPECT--
int(1)
int(1)
int(0)
bool(false)
bool(true)
bool(true)
bool(true)
int(1)
bool(false)
