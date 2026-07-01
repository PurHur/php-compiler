<?php

declare(strict_types=1);

// Issue #14619 — serialize() includes nullable null-default properties (ext/standard/var.c).
class Box
{
    public ?string $s = null;
    public string $t = 'x';
}

$expected = 'O:3:"Box":2:{s:1:"s";N;s:1:"t";s:1:"x";}';
$wire = serialize(new Box());
if ($wire !== $expected) {
    echo "fail: wire={$wire}\n";
    exit(1);
}

$round = unserialize($wire);
if (!($round instanceof Box) || !property_exists($round, 's') || $round->s !== null || $round->t !== 'x') {
    echo "fail: roundtrip\n";
    exit(1);
}

echo "ok\n";
