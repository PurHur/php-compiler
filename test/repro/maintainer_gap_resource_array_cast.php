<?php

declare(strict_types=1);

$h = fopen('php://memory', 'r+');
$open = (array) $h;
if (1 !== count($open) || !array_key_exists(0, $open)) {
    fwrite(STDERR, "open resource cast: expected one-element 0-keyed array\n");
    exit(1);
}

fclose($h);
$closed = (array) $h;
if (1 !== count($closed) || !array_key_exists(0, $closed)) {
    fwrite(STDERR, "closed resource cast: expected one-element 0-keyed array\n");
    exit(1);
}

echo "ok\n";
