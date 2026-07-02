<?php

declare(strict_types=1);

$h = fopen('php://memory', 'r+');
$open = (array) $h;
if (1 !== count($open) || !array_key_exists(0, $open) || !is_resource($open[0])) {
    fwrite(STDERR, "open resource cast mismatch\n");
    exit(1);
}
fclose($h);
$closed = (array) $h;
if (1 !== count($closed) || !array_key_exists(0, $closed) || 'resource (closed)' !== gettype($closed[0])) {
    fwrite(STDERR, "closed resource cast mismatch\n");
    exit(1);
}
echo "ok\n";
