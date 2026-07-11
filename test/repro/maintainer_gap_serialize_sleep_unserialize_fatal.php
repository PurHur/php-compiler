<?php

declare(strict_types=1);

final class SleepyBox
{
    public int $x = 7;

    public function __sleep(): array
    {
        return ['x'];
    }
}

$obj = new SleepyBox();
$s = serialize($obj);
$restored = unserialize($s);

if (!$restored instanceof SleepyBox) {
    echo "bad_class\n";
    exit(1);
}

echo "ok x=", $restored->x, "\n";

