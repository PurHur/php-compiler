<?php

foreach (['shell_exec', 'system', 'passthru'] as $fn) {
    try {
        $fn(null);
        fwrite(STDERR, "$fn(null): expected ValueError\n");
        exit(1);
    } catch (ValueError $e) {
        echo "$fn(null): ", $e->getMessage(), "\n";
    } catch (TypeError) {
        fwrite(STDERR, "$fn(null): unexpected TypeError\n");
        exit(1);
    }
}

$pipes = [];
$result = proc_open(null, [], $pipes);
echo 'proc_open(null): ', var_export($result, true), "\n";
