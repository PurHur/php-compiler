--TEST--
Stdlib: method_exists() on enum cases — enum static methods cases/from (#5702)
--FILE--
<?php
enum Backed: int
{
    case A = 1;
}

enum Unit
{
    case B;
}

$backed = Backed::A;
$unit = Unit::B;

echo method_exists($backed, 'cases') ? '1' : '0';
echo method_exists($backed, 'from') ? '1' : '0';
echo method_exists($backed, 'tryFrom') ? '1' : '0';
echo method_exists($unit, 'cases') ? '1' : '0';
echo method_exists($unit, 'from') ? '1' : '0';
echo "\n";
--EXPECT--
11110
