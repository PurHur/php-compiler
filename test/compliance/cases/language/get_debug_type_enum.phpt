--TEST--
Language: get_debug_type() on enum case values — enum class name (#3454)
--FILE--
<?php
enum Color: string {
    case Red = 'r';
}
enum E {
    case A;
}
echo get_debug_type(Color::Red), "\n";
echo get_debug_type(E::A), "\n";
--EXPECT--
Color
E
