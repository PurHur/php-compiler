<?php
// AOT compile-only: get_debug_type() on enum cases (#4955).
enum Color: string { case Red = 'r'; }
enum U { case A; }
echo get_debug_type(Color::Red), "\n";
echo get_debug_type(U::A), "\n";
