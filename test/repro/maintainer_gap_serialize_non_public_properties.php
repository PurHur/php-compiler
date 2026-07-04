<?php

declare(strict_types=1);

class SerializeVisC15751
{
    public int $x = 1;
    protected int $y = 2;
    private int $z = 3;
}

$c = new SerializeVisC15751();
$wire = serialize($c);
$expectedCount = 3;
$actualCount = (int) substr($wire, strrpos($wire, ':', -strcspn(strrev($wire), ':')) + 1, 1);
if ($actualCount !== $expectedCount) {
    echo "fail: property count {$actualCount}, expected {$expectedCount}\n";
    echo $wire, "\n";
    exit(1);
}
if (!str_contains($wire, "\0*\0y")) {
    echo "fail: protected key missing\n";
    echo $wire, "\n";
    exit(1);
}
if (!str_contains($wire, "\0SerializeVisC15751\0z")) {
    echo "fail: private key missing\n";
    echo $wire, "\n";
    exit(1);
}

$round = unserialize($wire);
if (!$round instanceof SerializeVisC15751) {
    echo "fail: round-trip type\n";
    exit(1);
}
if ($round->x !== 1) {
    echo "fail: public x\n";
    exit(1);
}
$ry = new ReflectionProperty(SerializeVisC15751::class, 'y');
$ry->setAccessible(true);
if ($ry->getValue($round) !== 2) {
    echo "fail: protected y\n";
    exit(1);
}
$rz = new ReflectionProperty(SerializeVisC15751::class, 'z');
$rz->setAccessible(true);
if ($rz->getValue($round) !== 3) {
    echo "fail: private z\n";
    exit(1);
}

echo "ok\n";
