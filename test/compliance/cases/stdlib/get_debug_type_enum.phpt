--TEST--
stdlib get_debug_type() on backed + unit enum cases (#4260, ext/standard/type.c)
--FILE--
<?php
declare(strict_types=1);

enum Color: string { case Red = 'r'; }
enum Size { case S; }

echo get_debug_type(Color::Red), "\n";
echo get_debug_type(Size::S), "\n";
--EXPECT--
Color
Size
