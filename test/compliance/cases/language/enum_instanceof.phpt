--TEST--
Language: instanceof on enum case values (#3550)
--FILE--
<?php
enum E: string
{
    case A = 'a';
}

var_export(E::A instanceof E);
echo "\n";
var_export(E::A instanceof UnitEnum);
echo "\n";
var_export(E::A instanceof BackedEnum);
echo "\n";

enum Other: string
{
    case X = 'x';
}
var_export(E::A instanceof Other);
echo "\n";
--EXPECT--
true
true
true
false
