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
$result = proc_open(null, [], $pipes);
echo 'proc_open(null): '.(is_resource($result) ? 'opened' : 'fail')."\n";
if (is_resource($result)) {
    @proc_terminate($result);
}
