<?php
enum Color: string { case Red = 'r'; case Green = 'g'; }
enum E { case A; case B; }
echo get_debug_type(Color::Red), "\n";
echo get_debug_type(Color::Green), "\n";
echo get_debug_type(E::A), "\n";
echo get_debug_type(E::B), "\n";
$c = Color::Red;
echo get_debug_type($c), "\n";
