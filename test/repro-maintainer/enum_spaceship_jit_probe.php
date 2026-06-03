<?php

enum Color: string { case Red = 'red'; case Blue = 'blue'; }
echo (Color::Red <=> Color::Blue), "\n";
echo (Color::Red <=> 'red'), "\n";

enum Unit { case A; case B; }
echo (Unit::A <=> Unit::B), "\n";
