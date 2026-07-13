<?php

declare(strict_types=1);

try {
    proc_get_status();
    echo "no_throw\n";
    exit(1);
} catch (\ArgumentCountError $e) {
    $msg = $e->getMessage();
    if (str_contains($msg, 'expects exactly 1 argument') && str_contains($msg, '0 given')) {
        echo "ok:{$msg}\n";
        exit(0);
    }
    echo "wrong_msg:{$msg}\n";
    exit(1);
} catch (\Throwable $e) {
    echo 'got:'.get_class($e).':'.$e->getMessage()."\n";
    exit(1);
}
