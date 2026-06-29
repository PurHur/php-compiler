<?php

declare(strict_types=1);

$fh = fopen('php://memory', 'r+');
if (false === $fh) {
    fwrite(STDERR, "fail: could not open memory stream\n");
    exit(1);
}
fwrite($fh, 'abcdef');
rewind($fh);

try {
    fread($fh, 2.9);
    fwrite(STDERR, "fail: expected TypeError for float length under strict_types\n");
    exit(1);
} catch (TypeError $e) {
    if (!str_contains($e->getMessage(), 'fread(): Argument #2 ($length) must be of type int, float given')) {
        fwrite(STDERR, 'unexpected message: '.$e->getMessage()."\n");
        exit(1);
    }
}

fclose($fh);

echo "ok\n";
