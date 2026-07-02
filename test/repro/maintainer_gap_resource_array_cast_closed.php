<?php

declare(strict_types=1);

$h = fopen('php://memory', 'r+');
fclose($h);
$closed = (array) $h;
if (1 !== count($closed) || !array_key_exists(0, $closed)) {
    fwrite(STDERR, "expected one-element closed resource array\n");
    exit(1);
}
if ('resource (closed)' !== gettype($closed[0])) {
    fwrite(STDERR, "expected resource (closed) at index 0\n");
    exit(1);
}
if ('Unknown' !== get_resource_type($closed[0])) {
    fwrite(STDERR, "expected Unknown resource type for closed handle\n");
    exit(1);
}
echo "ok\n";
