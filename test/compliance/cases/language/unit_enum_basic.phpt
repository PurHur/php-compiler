--TEST--
Language: unit enum declaration and case fetch (#3404)
--FILE--
<?php
enum E {
    case A;
    case B;
}
echo E::A->name;
echo "\n";
echo E::B->name;
echo "\n";
echo enum_exists('E') ? '1' : '0';
--EXPECT--
A
B
1
