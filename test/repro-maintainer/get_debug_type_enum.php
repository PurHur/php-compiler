<?php
declare(strict_types=1);

enum Color: string { case Red = 'r'; }
enum Size { case S; }

echo get_debug_type(Color::Red), "\n";
echo get_debug_type(Size::S), "\n";
