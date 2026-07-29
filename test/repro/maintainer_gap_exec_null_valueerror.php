<?php

foreach (['shell_exec', 'system', 'passthru'] as $fn) {
    try {
        $fn(null);
        fwrite(STDERR, "$fn(null): expected ValueError\n");
        exit(1);
    } catch (ValueError) {
        echo "$fn(null): ok\n";
    } catch (TypeError) {
        fwrite(STDERR, "$fn(null): got TypeError, expected ValueError\n");
        exit(1);
    }
}

$pipes = [];
try {
    proc_open(null, [], $pipes);
    fwrite(STDERR, "proc_open(null): expected TypeError\n");
    exit(1);
} catch (TypeError $e) {
    if (!str_contains($e->getMessage(), 'must be of type array|string, null given')) {
        fwrite(STDERR, 'proc_open(null): unexpected message: '.$e->getMessage()."\n");
        exit(1);
    }
    echo "proc_open(null): ok\n";
}
