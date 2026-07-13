<?php

declare(strict_types=1);

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
$result = proc_open(null, [], $pipes);
if (null !== $result) {
    fwrite(STDERR, 'proc_open(null): expected NULL, got '.var_export($result, true)."\n");
    exit(1);
}
echo "proc_open(null): ok\n";
