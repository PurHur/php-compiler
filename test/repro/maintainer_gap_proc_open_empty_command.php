<?php

declare(strict_types=1);

$pipes = [];
try {
    proc_open([], [], $pipes);
    fwrite(STDERR, "proc_open: expected ValueError\n");
    exit(1);
} catch (ValueError) {
    echo "proc_open: ok\n";
}
