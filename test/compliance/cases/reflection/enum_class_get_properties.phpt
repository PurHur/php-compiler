--TEST--
ReflectionClass::getProperties() on enums — name/value virtual properties (#22030, php_reflection.c)
--FILE--
<?php
declare(strict_types=1);

enum Backed: int
{
    case A = 1;
}

enum Unit
{
    case B;
}

$backed = [];
foreach ((new ReflectionClass(Backed::class))->getProperties() as $p) {
    $backed[] = $p->getName();
}
sort($backed);
echo 'backed=', implode(',', $backed), "\n";

$unit = [];
foreach ((new ReflectionClass(Unit::class))->getProperties() as $p) {
    $unit[] = $p->getName();
}
sort($unit);
echo 'unit=', implode(',', $unit), "\n";
--EXPECT--
backed=name,value
unit=name
