<?php
declare(strict_types=1);

enum E6: int
{
    case A = 1;
}

enum UnitOnly
{
    case B;
}

$props = (new ReflectionClass(E6::class))->getProperties();
echo 'backed_count=', count($props), "\n";
$names = [];
foreach ($props as $p) {
    $names[] = $p->getName();
}
sort($names);
echo 'backed_names=', implode(',', $names), "\n";

$unitProps = (new ReflectionClass(UnitOnly::class))->getProperties();
echo 'unit_count=', count($unitProps), "\n";
$unitNames = [];
foreach ($unitProps as $p) {
    $unitNames[] = $p->getName();
}
sort($unitNames);
echo 'unit_names=', implode(',', $unitNames), "\n";
