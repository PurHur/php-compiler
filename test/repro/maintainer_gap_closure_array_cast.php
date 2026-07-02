<?php

declare(strict_types=1);

$c = static fn (): int => 1;
$cast = (array) $c;
if (1 !== count($cast) || !array_key_exists(0, $cast)) {
    fwrite(STDERR, "expected one-element array with index 0\n");
    exit(1);
}
if (!($cast[0] instanceof Closure)) {
    fwrite(STDERR, 'expected Closure at index 0, got '.get_debug_type($cast[0])."\n");
    exit(1);
}
echo "ok\n";
