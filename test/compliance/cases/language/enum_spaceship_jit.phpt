--TEST--
Language: enum <=> JIT execute parity (#4805)
--FILE--
<?php
enum Color: string { case Red = 'red'; case Blue = 'blue'; }
echo Color::Red <=> Color::Blue, "\n";
echo Color::Red <=> 'red', "\n";
echo Color::Red <=> Color::Red, "\n";

enum Unit { case A; case B; }
echo Unit::A <=> Unit::B, "\n";
--EXPECT--
1
1
0
1
