<?php

declare(strict_types=1);

$fp = fopen('php://memory', 'r+');
if (false === $fp) {
    fwrite(STDERR, "fopen failed\n");
    exit(1);
}

try {
    stream_set_blocking($fp, null);
    echo "fail\n";
} catch (TypeError $e) {
    echo "ok\n";
}
