<?php

declare(strict_types=1);

$lines = [
    sprintf('%F', NAN),
    sprintf('%G', NAN),
    sprintf('%E', NAN),
    sprintf('%f', NAN),
    sprintf('%F', INF),
];

$expected = ['NaN', 'NaN', 'NaN', 'NaN', 'INF'];
foreach ($lines as $i => $line) {
    if ($expected[$i] !== $line) {
        fwrite(STDERR, "line $i: expected {$expected[$i]}, got $line\n");
        exit(1);
    }
}

echo "ok\n";
