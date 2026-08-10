<?php

declare(strict_types=1);

try {
    date_timestamp_get(null);
    echo "fail:date_timestamp_get\n";
} catch (TypeError $e) {
    $msg = $e->getMessage();
    if (str_contains($msg, 'Argument #1 ($object): Argument #1')) {
        echo 'fail:msg:', $msg, "\n";
    } else {
        echo 'ok:date_timestamp_get:', $msg, "\n";
    }
}
