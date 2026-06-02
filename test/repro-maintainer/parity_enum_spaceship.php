<?php

enum Color: string { case Red = 'red'; case Blue = 'blue'; }
var_dump(Color::Red <=> Color::Blue);
var_dump(Color::Red <=> 'red');
var_dump(Color::Red <=> Color::Red);

enum Size: int { case S = 1; case M = 2; }
var_dump(Size::S <=> Size::M);

enum Unit { case A; case B; case C; }
var_dump(Unit::A <=> Unit::B);
var_dump(Unit::B <=> Unit::A);
var_dump(Unit::A <=> Unit::A);
var_dump(Unit::A == Unit::B);
var_dump(Unit::A === Unit::A);
var_dump(Unit::A == Unit::cases()[0]);
