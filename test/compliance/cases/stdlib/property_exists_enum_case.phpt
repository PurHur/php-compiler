--TEST--
Stdlib: property_exists() on enum cases — name/value magic properties (#5612)
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

echo property_exists($backed, 'name') ? '1' : '0';
echo property_exists($backed, 'value') ? '1' : '0';
echo property_exists($backed, 'cases') ? '1' : '0';
echo property_exists($unit, 'name') ? '1' : '0';
echo property_exists($unit, 'value') ? '1' : '0';
echo property_exists($unit, 'cases') ? '1' : '0';
echo "\n";
--EXPECT--
110100
