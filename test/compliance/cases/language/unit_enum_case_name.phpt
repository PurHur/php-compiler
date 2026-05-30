--TEST--
Language: unit enum case singleton and name property (#3404)
--FILE--
<?php
enum Color {
    case Red;
    case Blue;
}
$a = Color::Red;
$b = Color::Red;
echo ($a === $b) ? '1' : '0';
echo "\n";
echo $a->name;
echo "\n";
echo Color::Blue->name;
--EXPECT--
1
Red
Blue
