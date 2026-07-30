<?php

foreach (['exec', 'shell_exec', 'system', 'passthru', 'popen'] as $fn) {
    try {
        if ('popen' === $fn) {
            $h = $fn(null, 'r');
            if (is_resource($h)) {
                pclose($h);
            }
            echo "$fn(null): opened\n";
            continue;
        }
        $fn(null);
    } catch (ValueError) {
        echo "$fn(null): ok\n";
        continue;
    } catch (TypeError) {
        fwrite(STDERR, "$fn(null): got TypeError, expected ValueError\n");
        exit(1);
    }
    fwrite(STDERR, "$fn(null): expected ValueError\n");
    exit(1);
}

$pipes = [];
$result = proc_open(null, [], $pipes);
echo 'proc_open(null): '.(is_resource($result) ? 'opened' : 'fail')."\n";
if (is_resource($result)) {
    @proc_terminate($result);
}
