<?php

declare(strict_types=1);

use Random\Engine\Mt19937;
use Random\Randomizer;

$r = new Randomizer(new Mt19937(42));
try {
    $payload = serialize($r);
    $r2 = unserialize($payload);
    $a = $r->getInt(1, 100);
    $b = $r2->getInt(1, 100);
    echo ($a === $b && is_string($payload)) ? "ok\n" : "fail a=$a b=$b\n";
} catch (Throwable $e) {
    echo 'fail: ' . get_class($e) . ': ' . $e->getMessage() . "\n";
    exit(1);
}
