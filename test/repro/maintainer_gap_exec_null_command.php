<?php

foreach (['exec', 'shell_exec', 'system', 'passthru', 'popen'] as $fn) {
    try {
        if ('popen' === $fn) {
            $fn(null, 'r');
        } else {
            $fn(null);
        }
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
    fwrite(STDERR, 'proc_open(null): expected NULL'."\n");
    exit(1);
}
echo "proc_open(null): ok\n";
